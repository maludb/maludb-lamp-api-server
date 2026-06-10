<?php
/**
 * /v1/memory/ingest  (text → LLM extraction → memory ingest; per-model prompt, OpenAI + Anthropic)
 *
 *   POST  Body: { text (required), model?, hints? (array of
 *                 {"subject-type","subject-name"}), namespace?, preview? }
 *
 *   Model resolution order: explicit `model` (legacy model_prompts first, then the seeded
 *   default_prompts catalog + the caller's provider key) → the user's 'extract' choice
 *   (PUT /v1/llm/models/extract) → the legacy default (the 'chatgpt-4o' model_prompts row) →
 *   the namespace config (Store A; connection only, paired with the default ingest prompt).
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
 *
 *   The pipeline body lives in mem_ingest_core() (config/memory_core.php), shared with the
 *   MCP store_memory tool (html/mcp.php). This file parses/validates and emits the response.
 */

require_once __DIR__ . '/../../config/response.php';
require_once __DIR__ . '/../../config/memory_core.php';

$user_id = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('method_not_allowed', 'This endpoint supports POST.', 405);
}

$body = body_json();

$text = isset($body['text']) ? (string) $body['text'] : '';
if (trim($text) === '') json_error('missing_field', 'Field "text" is required.', 400);

$explicit_model = isset($body['model']) && trim((string) $body['model']) !== '' ? trim((string) $body['model']) : null;
$namespace      = isset($body['namespace']) && trim((string) $body['namespace']) !== '' ? (string) $body['namespace'] : 'default';
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

$payload = mem_ingest_core($user_id, [
    'text'           => $text,
    'hints_json'     => $hints_json,
    'namespace'      => $namespace,
    'explicit_model' => $explicit_model,
    'preview'        => $preview,
]);

json_response($payload, $preview ? 200 : 201);
