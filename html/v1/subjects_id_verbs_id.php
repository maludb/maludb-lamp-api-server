<?php
/**
 * /v1/subjects/{id}/verbs/{verbId}  (requirements.md §4.1)
 *
 *   DELETE   Unlink a verb from a subject via maludb_subject_verb_unlink(subject_id, verb_id),
 *            which removes the vector compartment. 404 if no such link.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id      = path_id();
$verb_id = path_sub_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'DELETE': {
        $row = db_one("SELECT maludb_subject_verb_unlink(?, ?) AS removed", [$id, $verb_id]);
        if ((int) $row['removed'] === 0) {
            json_error('not_found', 'That verb is not linked to the subject.', 404);
        }
        json_response(['deleted' => true, 'id' => $id, 'verb_id' => $verb_id]);
    }

    default:
        header('Allow: DELETE');
        json_error('method_not_allowed', 'This endpoint supports DELETE only.', 405);
}
