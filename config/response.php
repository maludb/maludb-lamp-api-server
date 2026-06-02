<?php
/**
 * Shared response / auth / db helpers for the MaluDB API.
 *
 * The ONLY shared application code (requirements.md §3). Every endpoint file does:
 *   require_once __DIR__ . '/../../config/response.php';
 *
 * No autoloader, no namespace, no class hierarchy. Keep it small.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/local-database.php';
require_once __DIR__ . '/llm.php';

/* ---------------------------------------------------------------------------
 * Config flags
 * ------------------------------------------------------------------------- */

// Debug responses (meta.debug). Off unless MALUDB_DEBUG=1 in the environment.
if (!defined('DEBUG_ENABLED')) {
    define('DEBUG_ENABLED', (getenv('MALUDB_DEBUG') === '1'));
}

// Where SQL/app logs go. Default per requirements.md §1.1; fall back to a
// project-local dir when the default isn't writeable (dev without root).
function maludb_log_dir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;
    $preferred = getenv('MALUDB_LOG_DIR') ?: '/var/log/maludb';
    if (is_dir($preferred) && is_writable($preferred)) {
        return $dir = $preferred;
    }
    $fallback = dirname(__DIR__) . '/var/log';   // /var/www/var/log
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0775, true);
    }
    return $dir = (is_writable($fallback) ? $fallback : sys_get_temp_dir());
}

/* ---------------------------------------------------------------------------
 * SQL tracing (requirements.md §2.1) + per-request debug buffer (§2.2)
 * ------------------------------------------------------------------------- */

$GLOBALS['__auth_user_id'] = 'anon';   // set by require_auth()
$GLOBALS['__sql_trace']    = [];       // collected queries for ?debug=1

function endpoint_file(): string {
    return basename($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? 'unknown');
}

function iso_now_ms(): string {
    $t = microtime(true);
    return gmdate('Y-m-d\TH:i:s', (int)$t) . sprintf('.%03dZ', (int)(($t - floor($t)) * 1000));
}

function sql_log(string $sql, array $params, int $rows, float $dur_ms): void {
    // Feed the in-response debug block.
    $GLOBALS['__sql_trace'][] = [
        'sql'    => trim($sql),
        'params' => $params,
        'rows'   => $rows,
        'dur_ms' => round($dur_ms, 1),
    ];
    // Append the persistent trace block.
    $uri  = $_SERVER['REQUEST_URI'] ?? '';
    $line = sprintf(
        "%s  %s  %s  %s  user=%s\n  SQL: %s\n  PARAMS: %s\n  ROWS: %d\n  DUR:  %.1f ms\n\n",
        iso_now_ms(),
        endpoint_file(),
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $uri,
        (string)$GLOBALS['__auth_user_id'],
        preg_replace('/\n/', "\n       ", trim($sql)),
        json_encode(array_values($params)),
        $rows,
        $dur_ms
    );
    @file_put_contents(maludb_log_dir() . '/sql.log', $line, FILE_APPEND);
}

/* ---------------------------------------------------------------------------
 * DB wrappers — prepare / execute / log. Endpoints use ONLY these.
 * ------------------------------------------------------------------------- */

function db_query(string $sql, array $params = []): array {
    $pdo = Database::getInstance()->getConnection();
    $t0  = microtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    sql_log($sql, $params, count($rows), (microtime(true) - $t0) * 1000);
    return $rows;
}

function db_exec(string $sql, array $params = []): int {
    $pdo = Database::getInstance()->getConnection();
    $t0  = microtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $n = $stmt->rowCount();
    sql_log($sql, $params, $n, (microtime(true) - $t0) * 1000);
    return $n;
}

function db_one(string $sql, array $params = []): ?array {
    $pdo = Database::getInstance()->getConnection();
    $t0  = microtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    sql_log($sql, $params, $row ? 1 : 0, (microtime(true) - $t0) * 1000);
    return $row === false ? null : $row;
}

/**
 * Run $fn inside a transaction with the search_path the maludb_core facade needs.
 *
 * The maludb_* facade views/functions (episodes, svpor statements, the *_type pickers)
 * and the maludb_core.* resolvers reference their malu$* base tables + RLS grant tables
 * unqualified, so they only resolve when `maludb_core` is on the search_path. `public`
 * stays first so current_schema() = the tenant schema (owner_schema resolution / RLS).
 * SET LOCAL keeps the change scoped to the transaction.
 *
 * The callback receives the shared PDO handle; db_query/db_one/db_exec use that same
 * connection, so they participate in the transaction and the search_path. On any throw we
 * roll back and rethrow (the global handler maps DB SQLSTATEs → 409/422/500).
 */
