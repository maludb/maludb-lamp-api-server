<?php
/**
 * POST /mcp  (Model Context Protocol server endpoint — stateless Streamable HTTP)
 *
 * Lets MCP clients (Claude Code, Claude Desktop, hosted agents) use MaluDB as
 * long-term memory with nothing but this URL and a Bearer token:
 *
 *   claude mcp add --transport http maludb https://HOST/mcp \
 *     --header "Authorization: Bearer $TOKEN"
 *
 * Implements MCP spec 2025-06-18 in its simplest conformant shape:
 *   - Single endpoint, POST only (anything else -> 405). Every JSON-RPC request
 *     gets a single application/json response; notifications get HTTP 202.
 *     No sessions (no Mcp-Session-Id), no SSE, no JSON-RPC batches.
 *   - Methods: initialize, ping, tools/list, tools/call (+ notifications/*).
 *   - Auth: the same Bearer token flow as the REST API (require_auth);
 *     tools run as the token's user, so per-user LLM config applies.
 *   - Tool failures are JSON-RPC *successes* with isError:true and the standard
 *     {"error":{code,message}} JSON in the text block, so agents can read the
 *     error code and self-correct. Protocol failures use JSON-RPC error codes.
 *
 * Eight tools: store_memory, search_memory, find_subjects, explore_subject,
 * store_document, get_document, find_skills, get_skill. The pipeline tools call
 * the shared cores in config/memory_core.php; the read tools carry their own
 * literal SQL (copied from the corresponding REST endpoint files — see the
 * repo's SQL-traceability principle, requirements.md §3). The mcp_tools()
 * registry is a cross-server contract ported byte-for-byte from the Python
 * reference server (app/routers/mcp.py); do not edit it independently.
 *
 * Routed by .htaccess: /mcp -> mcp.php. The tests (tests/mcp_protocol_test.php)
 * require this file with MALUDB_MCP_TESTING defined to load the functions
 * without dispatching.
 */

require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/memory_core.php';

/* ---------------------------------------------------------------------------
 * Protocol constants
 * ------------------------------------------------------------------------- */

const MCP_SERVER_VERSION = '0.1.0';
const MCP_PROTOCOL_VERSIONS = ['2025-03-26', '2025-06-18'];
const MCP_DEFAULT_PROTOCOL_VERSION = '2025-06-18';
const MCP_SERVER_INFO = ['name' => 'maludb', 'title' => 'MaluDB Memory', 'version' => MCP_SERVER_VERSION];

// Same encoding flags as json_response() so MCP payloads read like REST payloads.
const MCP_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

/* ---------------------------------------------------------------------------
 * Tool registry — names, schemas, and descriptions are a cross-server contract
 * (ported verbatim from the Python reference server). Plain data only.
 * ------------------------------------------------------------------------- */

