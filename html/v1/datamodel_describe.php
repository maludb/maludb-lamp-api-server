<?php
/**
 * /v1/datamodel/describe  (maludb_core 0.104.0 — live catalog description)
 *
 *   GET  ?relation=maludb_subject
 *        → maludb_datamodel_describe(relation) — one relation's live catalog shape
 *          (columns, primary key, FKs in/out) as jsonb.
 *
 * Response: {"relation": "...", "describe": {...}}.
 * Routed by .htaccess: /v1/datamodel/<op> → datamodel_<op>.php. Runs in db_tx_core().
 * 409 not_supported when the core predates maludb_datamodel_describe.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}

$relation = query_str('relation', null, 200);
if ($relation === null || $relation === '') {
    json_error('missing_field', 'Query param "relation" is required.', 400);
}

$describe = db_tx_core(function () use ($relation) {
    $has = db_one("SELECT to_regproc('maludb_datamodel_describe') IS NOT NULL AS ok");
    if (!$has || !$has['ok']) {
        json_error('not_supported', 'maludb_datamodel_describe is not available (requires maludb_core >= 0.104.0).', 409);
    }
    $row = db_one("SELECT maludb_datamodel_describe(?) AS report", [$relation]);
    return $row['report'] === null ? null : json_decode($row['report'], true);
});

json_response(['relation' => $relation, 'describe' => $describe]);