function db_tx_core(callable $fn) {
    $pdo = Database::getInstance()->getConnection();
    $pdo->beginTransaction();
    $pdo->exec("SET LOCAL search_path TO public, maludb_core");
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* ---------------------------------------------------------------------------
 * SVO statement helpers (maludb_core 0.82.0) — shared by statements.php and
 * episodes_id_statements.php. A statement is
 *   (subject_kind, subject_id) --verb_id--> (object_kind, object_id).
 * Created via the idempotent maludb_svpor_statement_create(...) facade; both
 * endpoints call svpor_create_statement() inside db_tx_core() (the verb/subject/
 * predicate resolvers and the facade need maludb_core on the search_path).
 * ------------------------------------------------------------------------- */

/** Read-side column list for a maludb_svpor_statement row. */
function svpor_statement_cols(): string {
    return "statement_id AS id, subject_kind, subject_id, verb_id, object_kind, object_id,
            predicate_id, valid_from, valid_to, confidence, provenance, source_package_id,
            metadata_jsonb AS metadata, created_at";
}

/** Normalize scalar types on a statement row in place. */
function shape_statement(array &$r): void {
    foreach (['id','subject_id','verb_id','object_id','predicate_id','source_package_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['confidence'] = $r['confidence'] === null ? null : (float) $r['confidence'];
    // Decode as an object (not assoc) so empty metadata stays {} rather than [].
    $r['metadata']   = $r['metadata']   === null ? null : json_decode($r['metadata']);
}

/**
 * Create a statement from a request body and return the created (shaped) row.
 *
 * MUST run inside db_tx_core(). Recognized $body keys:
 *   verb | verb_id, subject_kind (default 'subject'), subject_id | subject (name,
 *   only when kind='subject' → create-or-resolve a person), object_kind, object_id,
 *   predicate | predicate_id, valid_from, valid_to, confidence, provenance
 *   (default 'provided'), source_package_id, metadata (object).
 * $force_object = ['kind'=>…, 'id'=>…] overrides object_kind/object_id (episode-scoped route).
 *
 * All shape/required-field checks (json_error 400/422) run before any DB write, so a
 * rejected request never leaves a half-resolved person behind.
 */
function svpor_create_statement(array $body, ?array $force_object = null): array {
    // ---- phase 1: parse + shape-validate (no DB writes) ----
    $verb_id = null; $verb_name = null;
    if (isset($body['verb_id'])) {
        if (!is_int($body['verb_id'])) json_error('validation_failed', '"verb_id" must be an integer.', 422);
        $verb_id = (int) $body['verb_id'];
    } elseif (isset($body['verb']) && trim((string) $body['verb']) !== '') {
        $verb_name = (string) $body['verb'];
    } else {
        json_error('missing_field', 'Provide "verb" (name) or "verb_id".', 400);
    }

    $subject_kind = isset($body['subject_kind']) && trim((string) $body['subject_kind']) !== ''
        ? (string) $body['subject_kind'] : 'subject';
    $subject_id = null; $subject_name = null;
    if (isset($body['subject_id'])) {
        if (!is_int($body['subject_id'])) json_error('validation_failed', '"subject_id" must be an integer.', 422);
        $subject_id = (int) $body['subject_id'];
    } elseif ($subject_kind === 'subject' && isset($body['subject']) && trim((string) $body['subject']) !== '') {
        $subject_name = (string) $body['subject'];
    } else {
        json_error('missing_field', 'Provide "subject_id", or "subject" (name) when subject_kind is "subject".', 400);
    }

    if ($force_object !== null) {
        $object_kind = (string) $force_object['kind'];
        $object_id   = (int) $force_object['id'];
    } else {
        $object_kind = isset($body['object_kind']) ? trim((string) $body['object_kind']) : '';
        if ($object_kind === '') json_error('missing_field', 'Field "object_kind" is required.', 400);
        if (!isset($body['object_id']) || !is_int($body['object_id'])) json_error('validation_failed', '"object_id" must be an integer.', 422);
        $object_id = (int) $body['object_id'];
    }

    $predicate_id = null; $predicate_name = null;
    if (isset($body['predicate_id'])) {
        if (!is_int($body['predicate_id'])) json_error('validation_failed', '"predicate_id" must be an integer.', 422);
        $predicate_id = (int) $body['predicate_id'];
    } elseif (isset($body['predicate']) && trim((string) $body['predicate']) !== '') {
        $predicate_name = (string) $body['predicate'];
    }
    if (array_key_exists('confidence', $body) && $body['confidence'] !== null && !is_numeric($body['confidence'])) {
        json_error('validation_failed', '"confidence" must be a number.', 422);
    }

    $valid_from = isset($body['valid_from']) ? (string) $body['valid_from'] : null;
    $valid_to   = isset($body['valid_to'])   ? (string) $body['valid_to']   : null;
    $confidence = (array_key_exists('confidence', $body) && $body['confidence'] !== null) ? (string) $body['confidence'] : null;
    $provenance = isset($body['provenance']) && trim((string) $body['provenance']) !== '' ? (string) $body['provenance'] : 'provided';
    $source_pkg = isset($body['source_package_id']) && $body['source_package_id'] !== null ? (int) $body['source_package_id'] : null;
    $metadata   = isset($body['metadata']) && is_array($body['metadata']) ? json_encode($body['metadata']) : '{}';

    // ---- phase 2: resolve names (SELECTs), then upsert the subject, then create ----
    if ($verb_id === null) {
        $verb_id = db_one("SELECT maludb_core.resolve_svpor_verb(?) AS id", [$verb_name])['id'] ?? null;
        if ($verb_id === null) json_error('validation_failed', 'Unknown verb "' . $verb_name . '".', 422);
        $verb_id = (int) $verb_id;
    }
    if ($predicate_name !== null) {
        $predicate_id = db_one("SELECT maludb_core.resolve_svpor_predicate(?) AS id", [$predicate_name])['id'] ?? null;
        if ($predicate_id === null) json_error('validation_failed', 'Unknown predicate "' . $predicate_name . '".', 422);
        $predicate_id = (int) $predicate_id;
    }
    if ($subject_id === null) {
        $subject_id = (int) db_one(
            "SELECT register_svpor_subject(p_canonical_name => ?, p_subject_type => 'person') AS id",
            [$subject_name]
        )['id'];
    }

    $row = db_one(
        "SELECT maludb_svpor_statement_create(
                    p_subject_kind      => ?, p_subject_id => ?,
                    p_verb_id           => ?,
                    p_object_kind       => ?, p_object_id  => ?,
                    p_predicate_id      => ?,
                    p_valid_from        => ?::timestamptz, p_valid_to => ?::timestamptz,
                    p_confidence        => ?::numeric,
                    p_provenance        => ?,
                    p_source_package_id => ?,
                    p_metadata_jsonb    => ?::jsonb
                ) AS id",
        [$subject_kind, $subject_id, $verb_id, $object_kind, $object_id, $predicate_id,
         $valid_from, $valid_to, $confidence, $provenance, $source_pkg, $metadata]
    );

    $stmt = db_one("SELECT " . svpor_statement_cols() . " FROM maludb_svpor_statement WHERE statement_id = ?", [(int) $row['id']]);
    shape_statement($stmt);
    return $stmt;
}

/* ---------------------------------------------------------------------------
 * Typed-attribute helpers (maludb_core 0.83.0+) — shared by attributes.php and
 * attributes_id.php. An attribute is a typed property on any node OR edge,
 * addressed by (target_kind, target_id); target_kind includes 'svpor_statement'
 * so graph edges carry attributes too. Created/upserted (on target+attr_name)
 * via the idempotent maludb_svpor_attribute_create(...) facade. Both endpoints
 * call svpor_create_attribute() inside db_tx_core() (the facade references its
 * malu$* base tables unqualified, so it needs maludb_core on the search_path).
 * ------------------------------------------------------------------------- */

/** Read-side column list for a maludb_svpor_attribute row. */
function svpor_attribute_cols(): string {
    return "attribute_id AS id, target_kind, target_id, attr_name,
            value_timestamp, value_range, value_numeric, value_text, value_jsonb,
            unit, provenance, confidence, valid_from, valid_to,
            metadata_jsonb AS metadata, created_at, ref_source, ref_entity, ref_key";
}

/** Normalize scalar types on an attribute row in place. */
function shape_attribute(array &$r): void {
    foreach (['id', 'target_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    foreach (['value_numeric', 'confidence'] as $k) {
        $r[$k] = $r[$k] === null ? null : (float) $r[$k];
    }
    // Decode as objects (not assoc) so empty values stay {} rather than [].
    $r['value_jsonb'] = $r['value_jsonb'] === null ? null : json_decode($r['value_jsonb']);
    $r['metadata']    = $r['metadata']    === null ? null : json_decode($r['metadata']);
    // value_range (tstzrange) is left as its text form.
}

/**
 * Create/upsert an attribute from a request body and return the (shaped) row.
 *
 * MUST run inside db_tx_core(). Upsert is on (target_kind, target_id, attr_name).
 * Recognized $body keys:
 *   target_kind (req), target_id (req int), attr_name (req), value_timestamp,
 *   value_range, value_numeric, value_text, value_jsonb, unit,
 *   provenance (default 'provided'), confidence, valid_from, valid_to,
 *   metadata (object), ref_source, ref_entity, ref_key.
 * $force_target = ['kind'=>…,'id'=>…] overrides target_kind/target_id (scoped routes).
 *
 * All shape/required checks (json_error 400/422) run before any DB write.
 */
function svpor_create_attribute(array $body, ?array $force_target = null): array {
    // ---- phase 1: parse + shape-validate (no DB writes) ----
    if ($force_target !== null) {
        $target_kind = (string) $force_target['kind'];
        $target_id   = (int) $force_target['id'];
    } else {
        $target_kind = isset($body['target_kind']) ? trim((string) $body['target_kind']) : '';
        if ($target_kind === '') json_error('missing_field', 'Field "target_kind" is required.', 400);
        if (!isset($body['target_id']) || !is_int($body['target_id'])) {
            json_error('validation_failed', '"target_id" must be an integer.', 422);
        }
        $target_id = (int) $body['target_id'];
    }

    $attr_name = isset($body['attr_name']) ? trim((string) $body['attr_name']) : '';
    if ($attr_name === '') json_error('missing_field', 'Field "attr_name" is required.', 400);

    foreach (['value_numeric', 'confidence'] as $k) {
        if (array_key_exists($k, $body) && $body[$k] !== null && !is_numeric($body[$k])) {
            json_error('validation_failed', "\"$k\" must be a number.", 422);
        }
    }

    $value_timestamp = isset($body['value_timestamp']) ? (string) $body['value_timestamp'] : null;
    $value_range     = isset($body['value_range'])     ? (string) $body['value_range']     : null;
    $value_numeric   = (array_key_exists('value_numeric', $body) && $body['value_numeric'] !== null) ? (string) $body['value_numeric'] : null;
    $value_text      = isset($body['value_text'])      ? (string) $body['value_text']      : null;
    $value_jsonb     = array_key_exists('value_jsonb', $body) && $body['value_jsonb'] !== null ? json_encode($body['value_jsonb']) : null;
    $unit            = isset($body['unit'])            ? (string) $body['unit']            : null;
    $provenance      = isset($body['provenance']) && trim((string) $body['provenance']) !== '' ? (string) $body['provenance'] : 'provided';
    $confidence      = (array_key_exists('confidence', $body) && $body['confidence'] !== null) ? (string) $body['confidence'] : null;
    $valid_from      = isset($body['valid_from']) ? (string) $body['valid_from'] : null;
    $valid_to        = isset($body['valid_to'])   ? (string) $body['valid_to']   : null;
    $metadata        = isset($body['metadata']) && is_array($body['metadata']) ? json_encode($body['metadata']) : '{}';
    $ref_source      = isset($body['ref_source']) ? (string) $body['ref_source'] : null;
    $ref_entity      = isset($body['ref_entity']) ? (string) $body['ref_entity'] : null;
    $ref_key         = isset($body['ref_key'])    ? (string) $body['ref_key']    : null;

    // ---- phase 2: upsert via the facade (named args; idempotent on target+attr_name) ----
    $row = db_one(
        "SELECT maludb_svpor_attribute_create(
                    p_target_kind     => ?, p_target_id => ?, p_attr_name => ?,
                    p_value_timestamp => ?::timestamptz,
                    p_value_range     => ?::tstzrange,
                    p_value_numeric   => ?::numeric,
                    p_value_text      => ?,
                    p_value_jsonb     => ?::jsonb,
                    p_unit            => ?,
                    p_provenance      => ?,
                    p_confidence      => ?::numeric,
                    p_valid_from      => ?::timestamptz,
                    p_valid_to        => ?::timestamptz,
                    p_metadata_jsonb  => ?::jsonb,
                    p_ref_source      => ?, p_ref_entity => ?, p_ref_key => ?
                ) AS id",
        [$target_kind, $target_id, $attr_name, $value_timestamp, $value_range, $value_numeric,
         $value_text, $value_jsonb, $unit, $provenance, $confidence, $valid_from, $valid_to,
         $metadata, $ref_source, $ref_entity, $ref_key]
    );

    $attr = db_one("SELECT " . svpor_attribute_cols() . " FROM maludb_svpor_attribute WHERE attribute_id = ?", [(int) $row['id']]);
    shape_attribute($attr);
    return $attr;
}

/**
 * For a list endpoint called with ?with=attributes: attach an `attributes` jsonb to
 * each row from the given maludb_*_with_attributes view, matched on $pk_col = row['id'].
 * One extra query inside db_tx_core() (the *_with_attributes views resolve their malu$*
 * tables there). $view/$pk_col are endpoint constants (never user input).
 */
function attach_attributes(array &$rows, string $view, string $pk_col): void {
    if (!$rows) return;
    $ids   = array_map(fn($r) => (int) $r['id'], $rows);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $attrs = db_tx_core(fn() => db_query(
        "SELECT $pk_col AS id, attributes FROM $view WHERE $pk_col IN ($place)", $ids
    ));
    $byId = [];
    foreach ($attrs as $a) {
        $byId[(int) $a['id']] = $a['attributes'] === null ? null : json_decode($a['attributes']);
    }
    foreach ($rows as &$r) { $r['attributes'] = $byId[(int) $r['id']] ?? null; }
    unset($r);
}

/* ---------------------------------------------------------------------------
 * Document ↔ graph helpers (maludb_core 0.87.0) — documents are first-class graph
 * nodes. A project/subject/stakeholder tag on a document is mirrored as a real
 * edge  (document) --concerns|mentions|involves--> (subject)  plus the resolved id
 * on the soft tag row, exactly as maludb_upload_document does. All three helpers
 * MUST run inside db_tx_core() (the graph facades resolve their malu$* tables there).
 * ------------------------------------------------------------------------- */

/** Document tag_kind → [subject_type, verb] for the three subject-like kinds; null otherwise. */
function document_link_spec(string $tag_kind): ?array {
    static $map = [
        'project'     => ['project', 'concerns'],
        'subject'     => ['concept', 'mentions'],
        'stakeholder' => ['person',  'involves'],
    ];
    return $map[$tag_kind] ?? null;
}

/**
 * Link a document to a project/subject/stakeholder by name (idempotent): resolve-or-create the
 * subject WITHOUT clobbering an existing subject's type, create the document→subject edge, and
 * record the resolved id on the soft tag row. Returns the subject_id (null for a blank name).
 */
function document_link_subject(int $document_id, string $tag_kind, string $name, string $provenance = 'provided'): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $spec = document_link_spec($tag_kind);
    if ($spec === null) json_error('validation_failed', 'Unsupported document link kind "' . $tag_kind . '".', 422);
    [$subject_type, $verb] = $spec;

    // Resolve-or-create the subject. Reuse an existing one as-is (never override its type) —
    // mirrors maludb_core._document_graph_link; register_svpor_subject() would clobber the type.
    $row = db_one("SELECT subject_id FROM maludb_subject WHERE canonical_name = ?", [$name]);
    $subject_id = $row !== null
        ? (int) $row['subject_id']
        : (int) db_one("SELECT register_svpor_subject(p_canonical_name => ?, p_subject_type => ?) AS id", [$name, $subject_type])['id'];

    $verb_id = (int) db_one("SELECT maludb_core.resolve_svpor_verb(?) AS id", [$verb])['id'];

    db_one(
        "SELECT maludb_svpor_statement_create(
                    p_subject_kind => 'document', p_subject_id => ?,
                    p_verb_id      => ?,
                    p_object_kind  => 'subject',  p_object_id  => ?,
                    p_provenance   => ?) AS id",
        [$document_id, $verb_id, $subject_id, $provenance]
    );

    // The soft tag carries the resolved object so the UI can link to the real record.
    $tag = db_one(
        "SELECT tag_id FROM maludb_document_tag
          WHERE document_id = ? AND tag_kind = ? AND tag_value = ? AND provenance = ?",
        [$document_id, $tag_kind, $name, $provenance]
    );
    if ($tag === null) {
        db_exec(
            "INSERT INTO maludb_document_tag (document_id, tag_kind, tag_value, tag_object_type, tag_object_id, provenance)
             VALUES (?, ?, ?, 'subject', ?, ?)",
            [$document_id, $tag_kind, $name, $subject_id, $provenance]
        );
    } else {
        db_exec(
            "UPDATE maludb_document_tag SET tag_object_type = 'subject', tag_object_id = ? WHERE tag_id = ?",
            [$subject_id, (int) $tag['tag_id']]
        );
    }
    return $subject_id;
}