function mcp_tools(): array {
    $read_only = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    $write     = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];

    return [
        [
            'name' => 'store_memory',
            'title' => 'Store memory',
            'description' =>
                'Store a fact, event, or observation in MaluDB long-term memory. The server runs'
                . " LLM extraction (with the user's configured extract model) and writes subjects,"
                . ' verbs, and edges into the knowledge graph. Call this whenever the user states'
                . ' something worth remembering. Pass hints for subjects you already know the text'
                . ' is about (use canonical names from find_subjects when possible).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string', 'description' => 'The text to remember.'],
                    'hints' => [
                        'type' => 'array',
                        'description' => 'Known subjects this text is about.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'subject_type' => ['type' => 'string', 'description' => 'e.g. person, project, software'],
                                'subject_name' => ['type' => 'string'],
                            ],
                            'required' => ['subject_type', 'subject_name'],
                        ],
                    ],
                    'namespace' => ['type' => 'string', 'default' => 'default'],
                ],
                'required' => ['text'],
                'additionalProperties' => false,
            ],
            'annotations' => $write,
        ],
        [
            'name' => 'search_memory',
            'title' => 'Search memory',
            'description' =>
                'Semantic vector search over stored memory; returns matching text spans with'
                . ' their source document ids. The search requires a compartment pre-filter:'
                . ' pass subject (canonical name) and/or verb. Call find_subjects first when you'
                . " don't know the canonical subject name.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'What to search for.'],
                    'subject' => ['type' => 'string', 'description' => 'Canonical subject name to search within.'],
                    'verb' => ['type' => 'string', 'description' => 'Canonical verb to search within.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
                    'namespace' => ['type' => 'string', 'default' => 'default'],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            'annotations' => $read_only,
        ],
        [
            'name' => 'find_subjects',
            'title' => 'Find subjects',
            'description' =>
                'List canonical subjects (entities) in the memory graph, optionally filtered by'
                . ' a name/description substring. Call this before search_memory or'
                . " explore_subject when you don't know the exact canonical name.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Substring to match against name or description.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 25],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $read_only,
        ],
        [
            'name' => 'explore_subject',
            'title' => 'Explore subject',
            'description' =>
                'Walk the knowledge graph around one subject: its edges and neighbors (depth 1)'
                . ' or multi-hop reach (depth 2-3). Use after find_subjects to see everything'
                . ' known about an entity.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'subject' => ['type' => 'string', 'description' => 'Canonical subject name or numeric subject id.'],
                    'direction' => ['type' => 'string', 'enum' => ['out', 'in', 'both'], 'default' => 'both'],
                    'verb' => ['type' => 'string', 'description' => 'Only follow edges with this verb.'],
                    'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3, 'default' => 1],
                ],
                'required' => ['subject'],
                'additionalProperties' => false,
            ],
            'annotations' => $read_only,
        ],
        [
            'name' => 'store_document',
            'title' => 'Store document',
            'description' =>
                'Store a full document (meeting notes, transcript, article) in memory. The'
                . " server chunks the text, extracts graph edges with the user's configured"
                . ' model, embeds them, and links the document to the given subjects/projects.'
                . ' Prefer store_memory for short facts and observations.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'text' => ['type' => 'string', 'description' => 'The full document text.'],
                    'source_type' => ['type' => 'string', 'default' => 'document'],
                    'subjects' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'projects' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'namespace' => ['type' => 'string', 'default' => 'default'],
                ],
                'required' => ['title', 'text'],
                'additionalProperties' => false,
            ],
            'annotations' => $write,
        ],
        [
            'name' => 'get_document',
            'title' => 'Get document',
            'description' =>
                "Fetch one stored document's metadata and tags by id. Document ids come from"
                . ' search_memory results, store_memory, or store_document.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'document_id' => ['type' => 'integer'],
                ],
                'required' => ['document_id'],
                'additionalProperties' => false,
            ],
            'annotations' => $read_only,
        ],
        [
            'name' => 'find_skills',
            'title' => 'Find skills',
            'description' =>
                'Discover stored agent skills. Pass subject and/or verb for tag-aware ranked'
                . " discovery (e.g. verb='extract'); otherwise query matches names and"
                . ' descriptions. Call this when the current task might already have a stored'
                . ' skill.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'subject' => ['type' => 'string'],
                    'verb' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 20],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $read_only,
        ],
        [
            'name' => 'get_skill',
            'title' => 'Get skill',
            'description' =>
                'Fetch one agent skill: metadata, the SKILL.md markdown instructions, and a'
                . ' listing of its bundle files (paths and sizes only — fetch full bundles via'
                . ' the REST API). Provide skill_id, or name to get the newest enabled version.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'skill_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $read_only,
        ],
    ];
}

function mcp_tools_by_name(): array {
    static $by_name = null;
    if ($by_name === null) {
        $by_name = [];
        foreach (mcp_tools() as $t) { $by_name[$t['name']] = $t; }
    }
    return $by_name;
}

/* ---------------------------------------------------------------------------
 * JSON-RPC helpers
 * ------------------------------------------------------------------------- */

/** Handler-level parameter problem -> JSON-RPC -32602. */
class McpInvalidParams extends Exception {}

/** A JSON-RPC success envelope. $req_id is echoed back untouched (string/int/null). */
function mcp_rpc_result(mixed $req_id, mixed $result): array {
    return ['jsonrpc' => '2.0', 'id' => $req_id, 'result' => $result];
}

/** A JSON-RPC error envelope (protocol-level failures only). */
function mcp_rpc_error(mixed $req_id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $req_id, 'error' => ['code' => $code, 'message' => $message]];
}

/** Tool success: the payload serialized into one text content block. */
function mcp_text_result(array $payload): array {
    return ['content' => [['type' => 'text', 'text' => json_encode($payload, MCP_JSON_FLAGS)]]];
}

/** Tool failure: a JSON-RPC *success* carrying the standard API error JSON + isError. */
function mcp_error_result(string $code, string $message, ?string $sqlstate = null): array {
    $error = ['code' => $code, 'message' => $message];
    if ($sqlstate !== null && $sqlstate !== '') {
        $error['sqlstate'] = $sqlstate;
    }
    return [
        'content' => [['type' => 'text', 'text' => json_encode(['error' => $error], MCP_JSON_FLAGS)]],
        'isError' => true,
    ];
}

