<?php
/**
 * /v1/model-prompts  (manage the per-model extraction prompts in MySQL `model_prompts`)
 *
 *   POST  Upsert a model's prompt + LLM connection. Body: { pg_dbname, pg_user, pg_password
 *         (authorization), model_name, api_format ('openai'|'anthropic'), system_prompt, base_url,
 *         api_key?, max_tokens? }. Returns the row (api_key masked). Omitting api_key on update
 *         keeps the existing key.
 *   GET   List the configured model prompts (api_key never returned — only api_key_set).
 *
 * Authorization is the Postgres login (same as /v1/tokens): supply pg_dbname/pg_user/pg_password;
 * the API verifies them by connecting. These endpoints operate on the local MySQL store.
 */

require_once __DIR__ . '/../../config/response.php';

/** Verify the Postgres login supplied in the body (authorization for managing prompts). */
function model_prompts_authorize(array $body): void {
    $db   = isset($body['pg_dbname']) ? trim((string) $body['pg_dbname']) : '';
    $user = isset($body['pg_user'])   ? trim((string) $body['pg_user'])   : '';
    $pass = array_key_exists('pg_password', $body) ? (string) $body['pg_password'] : '';
    if ($db === '' || $user === '' || $pass === '') {
        json_error('missing_field', 'pg_dbname, pg_user and pg_password are required.', 400);
    }
    if (!Database::testCredentials($db, $user, $pass)) {
        json_error('pg_auth_failed', 'Could not connect to Postgres with the supplied credentials.', 403);
    }
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'POST': {
        $body = body_json();
        model_prompts_authorize($body);

        $model_name = isset($body['model_name']) ? trim((string) $body['model_name']) : '';
        $api_format = isset($body['api_format']) ? strtolower(trim((string) $body['api_format'])) : '';
        $system     = isset($body['system_prompt']) ? (string) $body['system_prompt'] : '';
        $base_url   = isset($body['base_url']) ? trim((string) $body['base_url']) : '';
        $api_key    = array_key_exists('api_key', $body) && $body['api_key'] !== null && $body['api_key'] !== '' ? (string) $body['api_key'] : null;
        $max_tokens = (isset($body['max_tokens']) && is_int($body['max_tokens']) && $body['max_tokens'] > 0) ? (int) $body['max_tokens'] : 2048;

        if ($model_name === '')  json_error('missing_field', '"model_name" is required.', 400);
        if ($system === '')      json_error('missing_field', '"system_prompt" is required.', 400);
        if ($base_url === '')    json_error('missing_field', '"base_url" is required.', 400);
        if (!in_array($api_format, ['openai', 'anthropic'], true)) {
            json_error('validation_failed', '"api_format" must be "openai" or "anthropic".', 422);
        }

        $stmt = LocalDatabase::getInstance()->getConnection()->prepare(
            "INSERT INTO model_prompts (model_name, api_format, system_prompt, base_url, api_key, max_tokens)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE api_format=VALUES(api_format), system_prompt=VALUES(system_prompt),
               base_url=VALUES(base_url), max_tokens=VALUES(max_tokens),
               api_key=COALESCE(VALUES(api_key), api_key)"
        );
        $stmt->execute([$model_name, $api_format, $system, $base_url, $api_key, $max_tokens]);

        $pr = LocalDatabase::modelPrompt($model_name);
        json_response(['model_prompt' => [
            'model_name'    => $pr['model_name'],
            'api_format'    => $pr['api_format'],
            'base_url'      => $pr['base_url'],
            'max_tokens'    => (int) $pr['max_tokens'],
            'api_key_set'   => $pr['api_key'] !== null && $pr['api_key'] !== '',
            'system_prompt' => $pr['system_prompt'],
        ]], 200);
    }

    case 'GET': {
        model_prompts_authorize(body_json());
        $rows = LocalDatabase::getInstance()->getConnection()->query(
            "SELECT model_name, api_format, base_url, max_tokens,
                    (api_key IS NOT NULL AND api_key <> '') AS api_key_set, updated_at, system_prompt
               FROM model_prompts ORDER BY model_name"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['max_tokens']  = (int) $r['max_tokens'];
            $r['api_key_set'] = (bool) $r['api_key_set'];
        }
        unset($r);
        json_response(['model_prompts' => $rows]);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