/**
 * Remove a document↔subject link by name: delete the edge, delete the soft tag row, and if the
 * subject was the document's primary project, repoint primary_project_id to the first remaining
 * project tag (else NULL). No-op when the link does not exist.
 */
function document_unlink_subject(int $document_id, string $tag_kind, string $name, string $provenance = 'provided'): void {
    $name = trim($name);
    if ($name === '') return;
    $spec = document_link_spec($tag_kind);
    if ($spec === null) json_error('validation_failed', 'Unsupported document link kind "' . $tag_kind . '".', 422);
    [, $verb] = $spec;

    $row = db_one("SELECT subject_id FROM maludb_subject WHERE canonical_name = ?", [$name]);
    if ($row !== null) {
        $subject_id = (int) $row['subject_id'];
        $verb_id    = (int) db_one("SELECT maludb_core.resolve_svpor_verb(?) AS id", [$verb])['id'];
        $stmt = db_one(
            "SELECT statement_id FROM maludb_svpor_statement
              WHERE subject_kind = 'document' AND subject_id = ?
                AND object_kind  = 'subject'  AND object_id  = ? AND verb_id = ?",
            [$document_id, $subject_id, $verb_id]
        );
        if ($stmt !== null) {
            db_one("SELECT maludb_svpor_statement_delete(?) AS d", [(int) $stmt['statement_id']]);
        }
        // If this was the primary project, repoint to the first OTHER project tag (else NULL).
        db_exec(
            "UPDATE maludb_document SET primary_project_id = (
                 SELECT t.tag_object_id FROM maludb_document_tag t
                  WHERE t.document_id = ? AND t.tag_kind = 'project'
                    AND t.tag_value <> ? AND t.tag_object_id IS NOT NULL
                  ORDER BY t.tag_id LIMIT 1)
              WHERE document_id = ? AND primary_project_id = ?",
            [$document_id, $name, $document_id, $subject_id]
        );
    }
    db_exec(
        "DELETE FROM maludb_document_tag WHERE document_id = ? AND tag_kind = ? AND tag_value = ? AND provenance = ?",
        [$document_id, $tag_kind, $name, $provenance]
    );
}

