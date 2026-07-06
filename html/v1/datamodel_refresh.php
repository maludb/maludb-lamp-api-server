<?php
/**
 * /v1/datamodel/refresh  (maludb_core 0.104.0 — data-model graph refresh)
 *
 *   POST  Body (optional): {"namespace": "datamodel", "schemas": ["maludb_core"]}
 *
 * Introspect the tenant's database objects (tables, views, routines, triggers, FKs,
 * view dependencies) into the data-model graph namespace via
 * maludb_datamodel_refresh(namespace, schemas name[]). `schemas` NULL (absent) means
 * the core's default schema set. Response: {"report": {...}} (the core's jsonb report).
 * Routed by .htaccess: /v1/datamodel/<op> → datamodel_<op>.php. Runs in db_tx_core().
 * 409 not_supported when the core predates maludb_datamodel_refresh.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('method_not_allowed', 'This endpoint supports POST.', 405);
}

// The body is entirely optional (defaults apply); tolerate a missing/invalid body.
try {
    $body = body_json();
} catch (ApiException $e) {
    $body = [];
}

$namespace = trim((string) ($body['namespace'] ?? ''));
if ($namespace === '') $namespace = 'datamodel';

$schemas = $body['schemas'] ?? null;
if ($schemas !== null) {
    if (!is_array($schemas) || !array_is_list($schemas)) {
        json_error('validation_failed', '"schemas" must be an array of schema names.', 422);
    }
    foreach ($schemas as $s) {
        // Plain identifiers only — these are assembled into a name[] literal below.
        if (!is_string($s) || !preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $s)) {
            json_error('validation_failed', '"schemas" must be an array of schema names.', 422);
        }
    }
}
// name[] literal from validated identifiers (no quoting needed), or NULL when absent.
$schemas_literal = $schemas === null ? null : '{' . implode(',', $schemas) . '}';

$report = db_tx_core(function () use ($namespace, $schemas_literal) {
    $has = db_one("SELECT to_regproc('maludb_datamodel_refresh') IS NOT NULL AS ok");
    if (!$has || !$has['ok']) {
        json_error('not_supported', 'maludb_datamodel_refresh is not available (requires maludb_core >= 0.104.0).', 409);
    }
    $row = db_one(
        "SELECT maludb_datamodel_refresh(?, ?::name[]) AS report",
        [$namespace, $schemas_literal]
    );
    return $row['report'] === null ? null : json_decode($row['report'], true);
});

json_response(['report' => $report]);
