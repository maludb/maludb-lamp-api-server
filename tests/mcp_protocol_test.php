<?php
/**
 * CLI protocol-layer tests for the MCP endpoint (html/mcp.php) — no Apache,
 * MySQL, or Postgres needed. Run with any PHP 8.3 CLI:
 *
 *   php tests/mcp_protocol_test.php
 *
 * Covers: the ApiException refactor (uncaught rendering byte-identical to the
 * legacy json_error output), the 8-tool registry shape, initialize version
 * negotiation, JSON-RPC dispatch (batch/-32600/-32601/notifications/id
 * fidelity), tools/call required-arg and handler-level -32602 validation,
 * ApiException -> isError mapping, the canonical SQLSTATE classification, and
 * the Origin / MCP-Protocol-Version transport guards.
 *
 * DB-backed tool behavior (find_subjects round-trips, search_memory
 * suggestions, ...) is exercised by tests/mcp_curls.sh against a live server.
 */

define('MALUDB_MCP_TESTING', true);

$root = dirname(__DIR__);

// config/response.php requires the gitignored env configs; bootstrap them from
// the examples when absent (classes only — nothing connects until used).
foreach (['database', 'local-database'] as $f) {
    if (!file_exists("$root/config/$f.php")) {
        copy("$root/config/$f-example.php", "$root/config/$f.php");
        fwrite(STDERR, "note: created config/$f.php from the example (gitignored)\n");
    }
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST']      = 'localhost';

require $root . '/html/mcp.php';

$checks = 0;
$failures = 0;
function check(string $label, bool $ok): void {
    global $checks, $failures;
    $checks++;
    if ($ok) {
        echo "ok   $label\n";
    } else {
        $failures++;
        echo "FAIL $label\n";
    }
}

/** Decode a JSON-RPC request string the way mcp_main() does (assoc=false). */
function rpc(string $json): ?array {
    return mcp_dispatch(1, json_decode($json));
}

/* ---------------------------------------------------------------------------
 * 1. ApiException refactor: an uncaught ApiException renders byte-for-byte the
 *    legacy json_error output (child process: this build's `php -r` does not
 *    fire set_exception_handler, so use a temp script file).
 * ------------------------------------------------------------------------- */

try {
    json_error('not_found', 'Nope.', 404);
    check('json_error throws (never returns)', false);
} catch (ApiException $e) {
    check('ApiException carries code/message/status', $e->apiCode === 'not_found' && $e->getMessage() === 'Nope.' && $e->status === 404);
}

$tmp = tempnam(sys_get_temp_dir(), 'mcp_apiexc') . '.php';
file_put_contents($tmp, "<?php require " . var_export("$root/config/response.php", true) . ";\n"
    . "json_error('validation_failed', 'Unknown verb \"a/b — ünïcode ☕\".', 422);\n");
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null');
unlink($tmp);
// The expected string is the LEGACY json_error encode (default flags: escaped
// slashes + \uXXXX) — pinned literally so a flag change in the renderer fails.
$want = '{"error":{"code":"validation_failed","message":"Unknown verb \"a\/b \u2014 \u00fcn\u00efcode \u2615\"."}}';
check('uncaught ApiException renders the legacy json_error body byte-for-byte', $out === $want);

/* ---------------------------------------------------------------------------
 * 2. Tool registry shape — 8 tools, titles, schemas, annotations.
 * ------------------------------------------------------------------------- */

$tools = mcp_tools();
check('registry has exactly 8 tools', count($tools) === 8);
check('registry order/names', array_map(fn($t) => $t['name'], $tools) === [
    'store_memory', 'search_memory', 'find_subjects', 'explore_subject',
    'store_document', 'get_document', 'find_skills', 'get_skill',
]);
$all_fields = true;
foreach ($tools as $t) {
    foreach (['name', 'title', 'description', 'inputSchema', 'annotations'] as $k) {
        if (!isset($t[$k])) { $all_fields = false; }
    }
    if (($t['inputSchema']['type'] ?? null) !== 'object') { $all_fields = false; }
    if (($t['inputSchema']['additionalProperties'] ?? null) !== false) { $all_fields = false; }
}
check('every tool has name/title/description/inputSchema/annotations', $all_fields);

$by_name = mcp_tools_by_name();
$ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
$wr = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
$ann_ok = true;
foreach (['search_memory', 'find_subjects', 'explore_subject', 'get_document', 'find_skills', 'get_skill'] as $n) {
    if ($by_name[$n]['annotations'] !== $ro) { $ann_ok = false; }
}
foreach (['store_memory', 'store_document'] as $n) {
    if ($by_name[$n]['annotations'] !== $wr) { $ann_ok = false; }
}
check('annotations: 6 read-only, 2 non-destructive writes', $ann_ok);

check('required args per schema', (
    $by_name['store_memory']['inputSchema']['required'] === ['text']
    && $by_name['search_memory']['inputSchema']['required'] === ['query']
    && $by_name['explore_subject']['inputSchema']['required'] === ['subject']
    && $by_name['store_document']['inputSchema']['required'] === ['title', 'text']
    && $by_name['get_document']['inputSchema']['required'] === ['document_id']
    && !isset($by_name['find_subjects']['inputSchema']['required'])
    && !isset($by_name['find_skills']['inputSchema']['required'])
    && !isset($by_name['get_skill']['inputSchema']['required'])
));
check('search_memory limit caps at 50, others at 200', (
    $by_name['search_memory']['inputSchema']['properties']['limit']['maximum'] === 50
    && $by_name['find_subjects']['inputSchema']['properties']['limit']['maximum'] === 200
    && $by_name['find_skills']['inputSchema']['properties']['limit']['maximum'] === 200
));

/* ---------------------------------------------------------------------------
 * 3. initialize — version negotiation, capabilities, serverInfo.
 * ------------------------------------------------------------------------- */

$r = mcp_handle_initialize(json_decode('{"protocolVersion":"2025-03-26"}'));
check('initialize echoes a supported requested version', $r['protocolVersion'] === '2025-03-26');
$r = mcp_handle_initialize(json_decode('{"protocolVersion":"2024-11-05"}'));
check('initialize falls back for an unknown version', $r['protocolVersion'] === '2025-06-18');
$r = mcp_handle_initialize(new stdClass());
check('initialize falls back when no version requested', $r['protocolVersion'] === '2025-06-18');
check('initialize capabilities', $r['capabilities'] === ['tools' => ['listChanged' => false]]);
check('initialize serverInfo', $r['serverInfo'] === ['name' => 'maludb', 'title' => 'MaluDB Memory', 'version' => '0.1.0']);

/* ---------------------------------------------------------------------------
 * 4. JSON-RPC dispatch — shape checks, notifications, id fidelity.
 * ------------------------------------------------------------------------- */

$r = rpc('[{"jsonrpc":"2.0","id":1,"method":"ping"}]');
check('batch -> -32600', $r['error']['code'] === -32600 && $r['id'] === null);
$r = rpc('{"jsonrpc":"1.0","id":1,"method":"ping"}');
check('jsonrpc != "2.0" -> -32600', $r['error']['code'] === -32600);
$r = rpc('"just a string"');
check('non-object body -> -32600', $r['error']['code'] === -32600);
$r = rpc('{"jsonrpc":"2.0","id":9}');
check('missing method -> -32600 with id echoed', $r['error']['code'] === -32600 && $r['id'] === 9);
$r = rpc('{"jsonrpc":"2.0","method":"notifications/initialized"}');
check('notification (no id) -> null (HTTP 202)', $r === null);
$r = rpc('{"jsonrpc":"2.0","id":4,"method":"resources/list"}');
check('unknown method -> -32601', $r['error']['code'] === -32601 && $r['id'] === 4);
$r = rpc('{"jsonrpc":"2.0","id":"abc-123","method":"ping"}');
check('string id echoed without casting', $r['id'] === 'abc-123');
$r = rpc('{"jsonrpc":"2.0","id":null,"method":"ping"}');
check('explicit null id is a request (not a notification)', is_array($r) && $r['id'] === null && isset($r['result']));
$r = rpc('{"jsonrpc":"2.0","id":7,"method":"ping"}');
check('ping -> {} (object, not array)', json_encode($r['result']) === '{}');
$r = rpc('{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{"cursor":"ignored"}}');
check('tools/list -> 8 tools, cursor ignored, no nextCursor', count($r['result']['tools']) === 8 && !isset($r['result']['nextCursor']));

/* ---------------------------------------------------------------------------
 * 5. tools/call — -32602 validation (none of these reach the database).
 * ------------------------------------------------------------------------- */

$r = rpc('{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"no_such_tool","arguments":{}}}');
check('unknown tool -> -32602', $r['error']['code'] === -32602 && str_contains($r['error']['message'], 'Unknown tool'));
$r = rpc('{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"search_memory","arguments":{}}}');
check('missing required arg -> -32602 with exact message', $r['error']['code'] === -32602
    && $r['error']['message'] === 'Missing required argument "query" for tool "search_memory".');
$r = rpc('{"jsonrpc":"2.0","id":61,"method":"tools/call","params":{"name":"store_document","arguments":{"title":"x"}}}');
check('store_document without text -> -32602', $r['error']['code'] === -32602
    && $r['error']['message'] === 'Missing required argument "text" for tool "store_document".');
$r = rpc('{"jsonrpc":"2.0","id":62,"method":"tools/call","params":{"name":"store_memory","arguments":{"text":"   "}}}');
check('store_memory blank text -> handler-level -32602', $r['error']['code'] === -32602
    && $r['error']['message'] === '"text" must be a non-empty string.');
$r = rpc('{"jsonrpc":"2.0","id":63,"method":"tools/call","params":{"name":"get_skill","arguments":{}}}');
check('get_skill cross-field rule -> -32602', $r['error']['code'] === -32602
    && $r['error']['message'] === 'Provide "skill_id" or "name".');
$r = rpc('{"jsonrpc":"2.0","id":64,"method":"tools/call","params":{"name":"get_document","arguments":{"document_id":"abc"}}}');
check('get_document non-integer id -> -32602', $r['error']['code'] === -32602
    && $r['error']['message'] === '"document_id" must be an integer.');

/* ---------------------------------------------------------------------------
 * 6. Failure shapes — ApiException -> isError result; SQLSTATE classification.
 * ------------------------------------------------------------------------- */

try {
    json_error('ambiguous_subject', 'Multiple subjects match "ora".', 422);
} catch (ApiException $e) {
    $res = mcp_error_result($e->apiCode, $e->getMessage());
    $decoded = json_decode($res['content'][0]['text'], true);
    check('ApiException -> isError result with the standard error JSON', (
        $res['isError'] === true
        && $res['content'][0]['type'] === 'text'
        && $decoded === ['error' => ['code' => 'ambiguous_subject', 'message' => 'Multiple subjects match "ora".']]
    ));
}
$res = mcp_error_result('conflict', 'duplicate key', '23505');
check('isError result carries sqlstate when given', json_decode($res['content'][0]['text'], true)['error']['sqlstate'] === '23505');

$pdo_case = function (string $sqlstate): array {
    $e = new PDOException("SQLSTATE[$sqlstate]: boom");
    $e->errorInfo = [$sqlstate, 0, 'boom'];
    return mcp_classify_pdo_error($e);
};
check('23505 -> 409 conflict', $pdo_case('23505') === [409, 'conflict', '23505']);
check('22P02 -> 422 validation_failed', $pdo_case('22P02') === [422, 'validation_failed', '22P02']);
check('42501 -> 403 insufficient_privilege', $pdo_case('42501') === [403, 'insufficient_privilege', '42501']);
check('P0001 -> 422 validation_failed', $pdo_case('P0001') === [422, 'validation_failed', 'P0001']);
check('42P01 -> 500 schema_error', $pdo_case('42P01') === [500, 'schema_error', '42P01']);
check('class fallback: 23000 -> 422 constraint_violation', $pdo_case('23000') === [422, 'constraint_violation', '23000']);
check('class fallback: 08006 -> 503 database_unavailable', $pdo_case('08006') === [503, 'database_unavailable', '08006']);
check('unknown sqlstate -> 500 internal_error', $pdo_case('ZZZZZ') === [500, 'internal_error', 'ZZZZZ']);
$e = new PDOException('driver said no');
check('no sqlstate -> 500 internal_error, null sqlstate', mcp_classify_pdo_error($e) === [500, 'internal_error', null]);

/* ---------------------------------------------------------------------------
 * 7. Transport guards — Origin (DNS rebinding) and MCP-Protocol-Version.
 * ------------------------------------------------------------------------- */

$origin_case = function (?string $origin, string $host): ?ApiException {
    if ($origin === null) { unset($_SERVER['HTTP_ORIGIN']); } else { $_SERVER['HTTP_ORIGIN'] = $origin; }
    $_SERVER['HTTP_HOST'] = $host;
    try { mcp_check_origin(); return null; } catch (ApiException $e) { return $e; }
};
check('no Origin -> allowed', $origin_case(null, 'api.example.com') === null);
$e = $origin_case('https://evil.example.net', 'api.example.com');
check('foreign Origin -> 403 origin_forbidden', $e !== null && $e->status === 403 && $e->apiCode === 'origin_forbidden');
check('localhost Origin -> allowed', $origin_case('http://localhost:5173', 'api.example.com') === null);
check('127.0.0.1 Origin -> allowed', $origin_case('http://127.0.0.1', 'api.example.com') === null);
check('same-host Origin -> allowed (port ignored)', $origin_case('https://api.example.com', 'api.example.com:8443') === null);
check('case-insensitive host match', $origin_case('https://API.Example.com', 'api.example.com') === null);
unset($_SERVER['HTTP_ORIGIN']);
$_SERVER['HTTP_HOST'] = 'localhost';

$proto_case = function (?string $v): ?ApiException {
    if ($v === null) { unset($_SERVER['HTTP_MCP_PROTOCOL_VERSION']); } else { $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] = $v; }
    try { mcp_check_protocol_version(); return null; } catch (ApiException $e) { return $e; }
};
check('no MCP-Protocol-Version header -> allowed', $proto_case(null) === null);
check('2025-06-18 -> allowed', $proto_case('2025-06-18') === null);
check('2025-03-26 -> allowed', $proto_case('2025-03-26') === null);
$e = $proto_case('2024-11-05');
check('2024-11-05 -> 400 unsupported_protocol_version', $e !== null && $e->status === 400
    && $e->apiCode === 'unsupported_protocol_version'
    && $e->getMessage() === 'Supported MCP protocol versions: 2025-03-26, 2025-06-18.');
unset($_SERVER['HTTP_MCP_PROTOCOL_VERSION']);

/* ------------------------------------------------------------------------- */

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