/**
 * Documents linked to a subject/project through the unified graph (concerns/mentions/involves
 * edges). Returns [{id, title, rel}], one row per document (first rel kept). MUST run inside
 * db_tx_core(). Powers the "documents for this project/subject" lists on detail pages.
 */
function document_neighbors(int $subject_id): array {
    $rows = db_query(
        "SELECT neighbor_id, label, rel
           FROM maludb_graph_neighbors('subject', ?, 'both', ARRAY['concerns','mentions','involves'])
          WHERE neighbor_kind = 'document'
          ORDER BY neighbor_id",
        [$subject_id]
    );
    $out  = [];
    $seen = [];
    foreach ($rows as $r) {
        $id = (int) $r['neighbor_id'];
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        $out[] = ['id' => $id, 'title' => $r['label'], 'rel' => $r['rel']];
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * Memory pipeline helpers (maludb_core memory) — document → SVPO-extraction →
 * vector-memory. PostgreSQL cannot make outbound HTTP calls, so the API is the
 * model worker: it calls the LLM (extraction) and the embedding model, then writes
 * results back via the maludb_memory_* facades. All DB work runs in db_tx_core()
 * (search_path public, maludb_core → current_schema() = tenant, owner_schema stamped).
 *
 * No live model creds are required to exercise the pipeline: mem_embed() falls back
 * to a deterministic local embedding (same text → same vector), so upload→ingest→
 * search round-trips for tests; real creds (env / DB secret) switch on the HTTP path.
 * ------------------------------------------------------------------------- */

// NOTE: the outbound model calls (chat/extract/embed), chunking, and the HTTP transport live in
// config/llm.php (the centralized LLM layer), required above. The two helpers kept here are the
// DB-facing glue: mem_vector_literal (SQL literal) and mem_resolve_token (Postgres secret resolve).

/** Render a float array as a malu_vector literal body, e.g. "[0.1,-0.2,...]". Cast in SQL. */
function mem_vector_literal(array $floats): string {
    // Fixed-precision, locale-independent formatting (avoid comma decimals).
    $parts = array_map(fn($f) => rtrim(rtrim(sprintf('%.8f', (float) $f), '0'), '.') ?: '0', $floats);
    return '[' . implode(',', $parts) . ']';
}

/** Resolve a stored secret to its plaintext (needs maludb_secret_consumer); env fallback. */
function mem_resolve_token(?string $secret_ref): ?string {
    if ($secret_ref !== null && $secret_ref !== '') {
        try {
            $row = db_tx_core(fn() => db_one("SELECT maludb_core.__secret_resolve(?) AS tok", [$secret_ref]));
            if ($row !== null && $row['tok'] !== null && $row['tok'] !== '') return (string) $row['tok'];
        } catch (Throwable $e) {
            // No maludb_secret_consumer grant (or secret missing) → fall through to env.
        }
    }
    $env = getenv('MALUDB_LLM_TOKEN');
    return $env !== false && $env !== '' ? $env : null;
}

/**
 * Run a write whose params contain a secret, logging a redacted form to sql.log (the standard
 * db_exec would log the plaintext token). Binds positionally; $redact indexes (1-based) are
 * replaced with "<redacted>" in the trace. Returns the first row (or null).
 */
function db_one_redacted(string $sql, array $params, array $redact): ?array {
    $pdo = Database::getInstance()->getConnection();
    $t0  = microtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    $logged = $params;
    foreach ($redact as $i) { if (array_key_exists($i - 1, $logged)) $logged[$i - 1] = '<redacted>'; }
    sql_log($sql, $logged, $row ? 1 : 0, (microtime(true) - $t0) * 1000);
    return $row === false ? null : $row;
}

/* ---------------------------------------------------------------------------
 * Responses (requirements.md §1.5, §2.2, §2.3)
 * ------------------------------------------------------------------------- */

function json_response($data, int $status = 200): never {
    if (DEBUG_ENABLED && (($_GET['debug'] ?? '') === '1') && is_array($data)) {
        $data['meta']['debug'] = [
            'file'    => endpoint_file(),
            'queries' => $GLOBALS['__sql_trace'],
        ];
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $code, string $message, int $status): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => $code, 'message' => $message]]);
    exit;
}

