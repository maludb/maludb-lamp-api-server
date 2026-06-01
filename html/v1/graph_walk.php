<?php
/**
 * /v1/graph/walk  (maludb_core 0.86.0 — multi-hop graph traversal)
 *
 *   GET  ?kind=&id=&max_depth=4&direction=both&rel=a,b,c
 *        → maludb_graph_walk(kind, id, max_depth=4, direction='both', rel_filter text[])
 *          TABLE(object_kind, object_id, depth, rel, edge_store, label, path text[]).
 *
 * Cycle-safe breadth-first walk from the (kind, id) handle. Each row is a reached object
 * with its depth, the rel that reached it, and the path of object ids walked.
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
$max_depth = query_int('max_depth', 4, 20);
$direction = query_str('direction', 'both', 20);
$rel       = query_str('rel', null, 400);
if ($kind === null || $kind === '') json_error('missing_field', 'Query param "kind" is required.', 400);
if ($id === null)                   json_error('missing_field', 'Query param "id" is required.', 400);

$rows = db_tx_core(fn() => db_query(
    "SELECT object_kind, object_id, depth, rel, edge_store, label, path
       FROM maludb_graph_walk(?, ?, ?, ?, CASE WHEN ? = '' THEN NULL ELSE string_to_array(?, ',') END)",
    [$kind, $id, $max_depth, $direction, (string) $rel, (string) $rel]
));
foreach ($rows as &$r) {
    $r['object_id'] = (int) $r['object_id'];
    $r['depth']     = (int) $r['depth'];
    // path is a Postgres text[] — PDO returns it as a literal like {1,2,3}; expose as an array.
    $r['path'] = ($r['path'] === null || $r['path'] === '{}')
        ? []
        : array_map('intval', explode(',', trim($r['path'], '{}')));
}
unset($r);

json_response(['kind' => $kind, 'id' => $id, 'max_depth' => $max_depth, 'direction' => $direction, 'walk' => $rows]);