/* ---------------------------------------------------------------------------
 * SQLSTATE classification (canonical table, mirrored from the Python server's
 * app/errors.py). MCP-only: tool-level PDOExceptions become isError results
 * with a specific code; the REST surface keeps handle_uncaught()'s mapping.
 * ------------------------------------------------------------------------- */

// Exact SQLSTATE overrides.
const MCP_SQLSTATE_EXACT = [
    // Integrity constraint violations
    '23505' => [409, 'conflict'],                 // unique_violation
    '23503' => [422, 'validation_failed'],        // foreign_key_violation
    '23502' => [422, 'validation_failed'],        // not_null_violation
    '23514' => [422, 'validation_failed'],        // check_violation
    // Data exceptions
    '22000' => [422, 'validation_failed'],        // data_exception (generic)
    '22023' => [422, 'validation_failed'],        // invalid_parameter_value
    '22P02' => [422, 'validation_failed'],        // invalid_text_representation
    // PL/pgSQL RAISE (custom business-rule errors from facade functions)
    'P0001' => [422, 'validation_failed'],        // raise_exception
    // Access / privilege
    '42501' => [403, 'insufficient_privilege'],   // insufficient_privilege
    // Undefined database objects — almost always a schema/search_path/migration
    // mismatch on the server side.
    '42P01' => [500, 'schema_error'],             // undefined_table
    '42703' => [500, 'schema_error'],             // undefined_column
    '42883' => [500, 'schema_error'],             // undefined_function
    '42P02' => [500, 'schema_error'],             // undefined_parameter
    '3F000' => [500, 'schema_error'],             // invalid_schema_name
    // Transaction concurrency — retryable by the client.
    '40001' => [409, 'serialization_failure'],    // serialization_failure
    '40P01' => [409, 'deadlock_detected'],        // deadlock_detected
    '55P03' => [409, 'lock_not_available'],       // lock_not_available
    // Resource / operator
    '53300' => [503, 'too_many_connections'],     // too_many_connections
    '57014' => [503, 'query_canceled'],           // query_canceled (timeout)
];

// SQLSTATE class (first two chars) → fallback mapping.
const MCP_SQLSTATE_CLASS = [
    '08' => [503, 'database_unavailable'],        // connection exception
    '22' => [422, 'validation_failed'],           // data exception
    '23' => [422, 'constraint_violation'],        // integrity constraint violation
    '40' => [409, 'transaction_conflict'],        // transaction rollback
    '42' => [500, 'query_error'],                 // syntax error / access rule violation
    '53' => [503, 'insufficient_resources'],      // insufficient resources
    '54' => [500, 'program_limit_exceeded'],      // program limit exceeded
    '57' => [503, 'operator_intervention'],       // operator intervention
    '58' => [503, 'system_error'],                // system error (external to PG)
    'XX' => [500, 'internal_database_error'],     // internal error
];

/** Map a PDOException to [http_status, error_code, ?sqlstate] (exact, then class, then 500). */
function mcp_classify_pdo_error(PDOException $e): array {
    $sqlstate = (is_array($e->errorInfo ?? null) && isset($e->errorInfo[0]) && $e->errorInfo[0] !== '')
        ? (string) $e->errorInfo[0]
        : substr((string) $e->getCode(), 0, 5);
    if (!preg_match('/^[0-9A-Z]{5}$/', $sqlstate)) {
        $sqlstate = null;   // PDO codes like 0 or HY000 fragments are not Postgres SQLSTATEs
    }

    if ($sqlstate !== null && isset(MCP_SQLSTATE_EXACT[$sqlstate])) {
        [$status, $code] = MCP_SQLSTATE_EXACT[$sqlstate];
    } elseif ($sqlstate !== null && isset(MCP_SQLSTATE_CLASS[substr($sqlstate, 0, 2)])) {
        [$status, $code] = MCP_SQLSTATE_CLASS[substr($sqlstate, 0, 2)];
    } else {
        $status = 500; $code = 'internal_error';
    }
    return [$status, $code, $sqlstate];
}

/* ---------------------------------------------------------------------------
 * Transport-level checks
 * ------------------------------------------------------------------------- */

/** DNS-rebinding guard: a present Origin must be localhost or our own host (else 403). */
function mcp_check_origin(): void {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return;
    }
    $origin_host = strtolower(trim((string) (parse_url($origin, PHP_URL_HOST) ?: ''), '[]'));
    $own_host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $pos = strrpos($own_host, ':');
    if ($pos !== false) {
        $own_host = substr($own_host, 0, $pos);
    }
    $own_host = trim($own_host, '[]');
    if (in_array($origin_host, ['localhost', '127.0.0.1', '::1'], true)) {
        return;
    }
    if ($own_host !== '' && $origin_host === $own_host) {
        return;
    }
    json_error('origin_forbidden', 'Origin not allowed.', 403);
}