/* ---------------------------------------------------------------------------
 * Request body
 * ------------------------------------------------------------------------- */

function body_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        json_error('body_invalid_json', 'Request body is not valid JSON.', 400);
    }
    if (!is_array($data)) {
        json_error('bad_request', 'Request body must be a JSON object.', 400);
    }
    return $data;
}

/* ---------------------------------------------------------------------------
 * Auth (requirements.md §1.4) — resolved against the local MySQL `users` store.
 * The presented bearer token is hashed (sha256 of the part after `malu_`) and looked up in
 * MySQL; the matching row carries the user's role and the Postgres connection (DB_NAME/USER/
 * PASS) this request connects with. require_auth() configures Database with those creds before
 * any Postgres query runs. (Replaces the former Postgres api_tokens lookup.)
 * ------------------------------------------------------------------------- */

/** Role attached to the authenticated token (set by require_auth); null before auth. */
function current_role(): ?string {
    return $GLOBALS['__auth_role'] ?? null;
}

function bearer_token(): ?string {
    $hdr = null;
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
        }
    }
    $hdr = $hdr
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? null;
    if ($hdr === null) return null;
    if (!preg_match('/^Bearer\s+(\S+)$/i', trim($hdr), $m)) return null;
    return $m[1];
}

function require_auth(): int {
    $token = bearer_token();
    if ($token === null) {
        json_error('auth_missing', 'Authorization: Bearer token required.', 401);
    }
    if (!str_starts_with($token, 'malu_')) {
        json_error('auth_invalid', 'Malformed API token.', 401);
    }
    $hash = hash('sha256', substr($token, strlen('malu_')));
    $row  = LocalDatabase::resolveToken($hash);
    if ($row === null) {
        json_error('auth_invalid', 'Invalid or expired API token.', 401);
    }
    // Point the Postgres connection at this token's tenant database before any query runs.
    Database::configure((string) $row['pg_dbname'], (string) $row['pg_user'], (string) $row['pg_password']);
    $GLOBALS['__auth_role'] = $row['role'];
    return $GLOBALS['__auth_user_id'] = (int) $row['user_id'];
}

