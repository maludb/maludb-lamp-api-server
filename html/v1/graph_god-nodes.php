<?php
/**
 * /v1/graph/god-nodes  (maludb_core 0.102.0 — highest-degree nodes)
 *
 *   GET  ?limit=10
 *        → maludb_graph_degree(limit)
 *          TABLE(object_kind, object_id, label, degree_out, degree_in, degree_total).
 *
 * The graph's hubs: the `limit` (default 10, max 1000) nodes with the highest total
 * degree over the unified edge view, out/in split included.
 * Routed by .htaccess: /v1/graph/<op> → graph_<op>.php (the op regex allows hyphens,
 * so /v1/graph/god-nodes lands here verbatim). Runs in db_tx_core().
 * 409 not_supported when the core predates maludb_graph_degree.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}

$limit = query_int('limit', 10, 1000);
if ($limit < 1) $limit = 1;

$rows = db_tx_core(function () use ($limit) {
    $has = db_one("SELECT to_regproc('maludb_graph_degree') IS NOT NULL AS ok");
    if (!$has || !$has['ok']) {
        json_error('not_supported', 'maludb_graph_degree is not available (requires maludb_core >= 0.102.0).', 409);
    }
    return db_query(
        "SELECT object_kind, object_id, label, degree_out, degree_in, degree_total
           FROM maludb_graph_degree(?)",
        [$limit]
    );
});
foreach ($rows as &$r) {
    $r['object_id']    = (int) $r['object_id'];
    $r['degree_out']   = (int) $r['degree_out'];
    $r['degree_in']    = (int) $r['degree_in'];
    $r['degree_total'] = (int) $r['degree_total'];
}
unset($r);

json_response(['limit' => $limit, 'god_nodes' => $rows]);
