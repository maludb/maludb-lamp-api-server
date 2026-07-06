<?php
/**
 * /v1/graph/path  (maludb_core 0.101.0 — source→target paths, shortest first)
 *
 *   GET  ?source_kind=&source_id=&target_kind=&target_id=&max_depth=6&direction=both&rel=a,b,c
 *        → maludb_graph_path(source_kind, source_id, target_kind, target_id,
 *                            max_depth=6, direction='both', rel_filter text[])
 *          TABLE(depth, path text[]).
 *
 * All simple paths between two (kind, id) handles over the unified edge view, shortest
 * first. `direction` ∈ {both, out, in}; `rel` is an optional comma-separated filter.
 * Routed by .htaccess: /v1/graph/<op> → graph_<op>.php. Runs in db_tx_core().
 * 409 not_supported when the core predates maludb_graph_path.
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

$source_kind = query_str('source_kind', null, 40);
$source_id   = query_int('source_id', null);
$target_kind = query_str('target_kind', null, 40);
$target_id   = query_int('target_id', null);
$max_depth   = query_int('max_depth', 6);
$direction   = query_str('direction', 'both', 20);
$rel         = query_str('rel', null, 400);
if ($source_kind === null || $source_kind === '') json_error('missing_field', 'Query param "source_kind" is required.', 400);
if ($source_id === null)                          json_error('missing_field', 'Query param "source_id" is required.', 400);
if ($target_kind === null || $target_kind === '') json_error('missing_field', 'Query param "target_kind" is required.', 400);
if ($target_id === null)                          json_error('missing_field', 'Query param "target_id" is required.', 400);
if ($max_depth < 1 || $max_depth > 32) {
    json_error('validation_failed', '"max_depth" must be between 1 and 32.', 422);
}

$rows = db_tx_core(function () use ($source_kind, $source_id, $target_kind, $target_id, $max_depth, $direction, $rel) {
    $has = db_one("SELECT to_regproc('maludb_graph_path') IS NOT NULL AS ok");
    if (!$has || !$has['ok']) {
        json_error('not_supported', 'maludb_graph_path is not available (requires maludb_core >= 0.101.0).', 409);
    }
    return db_query(
        "SELECT depth, path
           FROM maludb_graph_path(?, ?, ?, ?, ?, ?, CASE WHEN ? = '' THEN NULL ELSE string_to_array(?, ',') END)",
        [$source_kind, $source_id, $target_kind, $target_id, $max_depth, $direction, (string) $rel, (string) $rel]
    );
});
foreach ($rows as &$r) {
    $r['depth'] = (int) $r['depth'];
    $r['path']  = pg_text_array($r['path']);
}
unset($r);

json_response([
    'source_kind' => $source_kind,
    'source_id'   => $source_id,
    'target_kind' => $target_kind,
    'target_id'   => $target_id,
    'max_depth'   => $max_depth,
    'direction'   => $direction,
    'paths'       => $rows,
]);
