<?php
/**
 * /v1/graph/query  (lexical seed + bounded walk — graphify-style graph question answering)
 *
 *   GET  ?q=&namespace=&depth=2&seeds=3&max_nodes=50
 *
 * Tokenize the question, score subjects one point per term matching their canonical
 * name or aliases (ILIKE), walk the unified graph from the top-`seeds` scorers via
 * maludb_graph_walk (merging shallowest-depth-wins), cap at `max_nodes`, and return
 * the merged subgraph: the kept nodes plus the maludb_edge rows among them.
 *
 *   depth default 2 (max 6), seeds default 3 (max 10), max_nodes default 50 (max 500).
 *   Optional ?namespace= scopes seed subjects to canonical names "<ns>" or "<ns>/...".
 *
 * Routed by .htaccess: /v1/graph/<op> → graph_<op>.php. Runs in db_tx_core().
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

$q         = query_str('q', null, 400);
$namespace = query_str('namespace', null, 64);
$depth     = query_int('depth', 2, 6);
$seeds     = query_int('seeds', 3, 10);
$max_nodes = query_int('max_nodes', 50, 500);
if ($q === null || $q === '') json_error('missing_field', 'Query param "q" is required.', 400);
if ($depth < 1)     $depth = 1;
if ($seeds < 1)     $seeds = 1;
if ($max_nodes < 1) $max_nodes = 1;

// Tokenize: lowercase, split on non-alphanumerics, keep terms of length >= 2, cap at 12.
$terms = array_values(array_filter(
    preg_split('/[^a-z0-9_]+/', strtolower($q)),
    fn($t) => strlen($t) >= 2
));
if ($terms === []) {
    json_error('validation_failed', 'Query param "q" has no searchable terms.', 422);
}
$terms = array_slice($terms, 0, 12);

$result = db_tx_core(function () use ($terms, $namespace, $depth, $seeds, $max_nodes) {
    // ---- seed scoring: one point per matching term ------------------------
    $score_expr = implode(' + ', array_fill(0, count($terms),
        "(CASE WHEN canonical_name ILIKE ? OR array_to_string(aliases, ' ') ILIKE ? THEN 1 ELSE 0 END)"
    ));
    $like_params = [];
    foreach ($terms as $t) {
        $like = '%' . $t . '%';
        $like_params[] = $like;
        $like_params[] = $like;
    }
    $ns_clause = '';
    $ns_params = [];
    if ($namespace !== null && $namespace !== '') {
        $ns_clause = 'AND (canonical_name LIKE ? OR canonical_name = ?)';
        $ns_params = [$namespace . '/%', $namespace];
    }

    $seed_rows = db_query(
        "SELECT subject_id, canonical_name, aliases, ($score_expr) AS score
           FROM maludb_subject
          WHERE ($score_expr) > 0
            $ns_clause
          ORDER BY score DESC, subject_id
          LIMIT $seeds",
        array_merge($like_params, $like_params, $ns_params)
    );
    if ($seed_rows === []) {
        return ['seeds' => [], 'nodes' => [], 'edges' => []];
    }

    // ---- walk from each seed, merge shallowest-depth-wins -----------------
    $node_depth = [];   // "kind:id" => node
    foreach ($seed_rows as $s) {
        $sid     = (int) $s['subject_id'];
        $aliases = pg_text_array($s['aliases']);
        $key     = 'subject:' . $sid;
        if (!isset($node_depth[$key])) {
            $node_depth[$key] = [
                'object_kind'    => 'subject',
                'object_id'      => $sid,
                'label'          => $aliases !== [] ? $aliases[0] : $s['canonical_name'],
                'canonical_name' => $s['canonical_name'],
                'depth'          => 0,
            ];
        }
        $walk = db_query(
            "SELECT object_kind, object_id, depth, label
               FROM maludb_graph_walk(?, ?, ?, 'both')",
            ['subject', $sid, $depth]
        );
        foreach ($walk as $w) {
            $wkey = $w['object_kind'] . ':' . (int) $w['object_id'];
            $d    = (int) $w['depth'];
            if (!isset($node_depth[$wkey]) || $d < $node_depth[$wkey]['depth']) {
                $node_depth[$wkey] = [
                    'object_kind' => $w['object_kind'],
                    'object_id'   => (int) $w['object_id'],
                    'label'       => $w['label'],
                    'depth'       => $d,
                ];
            }
        }
    }

    $nodes = array_values($node_depth);
    usort($nodes, fn($a, $b) => [$a['depth'], $a['object_id']] <=> [$b['depth'], $b['object_id']]);
    $nodes = array_slice($nodes, 0, $max_nodes);

    $kept = [];
    foreach ($nodes as $n) {
        $kept[$n['object_kind'] . ':' . $n['object_id']] = true;
    }

    // ---- edges among kept nodes (coarse id prefilter, exact kind+id check
    // in PHP since ids are only unique per kind) -----------------------------
    $kept_ids = [];
    foreach ($nodes as $n) $kept_ids[$n['object_id']] = true;
    $kept_ids = array_keys($kept_ids);
    sort($kept_ids);
    $ids_csv = implode(',', $kept_ids);   // ints only (query_int / (int) casts)

    $edge_rows = db_query(
        "SELECT source_kind, source_id, rel, target_kind, target_id, confidence
           FROM maludb_edge
          WHERE source_id = ANY(string_to_array(?, ',')::bigint[])
            AND target_id = ANY(string_to_array(?, ',')::bigint[])",
        [$ids_csv, $ids_csv]
    );
    $edges = [];
    foreach ($edge_rows as $e) {
        $src = $e['source_kind'] . ':' . (int) $e['source_id'];
        $tgt = $e['target_kind'] . ':' . (int) $e['target_id'];
        if (!isset($kept[$src]) || !isset($kept[$tgt])) continue;
        $edges[] = [
            'source_kind' => $e['source_kind'],
            'source_id'   => (int) $e['source_id'],
            'rel'         => $e['rel'],
            'target_kind' => $e['target_kind'],
            'target_id'   => (int) $e['target_id'],
            'confidence'  => $e['confidence'] === null ? null : (float) $e['confidence'],
        ];
    }

    return [
        'seeds' => array_map(fn($s) => [
            'subject_id'     => (int) $s['subject_id'],
            'canonical_name' => $s['canonical_name'],
            'score'          => (int) $s['score'],
        ], $seed_rows),
        'nodes' => $nodes,
        'edges' => $edges,
    ];
});

json_response([
    'query'     => $q,
    'namespace' => $namespace,
    'depth'     => $depth,
    'seeds'     => $result['seeds'],
    'nodes'     => $result['nodes'],
    'edges'     => $result['edges'],
]);
