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
 *           embedding_model?, chunk?:{max,overlap},
 *           edges?[] }    // optional pre-extracted candidate_edges → bypass the LLM call
 *
 *   Pipeline: read config → chunk (in code) → extract (LLM, or use body.edges) → embed each
 *   edge → ONE db_tx_core(): maludb_upload_document(...) then maludb_memory_ingest_edge(...) per
 *   edge (atomic per document; HTTP done before the tx opens). Extraction edges default to
 *   provenance='suggested' (review queue).
 *
 *   No live model creds? mem_embed() falls back to a deterministic embedding and you can supply
 *   "edges" directly — so the upload→ingest→search pipeline round-trips without a model.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('method_not_allowed', 'This endpoint supports POST.', 405);
}

$body = body_json();

$title = isset($body['title']) ? trim((string) $body['title']) : '';
$text  = isset($body['text'])  ? (string) $body['text'] : '';
if ($title === '') json_error('missing_field', 'Field "title" is required.', 400);
if (trim($text) === '') json_error('missing_field', 'Field "text" is required.', 400);

$namespace   = isset($body['namespace']) && trim((string) $body['namespace']) !== '' ? (string) $body['namespace'] : 'default';
$source_type = isset($body['source_type']) && trim((string) $body['source_type']) !== '' ? (string) $body['source_type'] : 'document';
$media_type  = isset($body['media_type']) && $body['media_type'] !== null ? (string) $body['media_type'] : null;
$doc_type    = isset($body['document_type']) && trim((string) $body['document_type']) !== '' ? (string) $body['document_type'] : null;
$metadata    = is_array($body['metadata'] ?? null) ? json_encode($body['metadata']) : '{}';

$to_text_array = static function ($v): array {
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $s) { if (is_string($s) && trim($s) !== '') $out[] = trim($s); }
    return $out;
};
$projects = $to_text_array($body['projects'] ?? null);
$subjects = $to_text_array($body['subjects'] ?? null);
$verbs    = $to_text_array($body['verbs'] ?? null);
$events   = $to_text_array($body['events'] ?? null);

$chunk_max     = isset($body['chunk']['max']) ? max(200, (int) $body['chunk']['max']) : 2000;
$chunk_overlap = isset($body['chunk']['overlap']) ? max(0, (int) $body['chunk']['overlap']) : 200;

// --- config (may be empty if no model is bound yet) ---
$row = db_tx_core(fn() => db_one("SELECT maludb_memory_model_config(?) AS cfg", [$namespace]));
$cfg = ($row && $row['cfg'] !== null) ? (array) json_decode($row['cfg'], true) : [];

$embedding_model = isset($body['embedding_model']) && trim((string) $body['embedding_model']) !== ''
    ? (string) $body['embedding_model']
    : ($cfg['embedding_model'] ?? (getenv('MALUDB_EMBED_MODEL') ?: 'maludb-local-dev'));
$default_subject = $cfg['default_subject_type'] ?? 'other';
$default_prov    = $cfg['default_provenance'] ?? 'suggested';
$model_id        = $cfg['model_identifier'] ?? '';

// extraction config for the (real) LLM call
$extract_cfg = [
    'base_url'          => $cfg['base_url'] ?? '',
    'model_identifier'  => $model_id,
    'prompt_template'   => $cfg['prompt_template'] ?? null,
    'generation_params' => $cfg['generation_params'] ?? [],
    'token'             => mem_resolve_token($cfg['secret_ref'] ?? null),
];
// embedding config (embedding endpoint comes from env; DB config carries only the model label)
$embed_cfg = ['embedding_model' => $embedding_model];

// --- 1. obtain candidate edges: caller-supplied (bypass) OR LLM extraction per chunk ---
$provided = is_array($body['edges'] ?? null) ? $body['edges'] : null;
$chunks   = mem_chunk($text, $chunk_max, $chunk_overlap);

$edges = [];
$extractor = 'provided';
if ($provided !== null) {
    foreach ($provided as $e) { if (is_array($e)) $edges[] = $e; }
} else {
    $extractor = 'llm';
    foreach ($chunks as $chunk) {
        foreach (mem_extract($chunk, $extract_cfg) as $e) {
            if (is_array($e)) {
                if (!isset($e['source_span']) || trim((string) $e['source_span']) === '') $e['source_span'] = $chunk;
                $edges[] = $e;
            }
        }
    }
}
if (!$edges) {
    json_error('no_edges', 'No SVPO edges to ingest (supply "edges" or configure an extraction model).', 422);
}