/* ---------------------------------------------------------------------------
 * Path & query params (requirements.md §3)
 * ------------------------------------------------------------------------- */

function path_id(): int {
    if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
        json_error('bad_request', 'Missing or non-numeric path id.', 400);
    }
    return (int)$_GET['id'];
}

function path_sub_id(): int {
    if (!isset($_GET['sub_id']) || !ctype_digit((string)$_GET['sub_id'])) {
        json_error('bad_request', 'Missing or non-numeric path sub_id.', 400);
    }
    return (int)$_GET['sub_id'];
}

function query_int(string $name, ?int $default = null, ?int $max = null): ?int {
    if (!isset($_GET[$name]) || $_GET[$name] === '') return $default;
    if (!ctype_digit((string)$_GET[$name])) {
        json_error('bad_request', "Query param '$name' must be an integer.", 400);
    }
    $v = (int)$_GET[$name];
    if ($max !== null && $v > $max) $v = $max;
    return $v;
}

function query_str(string $name, ?string $default = null, int $max_len = 200): ?string {
    if (!isset($_GET[$name])) return $default;
    $v = (string)$_GET[$name];
    return mb_substr($v, 0, $max_len);
}

/* ---------------------------------------------------------------------------
 * Global error handling (requirements.md §2.3 error body, §2.4 api.log)
 *
 * Without this, an uncaught exception (e.g. a DB trigger rejecting an unknown
 * type) produces a blank 500. Here we always return the standard JSON error
 * shape, log the detail + stack to api.log, and map known PostgreSQL
 * constraint/trigger violations to 409/422 instead of 500.
 * ------------------------------------------------------------------------- */

