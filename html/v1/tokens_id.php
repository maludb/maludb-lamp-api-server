<?php
/**
 * /v1/tokens/{id}  (local MySQL auth store — token revocation)
 *
 *   DELETE  Revoke (delete) a token row. Authorization is the Postgres login: the caller must
 *           supply working pg_dbname/pg_user/pg_password (verified by connecting), and the token
 *           being revoked must belong to that same Postgres connection — so you can only revoke
 *           tokens for a connection whose password you know. Body: { pg_dbname, pg_user, pg_password }.
 */

require_once __DIR__ . '/../../config/response.php';

$id = path_id();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    header('Allow: DELETE');
    json_error('method_not_allowed', 'This endpoint supports DELETE.', 405);
}

$body = body_json();
$db   = isset($body['pg_dbname']) ? trim((string) $body['pg_dbname']) : '';
$user = isset($body['pg_user'])   ? trim((string) $body['pg_user'])   : '';
$pass = array_key_exists('pg_password', $body) ? (string) $body['pg_password'] : '';
if ($db === '' || $user === '' || $pass === '') {
    json_error('missing_field', 'pg_dbname, pg_user and pg_password are required.', 400);
}
if (!Database::testCredentials($db, $user, $pass)) {
    json_error('pg_auth_failed', 'Could not connect to Postgres with the supplied credentials.', 403);
}

$conn = LocalDatabase::getInstance()->getConnection();
$row  = $conn->prepare("SELECT pg_dbname, pg_user FROM users WHERE id = ?");
$row->execute([$id]);
$found = $row->fetch();
if ($found === false) {
    json_error('not_found', 'Token not found.', 404);
}
// Only allow revoking a token that belongs to the connection the caller authenticated with.
if ($found['pg_dbname'] !== $db || $found['pg_user'] !== $user) {
    json_error('forbidden', 'This token does not belong to the supplied Postgres connection.', 403);
}

$del = $conn->prepare("DELETE FROM users WHERE id = ?");
$del->execute([$id]);

json_response(['deleted' => true, 'id' => $id]);