/** An MCP-Protocol-Version header, when present, must name a supported version (else 400). */
function mcp_check_protocol_version(): void {
    $version = (string) ($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '');
    if ($version !== '' && !in_array($version, MCP_PROTOCOL_VERSIONS, true)) {
        json_error(
            'unsupported_protocol_version',
            'Supported MCP protocol versions: ' . implode(', ', MCP_PROTOCOL_VERSIONS) . '.',
            400
        );
    }
}

/* ---------------------------------------------------------------------------
 * Tool handlers — ($user_id, $args) -> MCP result array.  Failures raise
 * ApiException (via json_error) -> isError result; McpInvalidParams -> -32602.
 * ------------------------------------------------------------------------- */

function mcp_tool_store_memory(int $user_id, array $args): array {
    $text = (string) ($args['text'] ?? '');
    if (trim($text) === '') {
        throw new McpInvalidParams('"text" must be a non-empty string.');
    }
    $hints = $args['hints'] ?? null;
    $hints_json = (is_array($hints) && array_is_list($hints)) ? json_encode($hints) : '[]';
    $ns = trim((string) ($args['namespace'] ?? ''));
    $namespace = $ns !== '' ? $ns : 'default';

    $payload = mem_ingest_core($user_id, [
        'text'           => $text,
        'hints_json'     => $hints_json,
        'namespace'      => $namespace,
        'explicit_model' => null,
        'preview'        => false,
    ]);
    return mcp_text_result($payload);
}

function mcp_tool_search_memory(int $user_id, array $args): array {
    $query = (string) ($args['query'] ?? '');
    if (trim($query) === '') {
        throw new McpInvalidParams('"query" must be a non-empty string.');
    }
    $subject = isset($args['subject']) && trim((string) $args['subject']) !== '' ? trim((string) $args['subject']) : null;
    $verb    = isset($args['verb']) && trim((string) $args['verb']) !== '' ? trim((string) $args['verb']) : null;
    $limit   = max(1, min(50, (int) ($args['limit'] ?? 10)));
    $ns = trim((string) ($args['namespace'] ?? ''));
    $namespace = $ns !== '' ? $ns : 'default';

    if ($subject === null && $verb === null) {
        // The compartment pre-filter is required; instead of a bare 400, return
        // the matching subjects so the agent can pick one and retry.
        $terms = [];
        foreach (preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (mb_strlen($t) >= 3) { $terms[] = $t; }
        }
        if ($terms === []) { $terms = [trim($query)]; }
        $patterns = array_map(fn($t) => '%' . $t . '%', $terms);
        $place = implode(', ', array_fill(0, count($patterns), '?'));
        $rows = db_query(
            "SELECT canonical_name AS name, subject_type AS type
               FROM maludb_subject
              WHERE canonical_name ILIKE ANY(ARRAY[$place])
              ORDER BY canonical_name
              LIMIT 10",
            $patterns
        );
        $matches = implode(', ', array_map(fn($r) => $r['name'] . ' (' . $r['type'] . ')', $rows));
        if ($matches === '') { $matches = 'none'; }
        return mcp_error_result(
            'missing_field',
            'Provide subject and/or verb — the compartment pre-filter is required.'
            . ' Known subjects matching your query: ' . $matches . '.'
            . ' Pick one or call find_subjects.'
        );
    }

    $payload = mem_search_core($user_id, [
        'query'           => $query,
        'subject'         => $subject,
        'verb'            => $verb,
        'namespace'       => $namespace,
        'limit'           => $limit,
        'metric'          => 'cosine',
        'embedding_model' => null,
    ]);
    return mcp_text_result($payload);
}

function mcp_tool_find_subjects(int $user_id, array $args): array {
    $q = isset($args['query']) && trim((string) $args['query']) !== '' ? trim((string) $args['query']) : null;
    $limit = max(1, min(200, (int) ($args['limit'] ?? 25)));

    $where = '';
    $params = [];
    if ($q !== null) {
        $where = 'WHERE s.canonical_name ILIKE ? OR s.description ILIKE ?';
        $params = ['%' . $q . '%', '%' . $q . '%'];
    }

    $sql = "SELECT s.subject_id     AS id,
                   s.canonical_name AS name,
                   s.subject_type   AS type,
                   s.description
              FROM maludb_subject s
              $where
             ORDER BY s.canonical_name
             LIMIT ?";
    $params[] = $limit;

    $rows = db_query($sql, $params);
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; }
    unset($r);
    return mcp_text_result(['subjects' => $rows]);
}

