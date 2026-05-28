<?php
/**
 * /v1/document-types  (maludb_core 0.81.0)
 *
 *   GET    The tenant's document-type picker list (feeds the type dropdown).
 *   POST   Add a type. Body: {document_type, description?, display_order?}
 *
 * Source: maludb_document_type (writable per-schema view; document_type_id from
 * sequence). The label is case-insensitive unique (lower(document_type)) — a
 * duplicate raises 23505, mapped to 409 by the global handler.
 *
 * The list is advisory only: maludb_document.document_type is free text with no
 * FK here, so a document may carry a type that isn't in this list.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $rows = db_query(
            "SELECT document_type_id AS id,
                    document_type,
                    description,
                    display_order,
                    created_at
               FROM maludb_document_type
              ORDER BY display_order NULLS LAST, document_type"
        );
        foreach ($rows as &$r) {
            $r['id']            = (int) $r['id'];
            $r['display_order'] = $r['display_order'] === null ? null : (int) $r['display_order'];
        }
        unset($r);

        json_response(['document_types' => $rows]);
    }

    case 'POST': {
        $body = body_json();

        $label = trim((string) ($body['document_type'] ?? ''));
        if ($label === '') {
            json_error('missing_field', 'Field "document_type" is required.', 400);
        }
        $description   = isset($body['description']) ? (string) $body['description'] : null;
        $display_order = null;
        if (array_key_exists('display_order', $body) && $body['display_order'] !== null) {
            if (!is_int($body['display_order'])) {
                json_error('validation_failed', '"display_order" must be an integer.', 422);
            }
            $display_order = (int) $body['display_order'];
        }

        $created = db_one(
            "INSERT INTO maludb_document_type (document_type, description, display_order)
             VALUES (?, ?, ?)
             RETURNING document_type_id AS id, document_type, description, display_order, created_at",
            [$label, $description, $display_order]
        );
        $created['id']            = (int) $created['id'];
        $created['display_order'] = $created['display_order'] === null ? null : (int) $created['display_order'];

        json_response(['document_type' => $created], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
