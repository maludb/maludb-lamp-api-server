<?php
/**
 * /v1/graph/stats  (aggregates over the unified edge view)
 *
 *   GET  ?top_rels=25
 *        → {"stats": {edges, nodes, by_store: {store: count}, top_rels: [{rel, edges}]}}
 *
 * edges = row count of maludb_edge; nodes = distinct (kind, id) endpoints (UNION of
 * source and target handles); by_store groups by edge_store; top_rels is the ?top_rels
 * most frequent rels (default 25, max 100).
 * Routed by .htaccess: /v1/graph/<op> → graph_<op>.php. Runs in db_tx_core().
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}

$top_rels = query_int('top_rels', 25, 100);
if ($top_rels < 1) $top_rels = 1;

$stats = db_tx_core(function () use ($top_rels) {
    $edges = db_one("SELECT count(*) AS edges FROM maludb_edge");

    $nodes = db_one(
        "SELECT count(*) AS nodes
           FROM (SELECT source_kind AS kind, source_id AS id FROM maludb_edge
                 UNION
                 SELECT target_kind, target_id FROM maludb_edge) endpoints"
    );

    $by_store_rows = db_query(
        "SELECT edge_store, count(*) AS edges
           FROM maludb_edge
          GROUP BY edge_store
          ORDER BY edges DESC, edge_store"
    );
    $by_store = [];
    foreach ($by_store_rows as $r) {
        $by_store[$r['edge_store']] = (int) $r['edges'];
    }

    $by_rel = db_query(
        "SELECT rel, count(*) AS edges
           FROM maludb_edge
          GROUP BY rel
          ORDER BY edges DESC, rel NULLS LAST
          LIMIT $top_rels"
    );

    return [
        'edges'    => (int) $edges['edges'],
        'nodes'    => (int) $nodes['nodes'],
        // Cast so an empty map serializes as {} rather than [].
        'by_store' => (object) $by_store,
        'top_rels' => array_map(
            fn($r) => ['rel' => $r['rel'], 'edges' => (int) $r['edges']],
            $by_rel
        ),
    ];
});

json_response(['stats' => $stats]);
