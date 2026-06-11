<?php
/**
 * /v1/memory/documents  (maludb_core memory — process a document; endpoint group 2)
 *
 *   POST  Upload a document/transcript, extract SVPO edges, embed them, and ingest them into
 *         the graph-bound vector store. The API is the model worker: it chunks the text, calls
 *         the LLM (extraction) and the embedding model, then writes back via the facades.
 *
 *   Body: { title (req), text (req), source_type='document', media_type?, document_type?,
 *           projects?[], subjects?[], verbs?[], events?[], metadata?{}, namespace='default',
 *           embedding_model?, model?, chunk?:{max,overlap},
 *           edges?[] }    // optional pre-extracted candidate_edges → bypass the LLM call
 *
 *   Extraction connection: the namespace config (Store A) first; else borrowed from Store B
 *   (explicit `model` → the user's 'extract' choice → the legacy 'chatgpt-4o' model_prompts
 *   row) — connection only, the candidate_edges prompt_template never crosses over. Embedding
 *   model precedence: body > namespace config > the user's 'embed' choice > env default.
 *
 *   Pipeline: read config → chunk (in code) → extract (LLM, or use body.edges) → embed each
 *   edge → ONE db_tx_core(): maludb_upload_document(...) then maludb_memory_ingest_edge(...) per
 *   edge (atomic per document; HTTP done before the tx opens). Extraction edges default to
 *   provenance='suggested' (review queue).
 *
 *   No live model creds? mem_embed() falls back to a deterministic embedding and you can supply
 *   "edges" directly — so the upload→ingest→search pipeline round-trips without a model.
 *
 *   The pipeline body lives in mem_documents_core() (config/memory_core.php), shared with the
 *   MCP store_document tool (html/mcp.php). This file parses/validates and emits the response.
 */

require_once __DIR__ . '/../../config/response.php';
require_once __DIR__ . '/../../config/memory_core.php';

$user_id = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('method_not_allowed', 'This endpoint supports POST.', 405);
}

$body = body_json();

$title = isset($body['title']) ? trim((string) $body['title']) : '';
$text  = isset($body['text'])  ? (string) $body['text'] : '';
if ($title === '') json_error('missing_field', 'Field "title" is required.', 400);
if (trim($text) === '') json_error('missing_field', 'Field "text" is required.', 400);

$to_text_array = static function ($v): array {
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $s) { if (is_string($s) && trim($s) !== '') $out[] = trim($s); }
    return $out;
};

$payload = mem_documents_core($user_id, [
    'title'           => $title,
    'text'            => $text,
    'source_type'     => isset($body['source_type']) && trim((string) $body['source_type']) !== '' ? (string) $body['source_type'] : 'document',
    'media_type'      => isset($body['media_type']) && $body['media_type'] !== null ? (string) $body['media_type'] : null,
    'document_type'   => isset($body['document_type']) && trim((string) $body['document_type']) !== '' ? (string) $body['document_type'] : null,
    'metadata_json'   => is_array($body['metadata'] ?? null) ? json_encode($body['metadata']) : '{}',
    'projects'        => $to_text_array($body['projects'] ?? null),
    'subjects'        => $to_text_array($body['subjects'] ?? null),
    'verbs'           => $to_text_array($body['verbs'] ?? null),
    'events'          => $to_text_array($body['events'] ?? null),
    'chunk_max'       => isset($body['chunk']['max']) ? max(200, (int) $body['chunk']['max']) : 2000,
    'chunk_overlap'   => isset($body['chunk']['overlap']) ? max(0, (int) $body['chunk']['overlap']) : 200,
    'embedding_model' => isset($body['embedding_model']) && trim((string) $body['embedding_model']) !== ''
        ? (string) $body['embedding_model'] : null,
    'explicit_model'  => isset($body['model']) && trim((string) $body['model']) !== '' ? trim((string) $body['model']) : null,
    'provided_edges'  => is_array($body['edges'] ?? null) ? $body['edges'] : null,
    'namespace'       => isset($body['namespace']) && trim((string) $body['namespace']) !== '' ? (string) $body['namespace'] : 'default',
]);

json_response($payload, 201);