/** Resolve a subject reference (numeric id or canonical name) to a row. */
function mcp_resolve_subject(string $ref): array {
    $ref = trim($ref);
    if ($ref === '') {
        throw new McpInvalidParams('"subject" must be a non-empty string.');
    }

    $base = 'SELECT subject_id AS id, canonical_name AS name, subject_type AS type FROM maludb_subject';
    if (ctype_digit($ref)) {
        $row = db_one("$base WHERE subject_id = ?", [(int) $ref]);
        if ($row === null) {
            json_error('not_found', 'No subject with id ' . $ref . '.', 404);
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    $row = db_one("$base WHERE canonical_name = ?", [$ref]);
    if ($row === null) {
        $candidates = db_query("$base WHERE canonical_name ILIKE ? ORDER BY canonical_name LIMIT 6", ['%' . $ref . '%']);
        if (count($candidates) === 1) {
            $row = $candidates[0];
        } elseif ($candidates === []) {
            json_error('not_found', 'No subject matching "' . $ref . '". Call find_subjects.', 404);
        } else {
            $names = implode(', ', array_map(fn($c) => $c['name'], $candidates));
            json_error(
                'ambiguous_subject',
                'Multiple subjects match "' . $ref . '": ' . $names . '. Pick one exact canonical name.',
                422
            );
        }
    }
    $row['id'] = (int) $row['id'];
    return $row;
}

function mcp_tool_explore_subject(int $user_id, array $args): array {
    $subject = mcp_resolve_subject((string) ($args['subject'] ?? ''));
    $direction = strtolower(trim((string) ($args['direction'] ?? '')));
    if ($direction === '') { $direction = 'both'; }
    if (!in_array($direction, ['out', 'in', 'both'], true)) {
        throw new McpInvalidParams('"direction" must be one of: out, in, both.');
    }
    $depth = max(1, min(3, (int) ($args['depth'] ?? 1)));
    $verb = isset($args['verb']) && trim((string) $args['verb']) !== '' ? trim((string) $args['verb']) : null;

    if ($depth === 1) {
        $rows = db_tx_core(function () use ($subject, $direction, $verb) {
            if ($verb !== null) {
                $rows = db_query(
                    "SELECT neighbor_kind, neighbor_id, rel, edge_store,
                            confidence, provenance, label
                       FROM maludb_graph_neighbors(?, ?, ?, ARRAY[?]::text[])",
                    ['subject', $subject['id'], $direction, $verb]
                );
            } else {
                $rows = db_query(
                    "SELECT neighbor_kind, neighbor_id, rel, edge_store,
                            confidence, provenance, label
                       FROM maludb_graph_neighbors(?, ?, ?)",
                    ['subject', $subject['id'], $direction]
                );
            }
            foreach ($rows as &$r) {
                $r['neighbor_id'] = (int) $r['neighbor_id'];
                $r['confidence']  = $r['confidence'] === null ? null : (float) $r['confidence'];
            }
            unset($r);
            return $rows;
        });
        return mcp_text_result(['subject' => $subject, 'direction' => $direction, 'depth' => $depth, 'neighbors' => $rows]);
    }

    $rows = db_tx_core(function () use ($subject, $direction, $depth, $verb) {
        if ($verb !== null) {
            $rows = db_query(
                "SELECT object_kind, object_id, depth, rel, edge_store, label, path
                   FROM maludb_graph_walk(?, ?, ?, ?, ARRAY[?]::text[])",
                ['subject', $subject['id'], $depth, $direction, $verb]
            );
        } else {
            $rows = db_query(
                "SELECT object_kind, object_id, depth, rel, edge_store, label, path
                   FROM maludb_graph_walk(?, ?, ?, ?)",
                ['subject', $subject['id'], $depth, $direction]
            );
        }
        foreach ($rows as &$r) {
            $r['object_id'] = (int) $r['object_id'];
            $r['depth']     = (int) $r['depth'];
            // path is a Postgres text[] — PDO returns it as a literal like {1,2,3};
            // expose as an array (same parsing as /v1/graph/walk).
            $r['path'] = ($r['path'] === null || $r['path'] === '{}')
                ? []
                : array_map('intval', explode(',', trim($r['path'], '{}')));
        }
        unset($r);
        return $rows;
    });
    return mcp_text_result(['subject' => $subject, 'direction' => $direction, 'depth' => $depth, 'walk' => $rows]);
}

function mcp_tool_store_document(int $user_id, array $args): array {
    $title = trim((string) ($args['title'] ?? ''));
    $text  = (string) ($args['text'] ?? '');
    if ($title === '') {
        throw new McpInvalidParams('"title" must be a non-empty string.');
    }
    if (trim($text) === '') {
        throw new McpInvalidParams('"text" must be a non-empty string.');
    }

    $strings = static function ($v): array {
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $s) { if (is_string($s) && trim($s) !== '') $out[] = trim($s); }
        return $out;
    };
    $st = trim((string) ($args['source_type'] ?? ''));
    $ns = trim((string) ($args['namespace'] ?? ''));

    $payload = mem_documents_core($user_id, [
        'title'           => $title,
        'text'            => $text,
        'source_type'     => $st !== '' ? $st : 'document',
        'media_type'      => null,
        'document_type'   => null,
        'metadata_json'   => json_encode(['source' => 'mcp']),
        'projects'        => $strings($args['projects'] ?? null),
        'subjects'        => $strings($args['subjects'] ?? null),
        'verbs'           => [],
        'events'          => [],
        'chunk_max'       => 2000,
        'chunk_overlap'   => 200,
        'embedding_model' => null,
        'explicit_model'  => null,
        'provided_edges'  => null,
        'namespace'       => $ns !== '' ? $ns : 'default',
    ]);
    return mcp_text_result($payload);
}

function mcp_tool_get_document(int $user_id, array $args): array {
    $v = $args['document_id'] ?? null;
    if (is_int($v)) {
        $document_id = $v;
    } elseif (is_numeric($v)) {
        $document_id = (int) $v;
    } else {
        throw new McpInvalidParams('"document_id" must be an integer.');
    }

    $doc = db_one(
        "SELECT d.document_id              AS id,
                d.title,
                d.source_type,
                d.media_type,
                d.document_type,
                d.primary_project_id,
                d.metadata_jsonb->>'description' AS description,
                sp.content_size,
                sp.content_hash,
                d.created_at,
                d.updated_at
           FROM maludb_document d
           LEFT JOIN maludb_source_package sp ON sp.source_package_id = d.source_package_id
          WHERE d.document_id = ?",
        [$document_id]
    );
    if ($doc === null) {
        json_error('not_found', 'Document not found.', 404);
    }
    $doc['id']                 = (int) $doc['id'];
    $doc['content_size']       = $doc['content_size'] === null ? null : (int) $doc['content_size'];
    $doc['primary_project_id'] = $doc['primary_project_id'] === null ? null : (int) $doc['primary_project_id'];

    $tags = db_query(
        "SELECT tag_id, tag_kind, tag_value, tag_object_type, tag_object_id, provenance, confidence
           FROM maludb_document_tag
          WHERE document_id = ?
          ORDER BY tag_kind, tag_value, tag_id",
        [$document_id]
    );
    foreach ($tags as &$t) {
        $t['tag_id']        = (int) $t['tag_id'];
        $t['tag_object_id'] = $t['tag_object_id'] === null ? null : (int) $t['tag_object_id'];
        $t['confidence']    = $t['confidence'] === null ? null : (float) $t['confidence'];
    }
    unset($t);
    $doc['tags'] = $tags;

    return mcp_text_result(['document' => $doc]);
}

function mcp_tool_find_skills(int $user_id, array $args): array {
    $q       = isset($args['query']) && trim((string) $args['query']) !== '' ? trim((string) $args['query']) : null;
    $subject = isset($args['subject']) && trim((string) $args['subject']) !== '' ? trim((string) $args['subject']) : null;
    $verb    = isset($args['verb']) && trim((string) $args['verb']) !== '' ? trim((string) $args['verb']) : null;
    $limit   = max(1, min(200, (int) ($args['limit'] ?? 20)));

    if ($subject !== null || $verb !== null) {
        // Tag-aware discovery (same SQL as GET /v1/skills?subject=&verb=).
        $rows = db_query(
            "SELECT owner_schema, skill_id AS id, skill_name AS name, description,
                    version, visibility, to_jsonb(subjects) AS subjects,
                    to_jsonb(verbs) AS verbs, to_jsonb(keywords) AS keywords, score,
                    to_jsonb(match_reasons) AS match_reasons, is_public, is_forkable,
                    source_owner_schema, source_skill_id, updated_at
               FROM maludb_skill_search(?, ?, ?, NULL, ?)",
            [$q, $subject, $verb, $limit]
        );
        foreach ($rows as &$r) {
            $r['id']              = (int) $r['id'];
            $r['score']           = $r['score'] === null ? null : (float) $r['score'];
            $r['source_skill_id'] = $r['source_skill_id'] === null ? null : (int) $r['source_skill_id'];
            $r['is_public']       = $r['is_public'] === null ? null : (bool) $r['is_public'];
            $r['is_forkable']     = $r['is_forkable'] === null ? null : (bool) $r['is_forkable'];
            foreach (['subjects', 'verbs', 'keywords', 'match_reasons'] as $jc) {
                $r[$jc] = $r[$jc] === null ? null : json_decode((string) $r[$jc]);
            }
        }
        unset($r);
        return mcp_text_result(['skills' => $rows]);
    }

    $clauses = [];
    $params  = [];
    if ($q !== null) {
        $clauses[] = '(skill_name ILIKE ? OR description ILIKE ?)';
        $params[]  = '%' . $q . '%';
        $params[]  = '%' . $q . '%';
    }
    $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

    $sql = "SELECT skill_id AS id, skill_name AS name, description, version,
                   visibility, packaging_kind, enabled, created_at
              FROM maludb_skill
              $where
             ORDER BY skill_name
             LIMIT ?";
    $params[] = $limit;

    $rows = db_query($sql, $params);
    foreach ($rows as &$r) {
        $r['id']      = (int) $r['id'];
        $r['enabled'] = $r['enabled'] === null ? null : (bool) $r['enabled'];
    }
    unset($r);
    return mcp_text_result(['skills' => $rows]);
}

function mcp_tool_get_skill(int $user_id, array $args): array {
    $skill_id = $args['skill_id'] ?? null;
    $name = isset($args['name']) && trim((string) $args['name']) !== '' ? trim((string) $args['name']) : null;
    if ($skill_id === null && $name === null) {
        throw new McpInvalidParams('Provide "skill_id" or "name".');
    }

    if ($skill_id === null) {
        $row = db_one(
            "SELECT skill_id FROM maludb_skill
              WHERE skill_name = ? AND (enabled IS DISTINCT FROM FALSE)
              ORDER BY skill_id DESC LIMIT 1",
            [$name]
        );
        if ($row === null) {
            json_error('not_found', 'No enabled skill named "' . $name . '".', 404);
        }
        $skill_id = (int) $row['skill_id'];
    } elseif (is_int($skill_id)) {
        // already an integer
    } elseif (is_numeric($skill_id)) {
        $skill_id = (int) $skill_id;
    } else {
        throw new McpInvalidParams('"skill_id" must be an integer.');
    }

    $skill = db_one(
        "SELECT skill_id AS id, skill_name AS name, description, markdown, version,
                visibility, enabled, bundle_hash, frontmatter_jsonb,
                source_owner_schema, source_skill_id, created_at
           FROM maludb_skill WHERE skill_id = ?",
        [$skill_id]
    );
    if ($skill === null) {
        json_error('not_found', 'Skill not found.', 404);
    }
    $skill['id']                = (int) $skill['id'];
    $skill['source_skill_id']   = $skill['source_skill_id'] === null ? null : (int) $skill['source_skill_id'];
    $skill['enabled']           = $skill['enabled'] === null ? null : (bool) $skill['enabled'];
    // Decode as an object so empty frontmatter stays {} rather than [].
    $skill['frontmatter_jsonb'] = $skill['frontmatter_jsonb'] === null ? null : json_decode((string) $skill['frontmatter_jsonb']);

    // Listing only — no maludb_source_package join, so file contents never load.
    $files = db_query(
        "SELECT relative_path, file_size, media_type
           FROM maludb_skill_file
          WHERE skill_id = ?
          ORDER BY relative_path",
        [$skill_id]
    );
    foreach ($files as &$f) {
        $f['file_size'] = $f['file_size'] === null ? null : (int) $f['file_size'];
    }
    unset($f);

    return mcp_text_result(['skill' => $skill, 'files' => $files]);
}

/** Run one named tool. The registry and this dispatch table stay in lockstep. */
function mcp_run_tool(string $name, int $user_id, array $args): array {
    return match ($name) {
        'store_memory'    => mcp_tool_store_memory($user_id, $args),
        'search_memory'   => mcp_tool_search_memory($user_id, $args),
        'find_subjects'   => mcp_tool_find_subjects($user_id, $args),
        'explore_subject' => mcp_tool_explore_subject($user_id, $args),
        'store_document'  => mcp_tool_store_document($user_id, $args),
        'get_document'    => mcp_tool_get_document($user_id, $args),
        'find_skills'     => mcp_tool_find_skills($user_id, $args),
        'get_skill'       => mcp_tool_get_skill($user_id, $args),
    };
}

/* ---------------------------------------------------------------------------
 * Method handlers
 * ------------------------------------------------------------------------- */

function mcp_handle_initialize(stdClass $params): array {
    $requested = $params->protocolVersion ?? null;
    $version = (is_string($requested) && in_array($requested, MCP_PROTOCOL_VERSIONS, true))
        ? $requested
        : MCP_DEFAULT_PROTOCOL_VERSION;
    return [
        'protocolVersion' => $version,
        'capabilities'    => ['tools' => ['listChanged' => false]],
        'serverInfo'      => MCP_SERVER_INFO,
    ];
}

function mcp_handle_tools_call(int $user_id, stdClass $params, mixed $req_id): array {
    $name = $params->name ?? null;
    if (!is_string($name) || !isset(mcp_tools_by_name()[$name])) {
        $label = is_string($name) ? "'" . $name . "'" : json_encode($name);
        return mcp_rpc_error($req_id, -32602, 'Unknown tool: ' . $label);
    }

    $args_raw = $params->arguments ?? null;
    $args = $args_raw instanceof stdClass ? json_decode(json_encode($args_raw), true) : [];
    $schema = mcp_tools_by_name()[$name]['inputSchema'];
    foreach (($schema['required'] ?? []) as $req_field) {
        if (!array_key_exists($req_field, $args) || $args[$req_field] === null) {
            return mcp_rpc_error($req_id, -32602, 'Missing required argument "' . $req_field . '" for tool "' . $name . '".');
        }
    }

    try {
        $result = mcp_run_tool($name, $user_id, $args);
    } catch (McpInvalidParams $e) {
        return mcp_rpc_error($req_id, -32602, $e->getMessage());
    } catch (ApiException $e) {
        $result = mcp_error_result($e->apiCode, $e->getMessage());
    } catch (PDOException $e) {
        [, $code, $sqlstate] = mcp_classify_pdo_error($e);
        $result = mcp_error_result($code, pg_error_message($e), $sqlstate);
    }
    return mcp_rpc_result($req_id, $result);
}

/* ---------------------------------------------------------------------------
 * Dispatch + the front controller
 * ------------------------------------------------------------------------- */

/**
 * Handle one decoded JSON-RPC message. Returns the response envelope, or null
 * for a notification (HTTP 202, empty body). $msg is json_decode() output with
 * assoc=false (stdClass for objects, array for batches) so the request `id`
 * keeps its JSON type (string/int/null) and lists are distinguishable.
 */
function mcp_dispatch(int $user_id, mixed $msg): ?array {
    if (is_array($msg)) {
        return mcp_rpc_error(null, -32600, 'Batch requests are not supported (MCP 2025-06-18).');
    }
    if (!($msg instanceof stdClass) || ($msg->jsonrpc ?? null) !== '2.0') {
        return mcp_rpc_error(null, -32600, 'Invalid request: expected a JSON-RPC 2.0 object.');
    }
    $method = $msg->method ?? null;
    if (!is_string($method) || $method === '') {
        return mcp_rpc_error($msg->id ?? null, -32600, 'Invalid request: "method" is required.');
    }

    // Notifications (no id) are accepted and ignored.
    if (!property_exists($msg, 'id')) {
        return null;
    }

    $req_id = $msg->id;
    $params = (isset($msg->params) && $msg->params instanceof stdClass) ? $msg->params : new stdClass();

    if ($method === 'initialize') {
        return mcp_rpc_result($req_id, mcp_handle_initialize($params));
    }
    if ($method === 'ping') {
        return mcp_rpc_result($req_id, new stdClass());   // {} — not []
    }
    if ($method === 'tools/list') {
        return mcp_rpc_result($req_id, ['tools' => mcp_tools()]);
    }
    if ($method === 'tools/call') {
        return mcp_handle_tools_call($user_id, $params, $req_id);
    }
    return mcp_rpc_error($req_id, -32601, 'Method not found: ' . $method);
}

/** Emit a JSON-RPC envelope (always HTTP 200) and stop. */
function mcp_emit(array $envelope): never {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($envelope, MCP_JSON_FLAGS);
    exit;
}

function mcp_main(): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        json_error('method_not_allowed', 'MCP requires POST. SSE streaming is not supported.', 405);
    }
    mcp_check_origin();
    mcp_check_protocol_version();

    // Same Bearer flow as REST; an auth failure throws ApiException(401), which
    // handle_uncaught renders as the standard {"error":{...}} body.
    $user_id = require_auth();

    $raw = file_get_contents('php://input');
    $msg = json_decode($raw === false ? '' : $raw);
    if (json_last_error() !== JSON_ERROR_NONE) {
        mcp_emit(mcp_rpc_error(null, -32700, 'Parse error'));
    }

    $envelope = mcp_dispatch($user_id, $msg);
    if ($envelope === null) {
        http_response_code(202);
        exit;
    }
    mcp_emit($envelope);
}

if (!defined('MALUDB_MCP_TESTING')) {
    mcp_main();
}
