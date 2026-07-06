<?php
/**
 * /v1/graph/import  (maludb_core 0.103.0 — bulk import of a Graphify node-link graph)
 *
 *   POST  Body: {
 *     "namespace":  "my-repo",                    # required; prefixes canonical names
 *     "provenance": "graphify-0.9.6",             # optional free text (default "graphify")
 *     "graph":      {"nodes":[...], "links":[...]},  # "edges" accepted as alias of "links"
 *     "options":    {"resolve_external": true, "algorithm": "louvain"}   # optional
 *   }
 *
 * Thin relay onto the in-core maludb_graph_import(namespace, graph jsonb, options jsonb):
 * the core owns the whole transformation (types, subjects, SVO edges, communities); this
 * endpoint validates the HTTP input and maps the core report onto the contract response
 * shape. Idempotent (subjects upsert by canonical name, statements by identity).
 * Caps: 50000 nodes / 200000 links. Runs in db_tx_core().
 * 409 not_supported when the core predates maludb_graph_import.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('method_not_allowed', 'This endpoint supports POST.', 405);
}

const GRAPH_IMPORT_MAX_NODES        = 50000;
const GRAPH_IMPORT_MAX_LINKS        = 200000;
const GRAPH_IMPORT_MAX_SKIPPED_REPORTED = 200;

$body = body_json();

$namespace = trim((string) ($body['namespace'] ?? ''));
if ($namespace === '') {
    json_error('missing_field', 'Field "namespace" is required.', 400);
}
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $namespace)) {
    json_error('validation_failed', '"namespace" must match ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$.', 422);
}

$graph = $body['graph'] ?? null;
if (!is_array($graph)) {
    json_error('missing_field', 'Field "graph" (node-link object) is required.', 400);
}

$nodes = $graph['nodes'] ?? null;
$links = $graph['links'] ?? ($graph['edges'] ?? []);
if (!is_array($nodes) || $nodes === [] || !array_is_list($nodes)) {
    json_error('validation_failed', '"graph.nodes" must be a non-empty array.', 422);
}
if (!is_array($links) || ($links !== [] && !array_is_list($links))) {
    json_error('validation_failed', '"graph.links" must be an array.', 422);
}
if (count($nodes) > GRAPH_IMPORT_MAX_NODES) {
    json_error('validation_failed', '"graph.nodes" exceeds the ' . GRAPH_IMPORT_MAX_NODES . ' node cap.', 422);
}
if (count($links) > GRAPH_IMPORT_MAX_LINKS) {
    json_error('validation_failed', '"graph.links" exceeds the ' . GRAPH_IMPORT_MAX_LINKS . ' link cap.', 422);
}

$options = (isset($body['options']) && is_array($body['options'])) ? $body['options'] : [];
if (array_key_exists('chunk_size', $options)
        && (!is_int($options['chunk_size']) || $options['chunk_size'] < 50 || $options['chunk_size'] > 5000)) {
    json_error('validation_failed', '"options.chunk_size" must be an integer in [50, 5000].', 422);
}

// Provenance: strip control characters, trim, cap at 200 chars, default "graphify".
$provenance = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($body['provenance'] ?? '')));
$provenance = mb_substr($provenance, 0, 200);
if ($provenance === '') $provenance = 'graphify';

$core_options = ['provenance' => $provenance];
if (($options['resolve_external'] ?? null) === true) {
    $core_options['resolve_external'] = true;
}
if (isset($options['algorithm']) && is_string($options['algorithm'])) {
    $core_options['algorithm'] = $options['algorithm'];
}

$report = db_tx_core(function () use ($namespace, $nodes, $links, $core_options) {
    $has = db_one("SELECT to_regproc('maludb_graph_import') IS NOT NULL AS ok");
    if (!$has || !$has['ok']) {
        json_error('not_supported', 'maludb_graph_import is not available (requires maludb_core >= 0.103.0).', 409);
    }
    $row = db_one(
        "SELECT maludb_graph_import(?, ?::jsonb, ?::jsonb) AS report",
        [
            $namespace,
            json_encode(['nodes' => $nodes, 'links' => $links], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($core_options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]
    );
    return $row['report'] === null ? [] : json_decode($row['report'], true);
});

$n    = is_array($report['nodes'] ?? null) ? $report['nodes'] : [];
$e    = is_array($report['edges'] ?? null) ? $report['edges'] : [];
$comm = is_array($report['communities'] ?? null) ? $report['communities'] : null;

json_response([
    'namespace' => $namespace,
    'nodes'     => [
        'received' => (int) ($n['received'] ?? 0),
        'imported' => (int) ($n['received'] ?? 0),
        'created'  => (int) ($n['created'] ?? 0),
        'resolved' => (int) ($n['resolved'] ?? 0),
    ],
    'edges'     => [
        'received' => (int) ($e['received'] ?? 0),
        'imported' => (int) ($e['received'] ?? 0),
        'created'  => (int) ($e['created'] ?? 0),
    ],
    'verbs_created' => (int) ($report['verbs_created'] ?? 0),
    'communities'   => $comm === null ? null : [
        'stored'  => (int) ($comm['communities'] ?? 0),
        'members' => (int) ($comm['members'] ?? 0),
    ],
    'chunks'  => 1,
    'skipped' => array_slice(
        is_array($report['skipped'] ?? null) ? $report['skipped'] : [],
        0, GRAPH_IMPORT_MAX_SKIPPED_REPORTED
    ),
]);
