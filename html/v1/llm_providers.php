<?php
/**
 * /v1/llm/providers and /v1/llm/providers/{provider}  (the caller's LLM provider API keys)
 *
 *   GET    /v1/llm/providers             List the caller's stored providers — the key value is
 *                                        never returned, only key_set (+ optional base_url).
 *   PUT    /v1/llm/providers/{provider}  Store/update a key. Body: { api_key, base_url? }.
 *                                        api_key is required on first set (400 missing_field);
 *                                        omitting it on update preserves the stored key
 *                                        (COALESCE, same convention as /v1/model-prompts).
 *                                        Unknown provider → 422 listing the known providers.
 *   DELETE /v1/llm/providers/{provider}  Remove the caller's key; 404 when none stored.
 *
 * Bearer-authenticated; config is keyed by the token's user_id (all of a user's tokens share
 * the same keys). The provider path segment arrives as ?provider= via html/.htaccess.
 */

require_once __DIR__ . '/../../config/response.php';

$user_id  = require_auth();
$provider = isset($_GET['provider']) && trim((string) $_GET['provider']) !== ''
    ? strtolower(trim((string) $_GET['provider'])) : null;

if ($provider === null) {

    // ---- collection: /v1/llm/providers ----
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        json_error('method_not_allowed', 'This endpoint supports GET.', 405);
    }
    json_response(['providers' => LocalDatabase::listUserProviderKeys($user_id)]);
}

// ---- item: /v1/llm/providers/{provider} ----
switch ($_SERVER['REQUEST_METHOD']) {

    case 'PUT': {
        $body = body_json();

        $known = LocalDatabase::catalogProviders();
        if (!in_array($provider, $known, true)) {
            json_error(
                'validation_failed',
                'Unknown provider "' . $provider . '". Known providers: ' . implode(', ', $known) . '.',
                422
            );
        }

        $api_key  = isset($body['api_key']) && $body['api_key'] !== null && $body['api_key'] !== ''
            ? (string) $body['api_key'] : null;
        $base_url = (isset($body['base_url']) && is_string($body['base_url']) && trim($body['base_url']) !== '')
            ? trim($body['base_url']) : null;

        $existing = LocalDatabase::userProviderKey($user_id, $provider);
        if ($api_key === null && $existing === null) {
            json_error('missing_field', '"api_key" is required when storing a new provider key.', 400);
        }

        // NULL api_key on update preserves the stored key (COALESCE in the upsert).
        LocalDatabase::upsertUserProviderKey($user_id, $provider, $api_key, $base_url);

        $row = null;
        foreach (LocalDatabase::listUserProviderKeys($user_id) as $r) {
            if ($r['provider'] === $provider) { $row = $r; break; }
        }
        json_response(['provider' => [
            'provider' => $provider,
            'key_set'  => (bool) ($row['key_set'] ?? false),
            'base_url' => $row['base_url'] ?? null,
        ]]);
    }

    case 'DELETE': {
        if (!LocalDatabase::deleteUserProviderKey($user_id, $provider)) {
            json_error('not_found', 'No stored key for provider "' . $provider . '".', 404);
        }
        json_response(['deleted' => true, 'provider' => $provider]);
    }

    default:
        header('Allow: PUT, DELETE');
        json_error('method_not_allowed', 'This endpoint supports PUT and DELETE.', 405);
}
