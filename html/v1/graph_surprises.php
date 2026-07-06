<?php
/**
 * /v1/graph/surprises  (maludb_core 0.102.0 — cross-community edges, rarest pair first)
 *
 *   GET  ?namespace=&limit=25
 *        → maludb_graph_surprises(namespace, limit)
 *          TABLE(source_kind, source_id, source_label, source_community, rel,
 *                target_kind, target_id, target_label, target_community,
 *                community_pair_edges).
 *
 * Edges that cross community boundaries within a namespace's community set, ordered so
 * the rarest community pair (fewest connecting edges) comes first — the "surprising"
 * connections. `limit` default 25, max 200.
 * Routed by .htaccess: /v1/graph/<op> → graph_<op>.php. Runs in db_tx_core().
 * 409 not_supported when the core predates maludb_graph_surprises.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}

$namespace = query_str('namespace', null, 64);
$limit     = query_int('limit', 25, 200);
if ($namespace === null || $namespace === '') json_error('missing_field', 'Query param "namespace" is required.', 400);
if ($limit < 1) $limit = 1;

$rows = db_tx_core(function () use ($namespace, $limit) {
    $has = db_one("SELECT to_regproc('maludb_graph_surprises') IS NOT NULL AS ok");
    if (!$has || !$has['ok']) {
        json_error('not_supported', 'maludb_graph_surprises is not available (requires maludb_core >= 0.102.0).', 409);
    }
    return db_query(
        "SELECT source_kind, source_id, source_label, source_community,
                rel, target_kind, target_id, target_label, target_community,
                community_pair_edges
           FROM maludb_graph_surprises(?, ?)",
        [$namespace, $limit]
    );
});
foreach ($rows as &$r) {
    foreach (['source_id', 'target_id', 'source_community', 'target_community', 'community_pair_edges'] as $k) {
        $r[$k] = (int) $r[$k];
    }
}
unset($r);

json_response(['namespace' => $namespace, 'limit' => $limit, 'surprises' => $rows]);
