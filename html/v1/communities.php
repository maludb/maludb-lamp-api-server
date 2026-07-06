<?php
/**
 * /v1/communities  (maludb_core 0.102.0 — namespace community sets)
 *
 *   GET  ?namespace=
 *        List community rows from maludb_community with their member counts:
 *        (community_id, namespace, community_key, label, algorithm, computed_at,
 *         member_count). Optional ?namespace= filters to one community set.
 *
 * Read-only. Routed by .htaccess: /v1/communities → communities.php (the generic
 * 1-segment rule). Runs in db_tx_core() (the views resolve their malu$* tables there).
 * 409 not_supported when the core predates the community views.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}

$namespace = query_str('namespace', null, 64);

$rows = db_tx_core(function () use ($namespace) {
    $has = db_one("SELECT to_regclass('maludb_community') IS NOT NULL AS ok");
    if (!$has || !$has['ok']) {
        json_error('not_supported', 'maludb_community is not available (requires maludb_core >= 0.102.0).', 409);
    }
    $where  = '';
    $params = [];
    if ($namespace !== null && $namespace !== '') {
        $where    = 'WHERE c.namespace = ?';
        $params[] = $namespace;
    }
    return db_query(
        "SELECT c.community_id, c.namespace, c.community_key, c.label,
                c.algorithm, c.computed_at, count(m.membership_id) AS member_count
           FROM maludb_community c
           LEFT JOIN maludb_community_membership m ON m.community_id = c.community_id
           $where
          GROUP BY c.community_id, c.namespace, c.community_key, c.label,
                   c.algorithm, c.computed_at
          ORDER BY c.namespace, c.community_key",
        $params
    );
});
foreach ($rows as &$r) {
    $r['community_id']  = (int) $r['community_id'];
    $r['community_key'] = (int) $r['community_key'];
    $r['member_count']  = (int) $r['member_count'];
}
unset($r);

json_response(['communities' => $rows]);
