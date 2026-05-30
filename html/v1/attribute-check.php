<?php
/**
 * /v1/attribute-check  (maludb_core 0.83.0+ — advisory completeness check)
 *
 *   GET  ?target_kind=&target_id=
 *        → maludb_attribute_check(target_kind, target_id) jsonb
 *          {applies_to, type_value, missing_required[], fields[]}.
 *
 * Advisory only — the DB never rejects on missing attributes; this is for the form
 * layer to validate completeness on submit. Runs in db_tx_core() (facade resolves
 * its malu$* base tables unqualified).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}

$target_kind = query_str('target_kind', null, 40);
$target_id   = query_int('target_id', null);
if ($target_kind === null || $target_kind === '') {
    json_error('missing_field', 'Query param "target_kind" is required.', 400);
}
if ($target_id === null) {
    json_error('missing_field', 'Query param "target_id" is required.', 400);
}

$row = db_tx_core(fn() => db_one(
    "SELECT maludb_attribute_check(?, ?) AS check",
    [$target_kind, $target_id]
));

$check = ($row && $row['check'] !== null) ? json_decode($row['check']) : null;
json_response(['check' => $check]);
