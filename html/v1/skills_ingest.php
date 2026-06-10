<?php
/**
 * /v1/skills/ingest  (agent-skill bundle ingest — maludb_core 0.97.0)
 *
 *   POST  Body: { name (required), markdown (required — the SKILL.md text), frontmatter?,
 *                 version?, model?, preview?, materially_different?,
 *                 parent? {owner_schema, skill_id},
 *                 files? [ {relative_path, content_base64|content_text, is_executable?, media_type?} ] }
 *
 * Registers a Claude Agent Skill bundle (SKILL.md + scripts/references/assets) as an
 * immutable skill version:
 *
 *   1. decode + validate the bundle files (SKILL.md synthesized from `markdown` when absent),
 *   2. compute the canonical bundle hash (identity of the version; idempotent re-push → 200),
 *   3. resolve the parent (explicit, else the newest enabled same-name skill) and decide
 *      materiality: caller override > deterministic screens > LLM judge (gray zone),
 *   4. extract discovery subjects/verbs/keywords — via the configured per-model prompt
 *      (explicit `model` against model_prompts/the seeded catalog, or the user's stored
 *      'skill_extract' choice — like /v1/memory/ingest) or a deterministic frontmatter-only
 *      fallback that needs no credentials,
 *   5. ONE transaction: maludb_memory_ingest_extraction (graph), content-hash-deduped
 *      skill_file source packages (bytea via PDO::PARAM_LOB), maludb_skill_register.
 *
 * preview=true returns the assembled prompt (model path) or the deterministic extraction
 * (no-model path) without calling the model or writing anything.
 *
 * Registered agent skills (bundle_hash set) are content-immutable thereafter: PATCH
 * /v1/skills/{id} rejects content fields with 409; pull the bundle via GET /v1/skills/{id}/bundle.
 */

require_once __DIR__ . '/../../config/response.php';
require_once __DIR__ . '/../../config/skills.php';

// Bundle size caps (the Anthropic API caps skill uploads at 30 MB zipped; we cap the
// unpacked JSON payload in the same spirit).
const SKILL_MAX_FILE_BYTES   = 5 * 1024 * 1024;
const SKILL_MAX_BUNDLE_BYTES = 30 * 1024 * 1024;

$user_id = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('method_not_allowed', 'This endpoint supports POST.', 405);
}

/**
 * Decode the request's files[] into {relative_path, content (raw bytes), file_hash,
 * file_size, is_executable, media_type}. Accepts content_base64 (binary-safe) or
 * content_text per file. SKILL.md is synthesized from the markdown when the client
 * didn't include it, so the manifest always describes the complete, reconstructable bundle.
 */
