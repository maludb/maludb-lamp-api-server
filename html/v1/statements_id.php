<?php
/**
 * /v1/statements/{id}  (maludb_core 0.82.0)
 *
 *   GET     The statement row.
 *   PATCH   { provenance? }  → maludb_svpor_statement_set_provenance (the accept/reject
 *                              transition: suggested → accepted | rejected).
 *           { valid_to? } or { close:true } → maludb_svpor_statement_close (close the
 *                              statement's validity; close=true uses now()).
 *   DELETE  maludb_svpor_statement_delete.
 *
 * provenance ∈ {provided,suggested,accepted,rejected} (DB-enforced → 422).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

function load_statement(int $id): ?array {
    $row = db_one("SELECT " . svpor_statement_cols() . " FROM maludb_svpor_statement WHERE statement_id = ?", [$id]);
    if ($row === null) {
        return null;
    }
    shape_statement($row);
    return $row;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $stmt = db_tx_core(fn() => load_statement($id));
        if ($stmt === null) {
            json_error('not_found', 'Statement not found.', 404);
        }
        json_response(['statement' => $stmt]);
    }

    case 'PATCH': {
        $body = body_json();

        $set_provenance = isset($body['provenance']) && trim((string) $body['provenance']) !== '';
        $do_close       = (array_key_exists('close', $body) && $body['close'] === true)
                          || array_key_exists('valid_to', $body);
        if (!$set_provenance && !$do_close) {
            json_error('bad_request', 'No updatable fields provided (provenance, valid_to, close).', 400);
        }

        $stmt = db_tx_core(function ($pdo) use ($id, $body, $set_provenance, $do_close) {
            if (db_one("SELECT 1 FROM maludb_svpor_statement WHERE statement_id = ?", [$id]) === null) {
                return null;
            }
            if ($set_provenance) {
                db_one("SELECT maludb_svpor_statement_set_provenance(?, ?)", [$id, (string) $body['provenance']]);
            }
            if ($do_close) {
                // close:true → now(); explicit valid_to → that timestamp (null also closes at now()).
                $valid_to = array_key_exists('valid_to', $body) && $body['valid_to'] !== null
                    ? (string) $body['valid_to'] : null;
                db_one("SELECT maludb_svpor_statement_close(?, COALESCE(?::timestamptz, now()))", [$id, $valid_to]);
            }
            return load_statement($id);
        });

        if ($stmt === null) {
            json_error('not_found', 'Statement not found.', 404);
        }
        json_response(['statement' => $stmt]);
    }

    case 'DELETE': {
        $deleted = db_tx_core(function ($pdo) use ($id) {
            if (db_one("SELECT 1 FROM maludb_svpor_statement WHERE statement_id = ?", [$id]) === null) {
                return false;
            }
            db_one("SELECT maludb_svpor_statement_delete(?)", [$id]);
            return true;
        });
        if (!$deleted) {
            json_error('not_found', 'Statement not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET, PATCH and DELETE.', 405);
}
