<?php
/**
 * /v1/documents/{id}  (requirements.md §4.4)
 *
 *   GET     Document metadata (no binary; download is out of v1 — §6).
 *   DELETE  Remove the document and its source package.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $doc = db_one(
            "SELECT d.document_id              AS id,
                    d.title,
                    d.source_type,
                    d.media_type,
                    d.document_type,
                    d.metadata_jsonb->>'description' AS description,
                    sp.content_size,
                    sp.content_hash,
                    d.created_at,
                    d.updated_at
               FROM maludb_document d
               LEFT JOIN maludb_source_package sp ON sp.source_package_id = d.source_package_id
              WHERE d.document_id = ?",
            [$id]
        );
        if ($doc === null) {
            json_error('not_found', 'Document not found.', 404);
        }
        $doc['id']           = (int) $doc['id'];
        $doc['content_size'] = $doc['content_size'] === null ? null : (int) $doc['content_size'];

        json_response(['document' => $doc]);
    }

    case 'DELETE': {
        $row = db_one("SELECT source_package_id FROM maludb_document WHERE document_id = ?", [$id]);
        if ($row === null) {
            json_error('not_found', 'Document not found.', 404);
        }
        db_exec("DELETE FROM maludb_document WHERE document_id = ?", [$id]);
        if ($row['source_package_id'] !== null) {
            db_exec("DELETE FROM maludb_source_package WHERE source_package_id = ?", [$row['source_package_id']]);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET and DELETE.', 405);
}