// --- 2. embed each edge (HTTP if configured, else deterministic) ---
foreach ($edges as &$e) {
    $span = isset($e['source_span']) && trim((string) $e['source_span']) !== ''
        ? (string) $e['source_span']
        : trim((string) ($e['subject_text'] ?? '') . ' ' . (string) ($e['verb_text'] ?? ''));
    $e['__vector'] = mem_vector_literal(mem_embed($span, $embed_cfg));
    $e['source_span'] = $span;
}
unset($e);

// --- 3. one transaction per document: upload, then ingest every edge ---
$result = db_tx_core(function () use (
    $title, $text, $source_type, $media_type, $doc_type, $metadata,
    $projects, $subjects, $verbs, $events,
    $edges, $embedding_model, $default_subject, $default_prov, $model_id, $extractor, $namespace
) {
    $doc = db_one(
        "SELECT maludb_upload_document(
                    p_title => ?, p_content_text => ?, p_source_type => ?,
                    p_media_type => ?, p_document_type => ?,
                    p_projects => ?::text[], p_subjects => ?::text[],
                    p_verbs => ?::text[], p_events => ?::text[],
                    p_metadata_jsonb => ?::jsonb) AS id",
        [$title, $text, $source_type, $media_type, $doc_type,
         '{' . implode(',', array_map(fn($s) => '"' . str_replace('"', '\"', $s) . '"', $projects)) . '}',
         '{' . implode(',', array_map(fn($s) => '"' . str_replace('"', '\"', $s) . '"', $subjects)) . '}',
         '{' . implode(',', array_map(fn($s) => '"' . str_replace('"', '\"', $s) . '"', $verbs)) . '}',
         '{' . implode(',', array_map(fn($s) => '"' . str_replace('"', '\"', $s) . '"', $events)) . '}',
         $metadata]
    );
    $document_id = (int) $doc['id'];

    $out = [];
    foreach ($edges as $e) {
        $subject_text = trim((string) ($e['subject_text'] ?? ''));
        $verb_text    = trim((string) ($e['verb_text'] ?? ''));
        if ($subject_text === '' || $verb_text === '') {
            json_error('validation_failed', 'Each edge needs subject_text and verb_text.', 422);
        }
        $predicate  = is_array($e['predicate'] ?? null) ? json_encode($e['predicate']) : '[]';
        $subject_ty = isset($e['subject_type']) && trim((string) $e['subject_type']) !== '' ? (string) $e['subject_type'] : $default_subject;
        $confidence = (array_key_exists('confidence', $e) && $e['confidence'] !== null) ? (string) $e['confidence'] : null;
        $provenance = isset($e['provenance']) && trim((string) $e['provenance']) !== '' ? (string) $e['provenance'] : $default_prov;
        $extr_model = $model_id !== '' ? $model_id : $extractor;

        $st = db_one(
            "SELECT maludb_memory_ingest_edge(
                        p_source_kind      => 'document', p_source_id => ?,
                        p_subject_text     => ?, p_verb_text => ?,
                        p_predicate        => ?::jsonb,
                        p_embedding        => ?::maludb_core.malu_vector,
                        p_embedding_model  => ?,
                        p_subject_type     => ?,
                        p_source_span      => ?,
                        p_confidence       => ?::numeric,
                        p_provenance       => ?,
                        p_extraction_model => ?,
                        p_namespace        => ?,
                        p_document_id      => ?) AS statement_id",
            [$document_id, $subject_text, $verb_text, $predicate, $e['__vector'], $embedding_model,
             $subject_ty, (string) $e['source_span'], $confidence, $provenance, $extr_model, $namespace, $document_id]
        );
        $out[] = [
            'statement_id' => (int) $st['statement_id'],
            'subject_text' => $subject_text,
            'verb_text'    => $verb_text,
            'subject_type' => $subject_ty,
            'provenance'   => $provenance,
        ];
    }
    return ['document_id' => $document_id, 'edges' => $out];
});

json_response([
    'document_id'     => $result['document_id'],
    'namespace'       => $namespace,
    'embedding_model' => $embedding_model,
    'extractor'       => $extractor,
    'chunk_count'     => count($chunks),
    'edges'           => $result['edges'],
], 201);
