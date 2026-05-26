<?php
/**
 * /v1/episodes  (requirements.md §4.9)
 *
 *   POST   Start an episode (activity). Returns the created episode (201).
 *
 * v1 is POST-only (§4.9). Created via the DB helper maludb_core.register_episode(...).
 * That helper is SECURITY INVOKER and derives owner_schema from current_schema(), so we
 * run it with search_path = "public, maludb_core" (public first → the episode is owned by
 * the tenant schema; maludb_core in path → the helper can resolve its base tables).
 *
 * Body (this endpoint defines the contract; §4.9/§6 left it open):
 *   { title (required), summary?, kind? (default 'activity'),
 *     payload? (object), occurred_at?, occurred_until?, sensitivity? (default 'internal') }
 * sensitivity ∈ {public,internal,restricted,prohibited} (DB-enforced → 422).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('method_not_allowed', 'This endpoint supports POST only.', 405);
}

$body = body_json();

$title = trim((string) ($body['title'] ?? ''));
if ($title === '') {
    json_error('missing_field', 'Field "title" is required.', 400);
}
$kind          = isset($body['kind'])    && trim((string) $body['kind'])    !== '' ? (string) $body['kind']    : 'activity';
$summary       = isset($body['summary']) ? (string) $body['summary'] : null;
$occurred_at   = isset($body['occurred_at'])   ? (string) $body['occurred_at']   : null;
$occurred_until= isset($body['occurred_until']) ? (string) $body['occurred_until'] : null;
$sensitivity   = isset($body['sensitivity']) && trim((string) $body['sensitivity']) !== '' ? (string) $body['sensitivity'] : 'internal';
$payload_json  = isset($body['payload']) && is_array($body['payload']) ? json_encode($body['payload']) : '{}';

$pdo = Database::getInstance()->getConnection();
try {
    $pdo->beginTransaction();
    $pdo->exec("SET LOCAL search_path TO public, maludb_core");

    $sql  = "SELECT register_episode(?, ?, ?, ?::jsonb, ?::timestamptz, ?::timestamptz, ?) AS id";
    $args = [$kind, $title, $summary, $payload_json, $occurred_at, $occurred_until, $sensitivity];
    $t0   = microtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $id   = (int) $stmt->fetchColumn();
    sql_log($sql, $args, 1, (microtime(true) - $t0) * 1000);

    $t1   = microtime(true);
    $rsql = "SELECT episode_id AS id, episode_kind AS kind, title, summary,
                    occurred_at, occurred_until, sensitivity, lifecycle_state, created_at
               FROM maludb_core.\"malu\$episode_object\"
              WHERE episode_id = ?";
    $rstmt = $pdo->prepare($rsql);
    $rstmt->execute([$id]);
    $episode = $rstmt->fetch();
    sql_log($rsql, [$id], $episode ? 1 : 0, (microtime(true) - $t1) * 1000);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e; // global handler maps DB constraint violations → 422 / etc.
}

$episode['id'] = (int) $episode['id'];
json_response(['episode' => $episode], 201);
