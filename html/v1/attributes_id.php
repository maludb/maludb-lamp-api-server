<?php
/**
 * /v1/attributes/{id}  (maludb_core 0.83.0+)
 *
 *   GET     The attribute row.
 *   PATCH   { provenance? }  → maludb_svpor_attribute_set_provenance (the accept/reject
 *                              transition: suggested → accepted | rejected). Any other
 *                              value_* / unit / confidence / metadata fields re-upsert
 *                              the attribute via maludb_svpor_attribute_create.
 *   DELETE  maludb_svpor_attribute_delete.
 *
 * provenance ∈ {provided,suggested,accepted,rejected} (DB-enforced → 422).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

function load_attribute(int $id): ?array {
    $row = db_one("SELECT " . svpor_attribute_cols() . " FROM maludb_svpor_attribute WHERE attribute_id = ?", [$id]);
    if ($row === null) {
        return null;
    }
    shape_attribute($row);
    return $row;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $attr = db_tx_core(fn() => load_attribute($id));
        if ($attr === null) {
            json_error('not_found', 'Attribute not found.', 404);
        }
        json_response(['attribute' => $attr]);
    }

    case 'PATCH': {
        $body = body_json();

        // The only "in place" edit is the provenance review transition. Anything that
        // would change a value is an upsert, which already lives on POST /v1/attributes.
        if (!isset($body['provenance']) || trim((string) $body['provenance']) === '') {
            json_error('bad_request', 'PATCH supports only "provenance" (use POST to re-upsert values).', 400);
        }

        $attr = db_tx_core(function ($pdo) use ($id, $body) {
            if (db_one("SELECT 1 FROM maludb_svpor_attribute WHERE attribute_id = ?", [$id]) === null) {
                return null;
            }
            db_one("SELECT maludb_svpor_attribute_set_provenance(?, ?)", [$id, (string) $body['provenance']]);
            return load_attribute($id);
        });

        if ($attr === null) {
            json_error('not_found', 'Attribute not found.', 404);
        }
        json_response(['attribute' => $attr]);
    }

    case 'DELETE': {
        $deleted = db_tx_core(function ($pdo) use ($id) {
            if (db_one("SELECT 1 FROM maludb_svpor_attribute WHERE attribute_id = ?", [$id]) === null) {
                return false;
            }
            db_one("SELECT maludb_svpor_attribute_delete(?)", [$id]);
            return true;
        });
        if (!$deleted) {
            json_error('not_found', 'Attribute not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET, PATCH and DELETE.', 405);
}
