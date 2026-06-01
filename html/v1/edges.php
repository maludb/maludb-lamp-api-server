<?php
/**
 * /v1/edges  (maludb_core 0.86.0 — unified edge view)
 *
 *   GET  ?source_kind=&source_id=&target_kind=&target_id=&rel=&edge_store=&limit=
 *        List rows from maludb_edge (SVO statements + lineage unified):
 *        (edge_store, edge_id, source_kind, source_id, rel, target_kind, target_id,
 *         confidence, provenance).
 *
 * Read-only. Runs in db_tx_core() (the view resolves its malu$* base tables there).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}

$source_kind = query_str('source_kind', null, 40);
$target_kind = query_str('target_kind', null, 40);
$rel         = query_str('rel', null, 120);
$edge_store  = query_str('edge_store', null, 40);
$source_id   = query_int('source_id', null);
$target_id   = query_int('target_id', null);
$limit       = query_int('limit', 100, 500);

$clauses = [];
$params  = [];
if ($source_kind !== null && $source_kind !== '') { $clauses[] = "source_kind = ?"; $params[] = $source_kind; }
if ($target_kind !== null && $target_kind !== '') { $clauses[] = "target_kind = ?"; $params[] = $target_kind; }
if ($rel !== null && $rel !== '')                 { $clauses[] = "rel = ?";         $params[] = $rel; }
if ($edge_store !== null && $edge_store !== '')   { $clauses[] = "edge_store = ?";  $params[] = $edge_store; }
if ($source_id !== null) { $clauses[] = "source_id = ?"; $params[] = $source_id; }
if ($target_id !== null) { $clauses[] = "target_id = ?"; $params[] = $target_id; }
$where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

$rows = db_tx_core(fn() => db_query(
    "SELECT edge_store, edge_id, source_kind, source_id, rel, target_kind, target_id, confidence, provenance
       FROM maludb_edge
       $where
      ORDER BY edge_store, edge_id DESC
      LIMIT $limit",
    $params
));
foreach ($rows as &$r) {
    $r['edge_id']    = $r['edge_id'] === null ? null : (int) $r['edge_id'];
    $r['source_id']  = (int) $r['source_id'];
    $r['target_id']  = (int) $r['target_id'];
    $r['confidence'] = $r['confidence'] === null ? null : (float) $r['confidence'];
}
unset($r);

json_response(['edges' => $rows]);
