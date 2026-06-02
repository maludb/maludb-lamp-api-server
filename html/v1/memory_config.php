<?php
/**
 * /v1/memory/config  (maludb_core memory — model/embedding/prompt config; endpoint group 1)
 *
 *   GET  ?namespace=default
 *        → maludb_memory_model_config(namespace) : jsonb {extraction_alias, model_identifier,
 *          provider_kind, base_url, secret_ref, embedding_model, prompt_template,
 *          generation_params, default_subject_type, default_provenance}. secret_ref is the
 *          NAME, never the token value.
 *
 *   PUT/POST  Configure the tenant+namespace: store the token encrypted (secret_set), register
 *        the provider + alias (base_url rides in the alias runtime_params), then bind the alias
 *        + prompt + embedding model + defaults (maludb_memory_set_model_config). Returns the
 *        read-back config. The whole sequence runs in one db_tx_core() transaction.
 *
 * Uses the per-tenant self-service facades (maludb_core 0.91.0): the schema-local
 * maludb_register_model_provider / maludb_register_model_alias (SECURITY DEFINER, granted to
 * maludb_memory_executor) register into the current schema — no global model-admin grant needed.
 * The token is never inlined into provider/alias rows or logs; it is stored via secret_set and
 * referenced by name. Body shape:
 *   { namespace, secret_name, token?, provider:{name,kind,adapter_name?,data_sensitivity?},
 *     alias:{name,model_identifier,context_length?,base_url}, prompt_template?, embedding_model,
 *     generation_params?, default_subject_type?, default_provenance? }
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $namespace = query_str('namespace', 'default', 120);
        $row = db_tx_core(fn() => db_one("SELECT maludb_memory_model_config(?) AS cfg", [$namespace]));
        $cfg = ($row && $row['cfg'] !== null) ? json_decode($row['cfg']) : null;
        json_response(['namespace' => $namespace, 'config' => $cfg]);
    }

    case 'PUT':
    case 'POST': {
        $body = body_json();

        $namespace   = isset($body['namespace']) && trim((string) $body['namespace']) !== '' ? (string) $body['namespace'] : 'default';
        $secret_name = isset($body['secret_name']) && trim((string) $body['secret_name']) !== '' ? (string) $body['secret_name'] : null;
        $token       = array_key_exists('token', $body) && $body['token'] !== null ? (string) $body['token'] : null;

        $provider = is_array($body['provider'] ?? null) ? $body['provider'] : [];
        $alias    = is_array($body['alias'] ?? null) ? $body['alias'] : [];

        $prov_name = isset($provider['name']) ? trim((string) $provider['name']) : '';
        $prov_kind = isset($provider['kind']) ? trim((string) $provider['kind']) : '';
        $prov_adapter = isset($provider['adapter_name']) ? (string) $provider['adapter_name'] : null;
        $prov_sens = isset($provider['data_sensitivity']) && trim((string) $provider['data_sensitivity']) !== '' ? (string) $provider['data_sensitivity'] : 'internal';

        $alias_name  = isset($alias['name']) ? trim((string) $alias['name']) : '';
        $alias_model = isset($alias['model_identifier']) ? trim((string) $alias['model_identifier']) : '';
        $alias_ctx   = isset($alias['context_length']) && $alias['context_length'] !== null ? (int) $alias['context_length'] : null;
        $base_url    = isset($alias['base_url']) ? trim((string) $alias['base_url']) : '';

        $embedding_model = isset($body['embedding_model']) ? trim((string) $body['embedding_model']) : '';
        $prompt_template = array_key_exists('prompt_template', $body) && $body['prompt_template'] !== null ? (string) $body['prompt_template'] : null;
        $gen_params      = is_array($body['generation_params'] ?? null) ? json_encode($body['generation_params']) : '{}';
        $default_subject = isset($body['default_subject_type']) && trim((string) $body['default_subject_type']) !== '' ? (string) $body['default_subject_type'] : 'other';
        $default_prov    = isset($body['default_provenance']) && trim((string) $body['default_provenance']) !== '' ? (string) $body['default_provenance'] : 'suggested';

        // ---- shape validation (no DB writes yet) ----
        if ($prov_name === '' || $prov_kind === '') json_error('missing_field', 'provider.name and provider.kind are required.', 400);
        if ($alias_name === '' || $alias_model === '') json_error('missing_field', 'alias.name and alias.model_identifier are required.', 400);
        if ($base_url === '') json_error('missing_field', 'alias.base_url is required.', 400);
        if ($embedding_model === '') json_error('missing_field', '"embedding_model" is required.', 400);
        if ($prompt_template !== null && strpos($prompt_template, '{{chunk}}') === false) {
            json_error('validation_failed', 'prompt_template must contain the {{chunk}} placeholder.', 422);
        }
        if ($token !== null && $secret_name === null) {
            json_error('missing_field', '"secret_name" is required when a token is provided.', 400);
        }

        $cfg = db_tx_core(function () use (
            $namespace, $secret_name, $token, $prov_name, $prov_kind, $prov_adapter, $prov_sens,
            $alias_name, $alias_model, $alias_ctx, $base_url, $embedding_model, $prompt_template,
            $gen_params, $default_subject, $default_prov
        ) {
            // 1. store the token encrypted (redacted from the SQL trace).
            if ($token !== null) {
                db_one_redacted(
                    "SELECT secret_id FROM maludb_core.secret_set(p_name => ?, p_kind => 'provider', p_value => ?)",
                    [$secret_name, $token],
                    [2]   // redact the token (2nd param)
                );
            }
            // 2. register the provider (per-tenant self-service facade; secret by name, never inlined).
            db_one(
                "SELECT maludb_register_model_provider(
                            p_name => ?, p_kind => ?, p_adapter_name => ?,
                            p_secret_ref => ?, p_data_sensitivity => ?) AS id",
                [$prov_name, $prov_kind, $prov_adapter, $secret_name, $prov_sens]
            );
            // 3. register the alias (per-tenant facade; base_url rides in runtime_params).
            db_one(
                "SELECT maludb_register_model_alias(
                            p_alias => ?, p_provider => ?, p_model_identifier => ?,
                            p_context_length => ?, p_runtime_params => jsonb_build_object('base_url', ?::text)) AS id",
                [$alias_name, $prov_name, $alias_model, $alias_ctx, $base_url]
            );
            // 4. bind alias + prompt + embedding + defaults for this tenant/namespace.
            db_one(
                "SELECT maludb_memory_set_model_config(
                            p_extraction_alias     => ?,
                            p_prompt_template      => ?,
                            p_embedding_model      => ?,
                            p_namespace            => ?,
                            p_generation_params    => ?::jsonb,
                            p_default_subject_type => ?,
                            p_default_provenance   => ?) AS cfg",
                [$alias_name, $prompt_template, $embedding_model, $namespace, $gen_params, $default_subject, $default_prov]
            );
            // 5. read it back.
            $row = db_one("SELECT maludb_memory_model_config(?) AS cfg", [$namespace]);
            return ($row && $row['cfg'] !== null) ? json_decode($row['cfg']) : null;
        });

        json_response(['namespace' => $namespace, 'config' => $cfg], 200);
    }

    default:
        header('Allow: GET, POST, PUT');
        json_error('method_not_allowed', 'This endpoint supports GET, POST and PUT.', 405);
}