function api_log(string $summary, ?Throwable $e = null): void {
    $line = sprintf(
        "%s  %s  %s  user=%s  %s\n",
        iso_now_ms(),
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $_SERVER['REQUEST_URI'] ?? '-',
        (string) $GLOBALS['__auth_user_id'],
        $summary
    );
    if ($e !== null) {
        $line .= $e->getTraceAsString() . "\n";
    }
    @file_put_contents(maludb_log_dir() . '/api.log', $line . "\n", FILE_APPEND);
}

/** Pull the human-readable "ERROR: ..." line out of a PDO/Postgres message. */
function pg_error_message(Throwable $e): string {
    if (preg_match('/ERROR:\s*(.+?)(\n|$)/s', $e->getMessage(), $m)) {
        return trim($m[1]);
    }
    return $e->getMessage();
}

function handle_uncaught(Throwable $e): void {
    $status = 500; $code = 'internal_error'; $message = 'An unexpected error occurred.';

    if ($e instanceof PDOException) {
        $sqlstate = (is_array($e->errorInfo ?? null) && isset($e->errorInfo[0]))
            ? (string) $e->errorInfo[0]
            : substr((string) $e->getCode(), 0, 5);
        switch ($sqlstate) {
            case '23505':                       // unique_violation
                $status = 409; $code = 'conflict';          $message = pg_error_message($e); break;
            case '42501':                       // insufficient_privilege
                $status = 403; $code = 'insufficient_privilege'; $message = pg_error_message($e); break;
            case '23502': case '23503': case '23514': // not_null / fk / check
            case '22000': case '22023': case '22P02': case 'P0001': // data exception / invalid value / bad cast / trigger RAISE
                $status = 422; $code = 'validation_failed'; $message = pg_error_message($e); break;
        }
    }

    api_log(sprintf('%d %s: %s', $status, get_class($e), $e->getMessage()), $status >= 500 ? $e : null);

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => ['code' => $code, 'message' => $message]]);
    exit;
}

set_exception_handler('handle_uncaught');

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        api_log('500 fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => ['code' => 'internal_error', 'message' => 'An unexpected error occurred.']]);
    }
});
