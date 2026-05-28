<?php
/**
 * /v1/document-types/{id}  (maludb_core 0.81.0)
 *
 *   PATCH   Update {document_type?, description?, display_order?}.
 *   DELETE  Remove the type from the picker.
 *
 * Source: maludb_document_type (writable per-schema view). The label is
 * case-insensitive unique (lower(document_type)) — a colliding update raises
 * 23505, mapped to 409 by the global handler.
 *
 * Deleting a type does NOT affect documents already tagged with that string:
 * maludb_document.document_type is free text with no FK to this list.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

function load_document_type(int $id): ?array {
    $row = db_one(
        "SELECT document_type_id AS id, document_type, description, display_order, created_at
           FROM maludb_document_type
          WHERE document_type_id = ?",
        [$id]
    );
    if ($row === null) {
        return null;
    }
    $row['id']            = (int) $row['id'];
    $row['display_order'] = $row['display_order'] === null ? null : (int) $row['display_order'];
    return $row;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'PATCH': {
        if (db_one("SELECT 1 FROM maludb_document_type WHERE document_type_id = ?", [$id]) === null) {
            json_error('not_found', 'Document type not found.', 404);
        }

        $body   = body_json();
        $fields = [];
        $params = [];

        if (array_key_exists('document_type', $body)) {
            $label = trim((string) $body['document_type']);
            if ($label === '') {
                json_error('validation_failed', 'Field "document_type" cannot be empty.', 422);
            }
            $fields[] = 'document_type = ?'; $params[] = $label;
        }
        if (array_key_exists('description', $body)) {
            $fields[] = 'description = ?';
            $params[] = $body['description'] === null ? null : (string) $body['description'];
        }
        if (array_key_exists('display_order', $body)) {
            if ($body['display_order'] !== null && !is_int($body['display_order'])) {
                json_error('validation_failed', '"display_order" must be an integer.', 422);
            }
            $fields[] = 'display_order = ?';
            $params[] = $body['display_order'] === null ? null : (int) $body['display_order'];
        }
        if (!$fields) {
            json_error('bad_request', 'No updatable fields provided (document_type, description, display_order).', 400);
        }

        $params[] = $id;
        db_exec("UPDATE maludb_document_type SET " . implode(', ', $fields) . " WHERE document_type_id = ?", $params);

        json_response(['document_type' => load_document_type($id)]);
    }

    case 'DELETE': {
        $n = db_exec("DELETE FROM maludb_document_type WHERE document_type_id = ?", [$id]);
        if ($n === 0) {
            json_error('not_found', 'Document type not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports PATCH and DELETE.', 405);
}
