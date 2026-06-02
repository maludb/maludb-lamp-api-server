<?php
/**
 * /v1/tokens  (local MySQL auth store — token issuance & listing)
 *
 *   POST  Generate a new API token and store its row in the local `users` table.
 *         Authorization is the Postgres login itself: the caller MUST supply working
 *         pg_dbname/pg_user/pg_password — we verify them by connecting to Postgres, and only
 *         then mint the token. The plaintext token is returned ONCE (only its sha256 hash is
 *         stored). Body: { pg_dbname, pg_user, pg_password (required), role?, user_id?,
 *         expires_in_days?, device_name? }.
 *
 *   GET   List the tokens that use a given Postgres connection (metadata only — never the token
 *         value or the password). Same authorization: body { pg_dbname, pg_user, pg_password }.
 *
 * This endpoint does NOT call require_auth() — it operates on the local MySQL store and proves
 * authorization by connecting to Postgres with the supplied credentials. DB_HOST/DB_PORT are the
 * fixed deployment values (config/database.php); only db/user/pass come from the request.
 */

require_once __DIR__ . '/../../config/response.php';

/** Pull + validate the Postgres connection triple from the body; verify it actually connects. */
function tokens_authorize(array $body): array {
    $db   = isset($body['pg_dbname'])   ? trim((string) $body['pg_dbname'])   : '';
    $user = isset($body['pg_user'])     ? trim((string) $body['pg_user'])     : '';
    $pass = array_key_exists('pg_password', $body) ? (string) $body['pg_password'] : '';
    if ($db === '' || $user === '' || $pass === '') {
        json_error('missing_field', 'pg_dbname, pg_user and pg_password are required.', 400);
    }
    if (!Database::testCredentials($db, $user, $pass)) {
        json_error('pg_auth_failed', 'Could not connect to Postgres with the supplied credentials.', 403);
    }
    return [$db, $user, $pass];
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'POST': {
        $body = body_json();
        [$db, $user, $pass] = tokens_authorize($body);

        $role        = isset($body['role']) && trim((string) $body['role']) !== '' ? (string) $body['role'] : 'executor';
        $device_name = isset($body['device_name']) && trim((string) $body['device_name']) !== '' ? (string) $body['device_name'] : null;
        $user_id     = (isset($body['user_id']) && is_int($body['user_id'])) ? (int) $body['user_id'] : LocalDatabase::nextUserId();

        $expires_at = null;
        if (isset($body['expires_in_days']) && $body['expires_in_days'] !== null) {
            if (!is_int($body['expires_in_days']) || $body['expires_in_days'] <= 0) {
                json_error('validation_failed', '"expires_in_days" must be a positive integer.', 422);
            }
            $expires_at = gmdate('Y-m-d H:i:s', time() + $body['expires_in_days'] * 86400);
        }

        // Generate the token: malu_<base64url(32 random bytes)>. Store only the sha256 of the part
        // after the prefix (matches require_auth's hashing) + a short prefix for diagnostics.
        $raw    = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token  = 'malu_' . $raw;
        $hash   = hash('sha256', $raw);
        $prefix = substr($raw, 0, 8);

        $stmt = LocalDatabase::getInstance()->getConnection()->prepare(
            "INSERT INTO users (token_hash, token_prefix, user_id, role, pg_dbname, pg_user, pg_password, expires_at, device_name)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([$hash, $prefix, $user_id, $role, $db, $user, $pass, $expires_at, $device_name]);
        $id = (int) LocalDatabase::getInstance()->getConnection()->lastInsertId();

        json_response([
            'token'       => $token,   // shown ONCE — not recoverable later
            'id'          => $id,
            'user_id'     => $user_id,
            'role'        => $role,
            'pg_dbname'   => $db,
            'pg_user'     => $user,
            'expires_at'  => $expires_at,
            'device_name' => $device_name,
        ], 201);
    }

    case 'GET': {
        $body = body_json();
        [$db, $user] = tokens_authorize($body);

        $stmt = LocalDatabase::getInstance()->getConnection()->prepare(
            "SELECT id, token_prefix, user_id, role, pg_dbname, pg_user, expires_at, device_name, created_at
               FROM users
              WHERE pg_dbname = ? AND pg_user = ?
              ORDER BY id"
        );
        $stmt->execute([$db, $user]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['user_id'] = (int) $r['user_id']; }
        unset($r);

        json_response(['tokens' => $rows]);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
