<?php
/**
 * /v1/llm/models and /v1/llm/models/{task}  (the caller's task → model choices)
 *
 *   GET    /v1/llm/models        One entry per known task with the EFFECTIVE model:
 *                                {task, model_name, provider, chosen, system_prompt_override}.
 *                                chosen=false rows show the legacy/server default ('chatgpt-4o'
 *                                for extract; deterministic/env for skill_extract and embed →
 *                                model_name:null, provider:null).
 *   PUT    /v1/llm/models/{task} Choose a model for a task. Body: { model_name, system_prompt? }.
 *                                (model_name, task) must exist in the catalog → 422 pointing at
 *                                GET /v1/llm/catalog. Allowed before a key is stored — the
 *                                response carries a warning when the provider has no key.
 *   DELETE /v1/llm/models/{task} Revert the task to the server default; 404 when no row.
 *
 * Bearer-authenticated; config is keyed by the token's user_id. The task path segment arrives
 * as ?task= via html/.htaccess.
 */

require_once __DIR__ . '/../../config/response.php';

// The task every legacy deployment implicitly has: /v1/memory/ingest defaults to the
// 'chatgpt-4o' model_prompts row when no choice is set.
const LLM_LEGACY_EXTRACT_DEFAULT = 'chatgpt-4o';

$user_id = require_auth();
$task    = isset($_GET['task']) && trim((string) $_GET['task']) !== ''
    ? strtolower(trim((string) $_GET['task'])) : null;

if ($task === null) {

    // ---- collection: /v1/llm/models ----
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        json_error('method_not_allowed', 'This endpoint supports GET.', 405);
    }

    $chosen = [];
    foreach (LocalDatabase::listUserModelChoices($user_id) as $c) {
        $chosen[$c['task']] = $c;
    }
    $catalog = [];
    foreach (LocalDatabase::listDefaultPrompts() as $r) {
        $catalog[$r['model_name'] . "\0" . $r['task']] = $r;
    }

    $models = [];
    foreach (LocalDatabase::catalogTasks() as $t) {
        $c = $chosen[$t] ?? null;
        if ($c !== null) {
            $row = $catalog[$c['model_name'] . "\0" . $t] ?? null;
            $models[] = [
                'task'                   => $t,
                'model_name'             => $c['model_name'],
                'provider'               => $row !== null ? $row['provider'] : null,
                'chosen'                 => true,
                'system_prompt_override' => $c['system_prompt_override'],
            ];
        } elseif ($t === 'extract') {
            // No choice: ingest falls back to the legacy model_prompts default.
            $models[] = [
                'task'                   => $t,
                'model_name'             => LLM_LEGACY_EXTRACT_DEFAULT,
                'provider'               => null,
                'chosen'                 => false,
                'system_prompt_override' => false,
            ];
        } else {
            // skill_extract: deterministic fallback; embed: env/deterministic.
            $models[] = [
                'task'                   => $t,
                'model_name'             => null,
                'provider'               => null,
                'chosen'                 => false,
                'system_prompt_override' => false,
            ];
        }
    }
    json_response(['models' => $models]);
}

// ---- item: /v1/llm/models/{task} ----
switch ($_SERVER['REQUEST_METHOD']) {

    case 'PUT': {
        $body = body_json();

        $model_name = isset($body['model_name']) ? trim((string) $body['model_name']) : '';
        if ($model_name === '') {
            json_error('missing_field', '"model_name" is required.', 400);
        }
        $system_prompt = isset($body['system_prompt']) && $body['system_prompt'] !== null && $body['system_prompt'] !== ''
            ? (string) $body['system_prompt'] : null;

        $row = LocalDatabase::defaultPrompt($model_name, $task);
        if ($row === null) {
            json_error(
                'validation_failed',
                'Unknown model "' . $model_name . '" for task "' . $task . '". See GET /v1/llm/catalog.',
                422
            );
        }

        LocalDatabase::upsertUserModelChoice($user_id, $task, $model_name, $system_prompt);

        $out = [
            'task'                   => $task,
            'model_name'             => $model_name,
            'provider'               => $row['provider'],
            'system_prompt_override' => $system_prompt !== null,
        ];
        $key = LocalDatabase::userProviderKey($user_id, (string) $row['provider']);
        $out['key_set'] = $key !== null && ($key['api_key'] ?? '') !== '' && $key['api_key'] !== null;
        if (!$out['key_set']) {
            $out['warning'] = 'No API key stored for provider "' . $row['provider'] . '".'
                . ' Set one via PUT /v1/llm/providers/' . $row['provider'] . '.';
        }
        json_response(['choice' => $out]);
    }

    case 'DELETE': {
        if (!LocalDatabase::deleteUserModelChoice($user_id, $task)) {
            json_error('not_found', 'No model choice stored for task "' . $task . '".', 404);
        }
        json_response(['deleted' => true, 'task' => $task]);
    }

    default:
        header('Allow: PUT, DELETE');
        json_error('method_not_allowed', 'This endpoint supports PUT and DELETE.', 405);
}