function skills_decode_files(array $body, string $markdown): array {
    $raw = $body['files'] ?? [];
    if (!is_array($raw)) {
        json_error('validation_failed', 'Field "files" must be an array.', 422);
    }

    $files = [];
    $total = 0;
    $seen_paths = [];
    $i = -1;
    foreach ($raw as $f) {
        $i++;
        if (!is_array($f)) {
            json_error('validation_failed', "files[$i] must be an object.", 422);
        }
        $rel = trim((string) ($f['relative_path'] ?? ''));
        if ($rel === '' || str_starts_with($rel, '/') || in_array('..', explode('/', $rel), true)) {
            json_error('validation_failed', "files[$i].relative_path is missing or unsafe.", 422);
        }
        if (isset($seen_paths[$rel])) {
            json_error('validation_failed', "files[$i]: duplicate relative_path '$rel'.", 422);
        }
        $seen_paths[$rel] = true;

        if (isset($f['content_base64']) && $f['content_base64'] !== null) {
            $content = base64_decode((string) $f['content_base64'], true);   // strict
            if ($content === false) {
                json_error('validation_failed', "files[$i].content_base64 is not valid base64.", 422);
            }
        } elseif (isset($f['content_text']) && $f['content_text'] !== null) {
            $content = (string) $f['content_text'];
        } else {
            json_error('validation_failed', "files[$i] needs content_base64 or content_text.", 422);
        }

        if (strlen($content) > SKILL_MAX_FILE_BYTES) {
            json_error('payload_too_large', "files[$i] ($rel) exceeds " . SKILL_MAX_FILE_BYTES . ' bytes.', 413);
        }
        $total += strlen($content);
        if ($total > SKILL_MAX_BUNDLE_BYTES) {
            json_error('payload_too_large', 'Bundle exceeds ' . SKILL_MAX_BUNDLE_BYTES . ' bytes.', 413);
        }

        $files[] = [
            'relative_path' => $rel,
            'content'       => $content,
            'file_hash'     => skill_file_sha256($content),
            'file_size'     => strlen($content),
            'is_executable' => !empty($f['is_executable']),
            'media_type'    => !empty($f['media_type']) ? (trim((string) $f['media_type']) !== '' ? trim((string) $f['media_type']) : null) : null,
        ];
    }

    if (!isset($seen_paths['SKILL.md'])) {
        array_unshift($files, [
            'relative_path' => 'SKILL.md',
            'content'       => $markdown,
            'file_hash'     => skill_file_sha256($markdown),
            'file_size'     => strlen($markdown),
            'is_executable' => false,
            'media_type'    => 'text/markdown',
        ]);
    }
    return $files;
}

/**
 * LLM judge for the gray zone: SKILL.md body changed, nothing else did. Returns true
 * (materially different → coexist) unless the model clearly answers otherwise.
 *
 * Done with a direct cURL call rather than llm_complete(): llm_complete()'s failure path
 * is json_error() (which terminates the request), but a judge failure must NEVER hide a
 * version wrongly — any failure here returns true instead of erroring out.
 */
function skill_judge_materiality(array $pr, string $parent_markdown, string $new_markdown, string $name): bool {
    $system = 'You compare two versions of an AI agent skill (its SKILL.md instructions) and decide'
        . ' whether the revision MATERIALLY changes what the skill does: different capabilities,'
        . ' different behavior, different instructions an agent would follow. Typo fixes, rewording'
        . ' with identical meaning, and formatting changes are NOT material.'
        . ' Respond with exactly one JSON object: {"materially_different": true|false}.';
    $user = "SKILL: {$name}\n\n=== PARENT VERSION ===\n{$parent_markdown}\n\n=== NEW VERSION ===\n{$new_markdown}\n";

    $fmt   = strtolower((string) ($pr['api_format'] ?? 'openai'));
    $base  = rtrim((string) ($pr['base_url'] ?? ''), '/');
    $token = (string) ($pr['api_key'] ?? '');
    $model = ($pr['model_identifier'] ?? '') !== null && (string) ($pr['model_identifier'] ?? '') !== ''
        ? (string) $pr['model_identifier'] : (string) ($pr['model_name'] ?? '');
    $gen = (isset($pr['generation_params']) && $pr['generation_params'] !== null && $pr['generation_params'] !== '')
        ? json_decode((string) $pr['generation_params'], true) : [];
    if (!is_array($gen)) $gen = [];
    if ($base === '' || $token === '' || $model === '') return true;

    if ($fmt === 'anthropic') {
        $url     = $base . '/v1/messages';
        $headers = ['x-api-key: ' . $token, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'];
        $payload = array_merge([
            'model'      => $model,
            'max_tokens' => 64,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
        ], $gen);
    } else {
        $url     = $base . '/chat/completions';
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
        $payload = array_merge([
            'model'      => $model,
            'max_tokens' => 64,
            'messages'   => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ], $gen);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int) (getenv('MALUDB_HTTP_TIMEOUT') ?: 60),
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) return true;

    $data = json_decode((string) $body, true);
    if ($fmt === 'anthropic') {
        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) $text .= $block['text'];
        }
    } else {
        $text = $data['choices'][0]['message']['content'] ?? '';
    }
    if (!is_string($text) || $text === '') return true;

    $verdict = llm_json_from_text($text);
    if (is_array($verdict) && array_key_exists('materially_different', $verdict) && is_bool($verdict['materially_different'])) {
        return $verdict['materially_different'];
    }
    return true;
}

