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
 * Auth (requirements.md §1.4) — adapted to the live api_tokens schema
 * (validates against expires_at; the spec's revoked_at column does not exist).
 * ------------------------------------------------------------------------- */

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
    $row  = db_one(
        'SELECT user_id FROM api_tokens WHERE token_hash = ? AND expires_at > now()',
        [$hash]
    );
    if ($row === null) {
        json_error('auth_invalid', 'Invalid or expired API token.', 401);
    }
    return $GLOBALS['__auth_user_id'] = (int)$row['user_id'];
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
