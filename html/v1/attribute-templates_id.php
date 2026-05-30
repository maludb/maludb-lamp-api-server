<?php
/**
 * /v1/attribute-templates/{id}  (maludb_core 0.83.0+)
 *
 *   GET     The template (form-field) row.
 *   DELETE  maludb_attribute_template_delete.
 *
 * No PATCH — the 0.83.0 surface exposes only create + delete (re-create to change).
 * Runs in db_tx_core() so the facade resolves its malu$* base tables.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

function template_cols(): string {
    return "template_id AS id, applies_to, type_value, attr_name, value_type, requirement,
            label, description, unit, allowed_values, default_value, display_order, created_at";
}

function shape_template(array &$r): void {
    $r['id']             = (int) $r['id'];
    $r['display_order']  = $r['display_order'] === null ? null : (int) $r['display_order'];
    $r['allowed_values'] = $r['allowed_values'] === null ? null : json_decode($r['allowed_values']);
    $r['default_value']  = $r['default_value']  === null ? null : json_decode($r['default_value']);
}

function load_template(int $id): ?array {
    $row = db_one("SELECT " . template_cols() . " FROM maludb_attribute_template WHERE template_id = ?", [$id]);
    if ($row === null) {
        return null;
    }
    shape_template($row);
    return $row;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $t = db_tx_core(fn() => load_template($id));
        if ($t === null) {
            json_error('not_found', 'Attribute template not found.', 404);
        }
        json_response(['attribute_template' => $t]);
    }

    case 'DELETE': {
        $deleted = db_tx_core(function ($pdo) use ($id) {
            if (db_one("SELECT 1 FROM maludb_attribute_template WHERE template_id = ?", [$id]) === null) {
                return false;
            }
            db_one("SELECT maludb_attribute_template_delete(?)", [$id]);
            return true;
        });
        if (!$deleted) {
            json_error('not_found', 'Attribute template not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET and DELETE.', 405);
}