$body = body_json();

$name     = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
$markdown = (string) ($body['markdown'] ?? '');
if ($name === '') {
    json_error('missing_field', 'Field "name" is required.', 400);
}
if (trim($markdown) === '') {
    json_error('missing_field', 'Field "markdown" (the SKILL.md text) is required.', 400);
}

$frontmatter = (isset($body['frontmatter']) && is_array($body['frontmatter'])) ? $body['frontmatter'] : [];
$model       = (isset($body['model']) && trim((string) $body['model']) !== '') ? trim((string) $body['model']) : null;
$preview     = !empty($body['preview']);

$files         = skills_decode_files($body, $markdown);
$computed_hash = skill_bundle_hash($files);

// maludb_skill_register arrived in 0.97.0 (with the bundle schema).
$has_register = db_one("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'maludb_skill_register') AS ok");
if (!$has_register || !$has_register['ok']) {
    json_error(
        'ingest_unavailable',
        "maludb_skill_register is not available (requires maludb_core 0.97.0;"
        . " re-run enable_memory_schema('<tenant>') after upgrading).",
        501
    );
}

// Idempotent re-push: same name + bundle -> the existing version, no LLM.
$existing = db_one(
    "SELECT skill_id AS id, version FROM maludb_skill WHERE skill_name = ? AND bundle_hash = ?",
    [$name, $computed_hash]
);
if ($existing !== null && !$preview) {
    json_response([
        'skill_id'    => (int) $existing['id'],
        'version'     => $existing['version'],
        'bundle_hash' => $computed_hash,
        'reused'      => true,
    ]);
}

// Parent: explicit {owner_schema, skill_id}, else the newest enabled same-name skill
// in the tenant's own schema (the re-upload case).
$parent_schema = null;
$parent_id     = null;
$parent_note   = null;
$parent_body   = $body['parent'] ?? null;
if (is_array($parent_body) && isset($parent_body['skill_id']) && $parent_body['skill_id'] !== null) {
    $parent_schema = trim((string) ($parent_body['owner_schema'] ?? '')) !== '' ? trim((string) $parent_body['owner_schema']) : null;
    $parent_id     = (int) $parent_body['skill_id'];
    if ($parent_schema === null) {
        json_error('validation_failed', 'Field "parent.owner_schema" is required with parent.skill_id.', 422);
    }
} else {
    $auto = db_one(
        "SELECT skill_id AS id, owner_schema FROM maludb_skill
          WHERE skill_name = ? AND enabled ORDER BY skill_id DESC LIMIT 1",
        [$name]
    );
    if ($auto !== null) {
        $parent_schema = $auto['owner_schema'];
        $parent_id     = (int) $auto['id'];
        $parent_note   = 'auto_detected_same_name';
    }
}

