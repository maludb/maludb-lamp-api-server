<?php
/**
 * /v1/subjects/{id}/related-subjects/{otherId}  (requirements.md §4.1)
 *
 *   DELETE   Unlink a related subject. Removes the relationship between the two
 *            subjects in either direction.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id    = path_id();
$other = path_sub_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'DELETE': {
        $n = db_exec(
            "DELETE FROM maludb_subject_relationship
              WHERE (from_subject_id = ? AND to_subject_id = ?)
                 OR (from_subject_id = ? AND to_subject_id = ?)",
            [$id, $other, $other, $id]
        );
        if ($n === 0) {
            json_error('not_found', 'No relationship between those subjects.', 404);
        }
        json_response(['deleted' => true, 'id' => $id, 'related_subject_id' => $other, 'removed' => $n]);
    }

    default:
        header('Allow: DELETE');
        json_error('method_not_allowed', 'This endpoint supports DELETE only.', 405);
}
