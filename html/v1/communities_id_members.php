<?php
/**
 * /v1/communities/{id}/members  (maludb_core 0.102.0 — community membership)
 *
 *   GET  ?limit=200
 *        Members of one community from maludb_community_membership, joined to
 *        maludb_subject for a display label:
 *        (object_kind, object_id, score, canonical_name, label). `label` is the
 *        subject's first alias, else its canonical name. `limit` default 200, max 2000.
 *
 * 404 when the community does not exist. Routed by .htaccess: the generic 3-segment
 * rule maps /v1/communities/<id>/members → communities_id_members.php?id=<id>.
 * Runs in db_tx_core(). 409 not_supported when the core predates the community views.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}

/** Parse a Postgres text[] literal ('{a,"b c"}') into a PHP list of strings. */
if (!function_exists('pg_text_array')) {
function pg_text_array(?string $lit): array {
    if ($lit === null || $lit === '' || $lit === '{}') return [];
    $body = substr($lit, 1, -1);   // strip the outer { }
    $out = []; $cur = ''; $quoted = false; $in_quotes = false;
    for ($i = 0, $len = strlen($body); $i < $len; $i++) {
        $c = $body[$i];
        if ($in_quotes) {
            if ($c === '\\')     { $cur .= $body[++$i] ?? ''; }
            elseif ($c === '"')  { $in_quotes = false; }
            else                 { $cur .= $c; }
        } elseif ($c === '"')    { $in_quotes = true; $quoted = true; }
        elseif ($c === ',') {
            $out[] = (!$quoted && $cur === 'NULL') ? null : $cur;
            $cur = ''; $quoted = false;
        } else { $cur .= $c; }
    }
    $out[] = (!$quoted && $cur === 'NULL') ? null : $cur;
    return $out;
}
}

$community_id = path_id();
$limit        = query_int('limit', 200, 2000);
if ($limit < 1) $limit = 1;

$rows = db_tx_core(function () use ($community_id, $limit) {
    $has = db_one("SELECT to_regclass('maludb_community') IS NOT NULL AS ok");
    if (!$has || !$has['ok']) {
        json_error('not_supported', 'maludb_community is not available (requires maludb_core >= 0.102.0).', 409);
    }
    $exists = db_one("SELECT community_id FROM maludb_community WHERE community_id = ?", [$community_id]);
    if ($exists === null) {
        json_error('not_found', "Community $community_id not found.", 404);
    }
    return db_query(
        "SELECT m.object_kind, m.object_id, m.score, s.canonical_name, s.aliases
           FROM maludb_community_membership m
           LEFT JOIN maludb_subject s
             ON m.object_kind = 'subject' AND s.subject_id = m.object_id
          WHERE m.community_id = ?
          ORDER BY m.object_id
          LIMIT $limit",
        [$community_id]
    );
});
foreach ($rows as &$r) {
    $r['object_id'] = (int) $r['object_id'];
    $r['score']     = $r['score'] === null ? null : (float) $r['score'];
    $aliases        = pg_text_array($r['aliases']);
    unset($r['aliases']);
    $r['label'] = ($aliases !== [] && $aliases[0] !== null && $aliases[0] !== '')
        ? $aliases[0] : $r['canonical_name'];
}
unset($r);

json_response(['community_id' => $community_id, 'members' => $rows]);