// Materiality: explicit override > deterministic screens > LLM judge.
$materiality          = ['verdict' => 'material', 'reasons' => ['no_parent']];
$materially_different = true;
if ($parent_id !== null) {
    // \$ escaped so PHP never interpolates inside the malu$* base-table names.
    $parent_row = db_one(
        "SELECT s.markdown, s.frontmatter_jsonb,
                COALESCE((SELECT jsonb_agg(jsonb_build_object(
                              'relative_path', f.relative_path,
                              'file_hash', f.file_hash))
                            FROM maludb_core.malu\$skill_file f
                           WHERE f.owner_schema = s.owner_schema
                             AND f.skill_id = s.skill_id), '[]'::jsonb) AS files
           FROM maludb_core.malu\$skill_package s
          WHERE s.owner_schema = ? AND s.skill_id = ?",
        [$parent_schema, $parent_id]
    );
    if ($parent_row === null) {
        json_error('not_found', 'Parent skill not found.', 404);
    }
    $parent_files = is_string($parent_row['files']) ? json_decode($parent_row['files'], true) : $parent_row['files'];
    $materiality = skill_materiality_screens(
        [
            'markdown'          => $parent_row['markdown'],
            'frontmatter_jsonb' => $parent_row['frontmatter_jsonb'],
            'files'             => is_array($parent_files) ? $parent_files : [],
        ],
        $markdown, $frontmatter, $files
    );
    if (array_key_exists('materially_different', $body) && is_bool($body['materially_different'])) {
        $materially_different    = $body['materially_different'];
        $materiality['reasons'][] = 'caller_override';
    } elseif ($materiality['verdict'] === 'material') {
        $materially_different = true;
    } elseif ($materiality['verdict'] === 'non_material') {
        $materially_different = false;
    } else {   // gray zone: SKILL.md body changed, nothing else did
        $pr_judge = mem_resolve_task_config($user_id, 'skill_extract', $model);
        if ($pr_judge !== null && ($pr_judge['api_key'] ?? null) !== null && $pr_judge['api_key'] !== '') {
            $materially_different     = skill_judge_materiality($pr_judge, (string) ($parent_row['markdown'] ?? ''), $markdown, $name);
            $materiality['reasons'][] = 'llm_judged';
        } else {
            $materially_different     = true;
            $materiality['reasons'][] = 'gray_zone_default_material';
        }
    }
    $materiality['materially_different'] = $materially_different;
}

