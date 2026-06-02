<?php
/**
 * /v1/memory/ingest  (text → LLM extraction → memory ingest; per-model prompt, OpenAI + Anthropic)
 *
 *   POST  Body: { text (required), model? (default 'chatgpt-4o'), hints? (array of
 *                 {"subject-type","subject-name"}), namespace?, preview? }
 *
 *   Contract (per the GPT-4o memory-extraction prompt): the model is given the stored SYSTEM
 *   prompt + a USER message built from the TEXT, the HINTS, and the schema's current
 *   KNOWN_SUBJECTS / KNOWN_VERBS (read from maludb_subject / maludb_verb so the model reuses
 *   canonical names). The model returns ONE JSON object {subjects, verbs, episodes, edges,
 *   relationships}; the API uploads the text as a document and passes the JSON verbatim to
 *   maludb_memory_ingest_extraction(<json>::jsonb, 'document', <document_id>).
 *
 *   preview=true returns the assembled SYSTEM + USER messages without calling the model or
 *   writing — verify the prompt / test without live model credentials.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('method_not_allowed', 'This endpoint supports POST.', 405);
}

$body = body_json();

$text = isset($body['text']) ? (string) $body['text'] : '';
if (trim($text) === '') json_error('missing_field', 'Field "text" is required.', 400);

$model     = isset($body['model']) && trim((string) $body['model']) !== '' ? (string) $body['model'] : 'chatgpt-4o';
$namespace = isset($body['namespace']) && trim((string) $body['namespace']) !== '' ? (string) $body['namespace'] : 'default';
$preview   = !empty($body['preview']);

// HINTS: a list of {"subject-type","subject-name"}. Accept an array (preferred); tolerate a
// pre-encoded JSON string; default to [].
if (isset($body['hints']) && is_array($body['hints'])) {
    $hints_json = json_encode(array_values($body['hints']));
} elseif (isset($body['hints']) && is_string($body['hints']) && trim($body['hints']) !== '') {
    $decoded = json_decode($body['hints'], true);
    $hints_json = is_array($decoded) ? json_encode($decoded) : json_encode([['subject-type' => 'note', 'subject-name' => (string) $body['hints']]]);
} else {
    $hints_json = '[]';
}

// --- per-model prompt + LLM connection (MySQL) ---
$pr = LocalDatabase::modelPrompt($model);
if ($pr === null) {
    json_error('model_not_configured', 'No prompt configured for model "' . $model . '". Set one via POST /v1/model-prompts.', 422);
}

// --- KNOWN_SUBJECTS / KNOWN_VERBS from Postgres (so the model reuses canonical names) ---
$subj_rows = db_query("SELECT canonical_name AS name, subject_type AS type FROM maludb_subject ORDER BY canonical_name");
$verb_rows = db_query("SELECT canonical_name FROM maludb_verb ORDER BY canonical_name");
$known_subjects_json = json_encode(array_map(fn($r) => ['name' => $r['name'], 'type' => $r['type']], $subj_rows), JSON_UNESCAPED_SLASHES);
$known_verbs_json    = json_encode(array_map(fn($r) => $r['canonical_name'], $verb_rows), JSON_UNESCAPED_SLASHES);

// --- build the messages ---
$system  = $pr['system_prompt'];   // stored verbatim
$user    = "TEXT:\n{$text}\n\nHINTS:\n{$hints_json}\n\nKNOWN_SUBJECTS:\n{$known_subjects_json}\n\nKNOWN_VERBS:\n{$known_verbs_json}\n";

if ($preview) {
    json_response([
        'model'         => $model,
        'api_format'    => $pr['api_format'],
        'system_prompt' => $system,
        'user_message'  => $user,
        'counts'        => ['known_subjects' => count($subj_rows), 'known_verbs' => count($verb_rows)],
    ]);
}

if ($pr['api_key'] === null || $pr['api_key'] === '') {
    json_error('model_api_key_missing', 'No API key set for model "' . $model . '". Set it via POST /v1/model-prompts.', 409);
}

// The 0.92.0 ingest facade must be present (the model JSON is passed to it verbatim).
$has_facade = db_one("SELECT to_regprocedure('maludb_memory_ingest_extraction(jsonb,text,bigint)') IS NOT NULL AS ok");
if (!$has_facade || !$has_facade['ok']) {
    json_error('ingest_unavailable', 'maludb_memory_ingest_extraction is not available in this database (requires maludb_core 0.92.0).', 501);
}

// --- call the LLM (OpenAI or Anthropic shape) and parse the extraction JSON ---
$cfg = [
    'api_format'        => $pr['api_format'],
    'base_url'          => $pr['base_url'],
    'model_identifier'  => ($pr['model_identifier'] !== null && $pr['model_identifier'] !== '') ? $pr['model_identifier'] : $model,
    'token'             => $pr['api_key'],
    'max_tokens'        => (int) $pr['max_tokens'],
    'generation_params' => ($pr['generation_params'] !== null && $pr['generation_params'] !== '') ? json_decode($pr['generation_params'], true) : [],
];
$content    = llm_complete($cfg, $system, $user);
$extraction = llm_json_from_text($content);
if ($extraction === null) {
    json_error('upstream_error', 'LLM output was not a JSON object.', 502);
}

// --- upload the text + ingest the extraction (one transaction) ---
$result = db_tx_core(function () use ($text, $extraction) {
    $doc = db_one(
        "SELECT maludb_upload_document(p_title => ?, p_content_text => ?, p_source_type => 'document') AS id",
        [mb_substr(trim($text), 0, 80), $text]
    );
    $document_id = (int) $doc['id'];
    $row = db_one(
        "SELECT maludb_memory_ingest_extraction(?::jsonb, 'document', ?) AS result",
        [json_encode($extraction), $document_id]
    );
    return ['document_id' => $document_id, 'result' => ($row['result'] !== null ? json_decode($row['result']) : null)];
});

json_response([
    'document_id' => $result['document_id'],
    'model'       => $model,
    'api_format'  => $pr['api_format'],
    'namespace'   => $namespace,
    'result'      => $result['result'],
], 201);
