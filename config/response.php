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