// Discovery extraction: LLM when a model is configured (explicit `model`, or the user's
// stored 'skill_extract' choice — PUT /v1/llm/models/skill_extract), else the deterministic
// frontmatter-only fallback (no credentials needed).
$frontmatter_json = $frontmatter === [] ? '{}' : json_encode($frontmatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$extraction = null;
$pr = mem_resolve_task_config($user_id, 'skill_extract', $model);
if ($model !== null && $pr === null) {
    json_error('model_not_configured', 'No prompt configured for model "' . $model . '". Set one via POST /v1/model-prompts.', 422);
}
if ($pr !== null) {
    $model = ($pr['model_name'] ?? '') !== '' && $pr['model_name'] !== null ? (string) $pr['model_name'] : $model;

    // SUBJECT TYPE CATALOG (0.96.0): render the entity/event vocabularies straight from the
    // tenant catalog (same as /v1/memory/ingest) so the prompt's allowed types never drift.
    try {
        $type_rows = db_query(
            "SELECT category, subject_type, description FROM maludb_subject_type ORDER BY category, sort_order"
        );
    } catch (PDOException $e) {
        // single-quoted on purpose: the `$` in malu$svpor_* must not be parsed as a PHP variable
        $type_rows = db_query(
            'SELECT category, subject_type, description FROM maludb_core.malu$svpor_subject_type ORDER BY category, sort_order'
        );
    }
    $entity_lines = [];
    $event_lines  = [];
    foreach ($type_rows as $r) {
        $desc = (isset($r['description']) && trim((string) $r['description']) !== '') ? ' — ' . $r['description'] : '';
        $line = '  - ' . $r['subject_type'] . $desc;
        if (($r['category'] ?? 'entity') === 'event') { $event_lines[] = $line; } else { $entity_lines[] = $line; }
    }
    $entity_block = $entity_lines !== [] ? implode("\n", $entity_lines) : '  - other';
    $event_block  = $event_lines  !== [] ? implode("\n", $event_lines)  : '  - task';

    $system = strtr($pr['system_prompt'], [
        '{{ENTITY_TYPES}}' => $entity_block,
        '{{EVENT_KINDS}}'  => $event_block,
    ]);
    $user_msg = "SKILL_NAME: {$name}\n\nFRONTMATTER:\n{$frontmatter_json}\n\nSKILL_MD:\n{$markdown}\n";

    if ($preview) {
        json_response([
            'model'         => $model,
            'system_prompt' => $system,
            'user_message'  => $user_msg,
            'bundle_hash'   => $computed_hash,
            'materiality'   => $materiality,
            'parent'        => ['owner_schema' => $parent_schema, 'skill_id' => $parent_id, 'note' => $parent_note],
        ]);
    }
    if (($pr['api_key'] ?? null) === null || $pr['api_key'] === '') {
        if (in_array($pr['source'] ?? null, ['catalog_explicit', 'user_choice'], true)) {
            $msg = 'No API key stored for provider "' . ($pr['provider'] ?? '') . '".'
                 . ' Set one via PUT /v1/llm/providers/' . ($pr['provider'] ?? '') . '.';
        } else {
            $msg = 'No API key set for model "' . $model . '".';
        }
        json_error('model_api_key_missing', $msg, 409);
    }

    $cfg = [
        'api_format'        => $pr['api_format'],
        'base_url'          => $pr['base_url'],
        'model_identifier'  => ($pr['model_identifier'] !== null && $pr['model_identifier'] !== '') ? $pr['model_identifier'] : $model,
        'token'             => $pr['api_key'],
        'max_tokens'        => (int) ($pr['max_tokens'] ?? 2048),
        'generation_params' => ($pr['generation_params'] !== null && $pr['generation_params'] !== '') ? json_decode($pr['generation_params'], true) : [],
    ];
    $extraction = llm_json_from_text(llm_complete($cfg, $system, $user_msg));
    if ($extraction === null) {
        json_error('upstream_error', 'LLM output was not a JSON object.', 502);
    }
    $extraction = skill_coerce_extraction($extraction, $name, $markdown, $frontmatter);
} else {
    $discovery  = skill_deterministic_discovery($name, $frontmatter);
    $extraction = skill_coerce_extraction(
        ['subjects' => [], 'verbs' => [], 'edges' => [], 'keywords' => $discovery['keywords']],
        $name, $markdown, $frontmatter
    );
    if ($preview) {
        json_response([
            'model'       => null,
            'extraction'  => $extraction,
            'bundle_hash' => $computed_hash,
            'materiality' => $materiality,
            'parent'      => ['owner_schema' => $parent_schema, 'skill_id' => $parent_id, 'note' => $parent_note],
        ]);
    }
}

$version = null;
if (isset($body['version']) && trim((string) $body['version']) !== '') {
    $version = trim((string) $body['version']);
} elseif (isset($frontmatter['metadata']) && is_array($frontmatter['metadata'])) {
    $v       = trim((string) ($frontmatter['metadata']['version'] ?? ''));
    $version = $v !== '' ? $v : null;
}
$description = trim((string) ($frontmatter['description'] ?? '')) !== ''
    ? trim((string) ($frontmatter['description'] ?? '')) : null;

// One transaction: graph ingest, bundle storage, skill registration.
$result = db_tx_core(function () use (
    $extraction, $files, $name, $markdown, $computed_hash, $description,
    $frontmatter_json, $version, $parent_schema, $parent_id, $materially_different
) {
    $ingest_row = db_one(
        "SELECT maludb_memory_ingest_extraction(
                    p_extraction => ?::jsonb, p_source_kind => 'document',
                    p_source_id => NULL, p_provenance => 'suggested') AS result",
        [json_encode($extraction, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
    );
    // Decode as an object so empty JSON objects in the report stay {} in the response.
    $report = $ingest_row['result'] !== null ? json_decode($ingest_row['result']) : null;

    // Subject names -> graph ids, via the report's key->id map.
    $ids = $report->ids ?? null;
    $subjects_param = [];
    foreach (($extraction['subjects'] ?? []) as $s) {
        $entry = ['name' => $s['name'] ?? null];
        $key   = $s['key'] ?? null;
        if ($key !== null && $ids !== null && isset($ids->{(string) $key})) {
            $entry['id'] = $ids->{(string) $key};
        }
        $subjects_param[] = $entry;
    }
    $verbs_param = [];
    foreach (($extraction['verbs'] ?? []) as $v) {
        if (is_array($v) && !empty($v['name'])) $verbs_param[] = ['name' => $v['name']];
    }
    $keywords = [];
    foreach (($extraction['keywords'] ?? []) as $k) {
        $k = (string) $k;
        if (trim($k) !== '') $keywords[] = $k;
    }

    // Bundle files: content-hash-deduped source packages in the tenant schema.
    // content_bytes (bytea) must bind as a LOB — db_one binds strings (and would log the raw
    // bytes), so the INSERT runs on the raw PDO handle with a manual, byte-redacted sql_log.
    $pdo = Database::getInstance()->getConnection();
    $files_param = [];
    foreach ($files as $f) {
        $sp = db_one(
            "SELECT source_package_id FROM maludb_source_package
              WHERE content_hash = ? AND source_type = 'skill_file'
              ORDER BY source_package_id LIMIT 1",
            [$f['file_hash']]
        );
        if ($sp === null) {
            $t0  = microtime(true);
            $sql = "INSERT INTO maludb_source_package
                        (source_type, content_bytes, media_type, content_size, content_hash, ingested_at)
                    VALUES ('skill_file', ?, ?, ?, ?, now()) RETURNING source_package_id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $f['content'], PDO::PARAM_LOB);
            $stmt->bindValue(2, $f['media_type'], $f['media_type'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(3, $f['file_size'], PDO::PARAM_INT);
            $stmt->bindValue(4, $f['file_hash']);
            $stmt->execute();
            $spid = (int) $stmt->fetchColumn();
            sql_log($sql, ['<' . $f['file_size'] . ' bytes>', $f['media_type'], $f['file_size'], $f['file_hash']], 1, (microtime(true) - $t0) * 1000);
        } else {
            $spid = (int) $sp['source_package_id'];
        }
        $files_param[] = [
            'relative_path'     => $f['relative_path'],
            'source_package_id' => $spid,
            'file_hash'         => $f['file_hash'],
            'file_size'         => $f['file_size'],
            'is_executable'     => $f['is_executable'],
            'media_type'        => $f['media_type'],
        ];
    }

    // text[]: a Postgres array literal ('{"a","b"}'), or NULL when empty.
    $kw_literal = $keywords === [] ? null
        : '{' . implode(',', array_map(
            fn($k) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $k) . '"', $keywords
          )) . '}';

    $reg_row = db_one(
        "SELECT maludb_skill_register(
                    p_skill_name => ?, p_markdown => ?, p_bundle_hash => ?,
                    p_description => ?, p_frontmatter => ?::jsonb, p_version => ?,
                    p_keywords => ?::text[], p_subjects => ?::jsonb, p_verbs => ?::jsonb,
                    p_files => ?::jsonb, p_parent_owner_schema => ?,
                    p_parent_skill_id => ?, p_materially_different => ?) AS result",
        [
            $name, $markdown, $computed_hash,
            $description, $frontmatter_json, $version,
            $kw_literal, json_encode($subjects_param), json_encode($verbs_param),
            json_encode($files_param), $parent_schema,
            $parent_id, $materially_different ? 'true' : 'false',
        ]
    );
    $register = $reg_row['result'] !== null ? json_decode($reg_row['result']) : null;
    return ['ingest' => $report, 'register' => $register];
});

json_response([
    'skill_id'    => $result['register']->skill_id ?? null,
    'version'     => $result['register']->version ?? null,
    'bundle_hash' => $computed_hash,
    'reused'      => (bool) ($result['register']->reused ?? false),
    'model'       => $model,
    'parent'      => ['owner_schema' => $parent_schema, 'skill_id' => $parent_id, 'note' => $parent_note],
    'materiality' => $materiality,
    'register'    => $result['register'],
    'ingest'      => $result['ingest'],
], 201);
