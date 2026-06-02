<?php
/**
 * /v1/memory/ingest  (text → LLM extraction → memory ingest; per-model prompt, OpenAI + Anthropic)
 *
 *   POST  Body: { text (required), model? (default 'chatgpt-4o'), hints? (context string),
 *                 namespace? (default 'default'), embedding_model?, preview? (bool) }
 *
 *   Pipeline: load the model's system prompt + LLM connection from the MySQL `model_prompts`
 *   table → gather the existing verbs, verb types, subjects and subject types from Postgres and
 *   inject them into the prompt (placeholders {{verbs}} {{verb_types}} {{subjects}}
 *   {{subject_types}} {{hints}}) → call the LLM in its api_format (openai|anthropic) → parse the
 *   candidate_edges JSON → upload the text as a document and ingest each edge (embedding +
 *   maludb_memory_ingest_edge, provenance 'suggested'). Returns {document_id, edges}.
 *
 *   preview=true assembles + returns the filled prompt WITHOUT calling the model or writing —
 *   useful for verifying the prompt (and for testing without live model credentials).
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

$model      = isset($body['model']) && trim((string) $body['model']) !== '' ? (string) $body['model'] : 'chatgpt-4o';
$hints      = isset($body['hints']) && $body['hints'] !== null ? (string) $body['hints'] : '';
$namespace  = isset($body['namespace']) && trim((string) $body['namespace']) !== '' ? (string) $body['namespace'] : 'default';
$preview    = !empty($body['preview']);
$embedding_model = isset($body['embedding_model']) && trim((string) $body['embedding_model']) !== ''
    ? (string) $body['embedding_model']
    : (getenv('MALUDB_EMBED_MODEL') ?: 'maludb-local-dev');

// --- 1. per-model prompt + LLM connection (MySQL) ---
$pr = LocalDatabase::modelPrompt($model);
if ($pr === null) {
    json_error('model_not_configured', 'No prompt configured for model "' . $model . '". Set one via POST /v1/model-prompts.', 422);
}

// --- 2. gather the graph vocabulary from Postgres (facades resolve on the default search_path) ---
$verbs         = db_query("SELECT canonical_name, verb_type FROM maludb_verb ORDER BY canonical_name");
$verb_types    = db_query("SELECT verb_type, display_name FROM maludb_verb_type ORDER BY sort_order, verb_type");
$subjects      = db_query("SELECT canonical_name, subject_type FROM maludb_subject ORDER BY canonical_name");
$subject_types = db_query("SELECT subject_type, display_name FROM maludb_subject_type ORDER BY sort_order, subject_type");

$fmt_pairs = static function (array $rows, string $a, string $b): string {
    $out = [];
    foreach ($rows as $r) {
        $out[] = ($r[$b] !== null && $r[$b] !== '') ? "- {$r[$a]} ({$r[$b]})" : "- {$r[$a]}";
    }
    return $out ? implode("\n", $out) : '(none)';
};
$fmt_one = static function (array $rows, string $a): string {
    $out = [];
    foreach ($rows as $r) { $out[] = "- {$r[$a]}"; }
    return $out ? implode("\n", $out) : '(none)';
};

$system_prompt = strtr($pr['system_prompt'], [
    '{{verbs}}'         => $fmt_pairs($verbs, 'canonical_name', 'verb_type'),
    '{{verb_types}}'    => $fmt_pairs($verb_types, 'verb_type', 'display_name'),
    '{{subjects}}'      => $fmt_pairs($subjects, 'canonical_name', 'subject_type'),
    '{{subject_types}}' => $fmt_pairs($subject_types, 'subject_type', 'display_name'),
    '{{hints}}'         => ($hints !== '' ? $hints : '(none)'),
    '{{text}}'          => $text,   // allow the prompt to embed the text directly if it wants
]);

// --- preview: return the assembled prompt without calling the model or writing ---
if ($preview) {
    json_response([
        'model'         => $model,
        'api_format'    => $pr['api_format'],
        'system_prompt' => $system_prompt,
        'user_message'  => $text,
        'counts'        => [
            'verbs' => count($verbs), 'verb_types' => count($verb_types),
            'subjects' => count($subjects), 'subject_types' => count($subject_types),
        ],
    ]);
}

if ($pr['api_key'] === null || $pr['api_key'] === '') {
    json_error('model_api_key_missing', 'No API key set for model "' . $model . '". Set it via POST /v1/model-prompts.', 409);
}

// --- 3. call the LLM (OpenAI or Anthropic shape) and parse candidate_edges ---
$cfg = [
    'api_format'       => $pr['api_format'],
    'base_url'         => $pr['base_url'],
    'model_identifier' => $model,
    'token'            => $pr['api_key'],
    'max_tokens'       => (int) $pr['max_tokens'],
];
$content = llm_complete($cfg, $system_prompt, $text);
$parsed  = llm_json_from_text($content);
if ($parsed === null || !isset($parsed['candidate_edges']) || !is_array($parsed['candidate_edges'])) {
    json_error('upstream_error', 'LLM output was not the candidate_edges contract.', 502);
}
$edges = array_values(array_filter($parsed['candidate_edges'], 'is_array'));
if (!$edges) {
    json_error('no_edges', 'The model returned no edges to ingest.', 422);
}

// --- 4. upload the text + ingest each edge (one transaction) ---
$result = db_tx_core(function () use ($text, $edges, $embedding_model, $namespace, $model) {
    $doc = db_one(
        "SELECT maludb_upload_document(p_title => ?, p_content_text => ?, p_source_type => 'document') AS id",
        [mb_substr(trim($text), 0, 80), $text]
    );
    $document_id = (int) $doc['id'];

    $out = [];
    foreach ($edges as $e) {
        $subject_text = trim((string) ($e['subject_text'] ?? ''));
        $verb_text    = trim((string) ($e['verb_text'] ?? ''));
        if ($subject_text === '' || $verb_text === '') continue;   // skip malformed edges

        $span       = isset($e['source_span']) && trim((string) $e['source_span']) !== ''
            ? (string) $e['source_span'] : ($subject_text . ' ' . $verb_text);
        $vector     = mem_vector_literal(mem_embed($span, ['embedding_model' => $embedding_model]));
        $predicate  = is_array($e['predicate'] ?? null) ? json_encode($e['predicate']) : '[]';
        $subject_ty = isset($e['subject_type']) && trim((string) $e['subject_type']) !== '' ? (string) $e['subject_type'] : 'other';
        $confidence = (array_key_exists('confidence', $e) && $e['confidence'] !== null) ? (string) $e['confidence'] : null;

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
                        p_provenance       => 'suggested',
                        p_extraction_model => ?,
                        p_namespace        => ?,
                        p_document_id      => ?) AS statement_id",
            [$document_id, $subject_text, $verb_text, $predicate, $vector, $embedding_model,
             $subject_ty, $span, $confidence, $model, $namespace, $document_id]
        );
        $out[] = ['statement_id' => (int) $st['statement_id'], 'subject_text' => $subject_text, 'verb_text' => $verb_text];
    }
    return ['document_id' => $document_id, 'edges' => $out];
});

json_response([
    'document_id'     => $result['document_id'],
    'model'           => $model,
    'api_format'      => $pr['api_format'],
    'namespace'       => $namespace,
    'embedding_model' => $embedding_model,
    'edges'           => $result['edges'],
], 201);
