<?php
/**
 * /v1/objects/{kind}/{id}  (maludb_core 0.85.0+ — object-with-attributes ergonomics)
 *
 *   GET   maludb_object_get(kind, id) → jsonb
 *         {kind, id, object, attributes, [statements, details for episodes]}.
 *
 * The (object_kind, object_id) handle is the canonical resource identifier across the
 * graph/attribute/traversal surface — this endpoint resolves one handle inline with its
 * typed attributes (and, for episodes, its statements + details) in a single read.
 *
 * Routed by an .htaccess rule: /v1/objects/<kind>/<id> → objects_id.php?kind=&id=
 * (the {kind} segment is text, so it can't use the generic numeric-id rewrite).
 *
 * Runs in db_tx_core() — maludb_object_get references its malu$* base tables unqualified.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

$kind = query_str('kind', null, 40);
$id   = path_id();
if ($kind === null || $kind === '') {
    json_error('bad_request', 'Missing object kind in path.', 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}

$row = db_tx_core(fn() => db_one("SELECT maludb_object_get(?, ?) AS obj", [$kind, $id]));

// maludb_object_get returns NULL (or a null-object envelope) when the handle is unknown.
$obj = ($row && $row['obj'] !== null) ? json_decode($row['obj']) : null;
if ($obj === null || (isset($obj->object) && $obj->object === null)) {
    json_error('not_found', 'Object not found for the given (kind, id).', 404);
}

json_response(['object' => $obj]);
