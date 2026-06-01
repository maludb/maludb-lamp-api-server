<?php
/**
 * /v1/graph/neighbors  (maludb_core 0.86.0 — one-hop graph traversal)
 *
 *   GET  ?kind=&id=&direction=both&rel=a,b,c
 *        → maludb_graph_neighbors(kind, id, direction='both', rel_filter text[])
 *          TABLE(neighbor_kind, neighbor_id, rel, edge_store, confidence, provenance, label).
 *
 * One labeled hop out of the (kind, id) handle over the unified edge view (SVO statements
 * + lineage). `direction` ∈ {both, out, in}; `rel` is an optional comma-separated filter.
 * Routed by .htaccess: /v1/graph/<op> → graph_<op>.php. Runs in db_tx_core().
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}

$kind      = query_str('kind', null, 40);
$id        = query_int('id', null);
$direction = query_str('direction', 'both', 20);
$rel       = query_str('rel', null, 400);
if ($kind === null || $kind === '') json_error('missing_field', 'Query param "kind" is required.', 400);
if ($id === null)                   json_error('missing_field', 'Query param "id" is required.', 400);

$rows = db_tx_core(fn() => db_query(
    "SELECT neighbor_kind, neighbor_id, rel, edge_store, confidence, provenance, label
       FROM maludb_graph_neighbors(?, ?, ?, CASE WHEN ? = '' THEN NULL ELSE string_to_array(?, ',') END)",
    [$kind, $id, $direction, (string) $rel, (string) $rel]
));
foreach ($rows as &$r) {
    $r['neighbor_id'] = (int) $r['neighbor_id'];
    $r['confidence']  = $r['confidence'] === null ? null : (float) $r['confidence'];
}
unset($r);

json_response(['kind' => $kind, 'id' => $id, 'direction' => $direction, 'neighbors' => $rows]);
