<?php
/**
 * /v1/llm/catalog  (seeded LLM model catalog — models × tasks, with the caller's state)
 *
 *   GET   List the seeded default_prompts catalog: one entry per (model, task) with the
 *         connection facts (provider, model_identifier, api_format, base_url, max_tokens) plus
 *         the caller's state: key_set (a key is stored for that provider) and is_choice (the
 *         caller's choice for that task is this model). The prompt text is NOT returned (large);
 *         has_system_prompt flags it.
 *
 * Bearer-authenticated (unlike the legacy /v1/model-prompts, which requires raw Postgres
 * credentials). Config is keyed by the token's user_id, so every token a user holds shares the
 * same keys and choices.
 */

require_once __DIR__ . '/../../config/response.php';

$user_id = require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $keys = [];
        foreach (LocalDatabase::listUserProviderKeys($user_id) as $k) {
            if ($k['key_set']) $keys[$k['provider']] = true;
        }
        $choices = [];
        foreach (LocalDatabase::listUserModelChoices($user_id) as $c) {
            $choices[$c['task']] = $c['model_name'];
        }

        $models = [];
        foreach (LocalDatabase::listDefaultPrompts() as $r) {
            $models[] = [
                'provider'          => $r['provider'],
                'model_name'        => $r['model_name'],
                'model_identifier'  => $r['model_identifier'],
                'api_format'        => $r['api_format'],
                'base_url'          => $r['base_url'],
                'task'              => $r['task'],
                'max_tokens'        => (int) $r['max_tokens'],
                'has_system_prompt' => (bool) $r['has_system_prompt'],
                'key_set'           => isset($keys[$r['provider']]),
                'is_choice'         => ($choices[$r['task']] ?? null) === $r['model_name'],
            ];
        }
        json_response(['tasks' => LocalDatabase::catalogTasks(), 'models' => $models]);
    }

    default:
        header('Allow: GET');
        json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}
