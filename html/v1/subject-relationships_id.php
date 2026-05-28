<?php
/**
 * /v1/subject-relationships/{relationship_id}
 *
 *   GET     Fetch a single subject↔subject relationship row.
 *   PATCH   Update {relationship_type?, label?, valid_from?, valid_to?}.
 *   DELETE  Remove the relationship by id.
 *
 * Operates directly on the writable view maludb_subject_relationship. The DB enforces:
 *   - relationship_type FK → unregistered type → 422 validation_failed.
 *   - valid_from < valid_to (CHECK) → 422 validation_failed.
 *
 * This is the row-level companion to the pair-level
 * `DELETE /v1/subjects/{id}/related-subjects/{otherId}`. Pass `null` for `valid_from`
 * or `valid_to` in a PATCH to clear that bound; omit the field to leave it unchanged.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

function load_relationship(int $id): ?array {
    $row = db_one(
        "SELECT relationship_id   AS id,
                from_subject_id, to_subject_id,
                from_subject_label, to_subject_label,
                relationship_type,
                label,
                valid_from, valid_to,
                created_at
           FROM maludb_subject_relationship
          WHERE relationship_id = ?",
        [$id]
    );
    if ($row === null) {
        return null;
    }
    $row['id']              = (int) $row['id'];
    $row['from_subject_id'] = (int) $row['from_subject_id'];
    $row['to_subject_id']   = (int) $row['to_subject_id'];
    return $row;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $row = load_relationship($id);
        if ($row === null) {
            json_error('not_found', 'Relationship not found.', 404);
        }
        json_response(['relationship' => $row]);
    }

    case 'PATCH': {
        if (db_one("SELECT 1 FROM maludb_subject_relationship WHERE relationship_id = ?", [$id]) === null) {
            json_error('not_found', 'Relationship not found.', 404);
        }

        $body   = body_json();
        $fields = [];
        $params = [];

        if (array_key_exists('relationship_type', $body)) {
            $rt = trim((string) $body['relationship_type']);
            if ($rt === '') {
                json_error('validation_failed', 'Field "relationship_type" cannot be empty.', 422);
            }
            $fields[] = 'relationship_type = ?'; $params[] = $rt;
        }
        if (array_key_exists('label', $body)) {
            $fields[] = 'label = ?';
            $params[] = $body['label'] === null ? null : (string) $body['label'];
        }
        if (array_key_exists('valid_from', $body)) {
            $fields[] = 'valid_from = ?::timestamptz';
            $params[] = $body['valid_from'] === null || $body['valid_from'] === '' ? null : (string) $body['valid_from'];
        }
        if (array_key_exists('valid_to', $body)) {
            $fields[] = 'valid_to = ?::timestamptz';
            $params[] = $body['valid_to'] === null || $body['valid_to'] === '' ? null : (string) $body['valid_to'];
        }
        if (!$fields) {
            json_error('bad_request', 'No updatable fields provided (relationship_type, label, valid_from, valid_to).', 400);
        }

        $params[] = $id;
        db_exec("UPDATE maludb_subject_relationship SET " . implode(', ', $fields) . " WHERE relationship_id = ?", $params);

        json_response(['relationship' => load_relationship($id)]);
    }

    case 'DELETE': {
        $n = db_exec("DELETE FROM maludb_subject_relationship WHERE relationship_id = ?", [$id]);
        if ($n === 0) {
            json_error('not_found', 'Relationship not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET, PATCH and DELETE.', 405);
}
