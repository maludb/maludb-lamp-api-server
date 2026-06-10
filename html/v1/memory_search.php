<?php
/**
 * /v1/memory/search  (maludb_core memory — query the stored memory; endpoint group 3)
 *
 *   POST  { query (req text), subject?, verb?, namespace='default', limit=20, metric='cosine' }
 *         Embed the query with the SAME embedding model used at ingest, then call
 *         maludb_memory_search(...). subject/verb pre-filter to a compartment before the ANN.
 *         Returns rows: {chunk_id, statement_id, document_id, source_text, distance, similarity,
 *         rank_no, subject_name, verb_name}.
 *
 *   The query embedding MUST use the same embedding model/dimension as the stored vectors —
 *   mem_embed() reads the configured/namespace model (deterministic fallback otherwise).
 */

require_once __DIR__ . '/../../config/response.php';

$user_id = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('method_not_allowed', 'This endpoint supports POST.', 405);
}

$body = body_json();

$query = isset($body['query']) ? (string) $body['query'] : '';
if (trim($query) === '') json_error('missing_field', 'Field "query" is required.', 400);

$namespace = isset($body['namespace']) && trim((string) $body['namespace']) !== '' ? (string) $body['namespace'] : 'default';
$subject   = isset($body['subject']) && trim((string) $body['subject']) !== '' ? (string) $body['subject'] : null;
$verb      = isset($body['verb']) && trim((string) $body['verb']) !== '' ? (string) $body['verb'] : null;
// The graph-bound search pre-filters to a (subject, verb) compartment before the ANN, so at
// least one is required (the DB enforces this too — surface it as a clean 400).
if ($subject === null && $verb === null) {
    json_error('missing_field', 'Provide "subject" and/or "verb" — the compartment pre-filter is required.', 400);
}
$limit     = isset($body['limit']) ? max(1, min(200, (int) $body['limit'])) : 20;
$metric    = isset($body['metric']) && trim((string) $body['metric']) !== '' ? (string) $body['metric'] : 'cosine';

// Same embedding model (and precedence) as document ingest:
// body > namespace config > the user's 'embed' choice > env default.
$cfg        = mem_namespace_config($namespace);
$user_embed = mem_resolve_embed_config($user_id);
$embedding_model = isset($body['embedding_model']) && trim((string) $body['embedding_model']) !== ''
    ? (string) $body['embedding_model']
    : (($cfg['embedding_model'] ?? '') ?: (($user_embed['embedding_model'] ?? '') ?: (getenv('MALUDB_EMBED_MODEL') ?: 'maludb-local-dev')));

$vector = mem_vector_literal(mem_embed($query, array_merge($user_embed, ['embedding_model' => $embedding_model])));

$rows = db_tx_core(fn() => db_query(
    "SELECT chunk_id, statement_id, document_id, source_text, distance, similarity,
            rank_no, subject_name, verb_name
       FROM maludb_memory_search(
                p_query_embedding => ?::maludb_core.malu_vector,
                p_subject         => ?,
                p_verb            => ?,
                p_namespace       => ?,
                p_limit           => ?,
                p_metric          => ?)",
    [$vector, $subject, $verb, $namespace, $limit, $metric]
));
foreach ($rows as &$r) {
    foreach (['chunk_id', 'statement_id', 'document_id', 'rank_no'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    foreach (['distance', 'similarity'] as $k) {
        $r[$k] = $r[$k] === null ? null : (float) $r[$k];
    }
}
unset($r);

json_response([
    'namespace'       => $namespace,
    'embedding_model' => $embedding_model,
    'results'         => $rows,
]);
