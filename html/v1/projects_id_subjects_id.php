<?php
/**
 * /v1/projects/{id}/subjects/{sid}  (requirements.md §4.6)
 *
 *   DELETE  Unlink one subject from the project (removes the 'has_member' SVPOR edge
 *           via maludb_svpor_relationship_delete). 404 if no such link.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id  = path_id();
$sid = path_sub_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'DELETE': {
        $row = db_one(
            "SELECT maludb_svpor_relationship_delete('subject', ?, 'subject', ?, 'has_member') AS removed",
            [$id, $sid]
        );
        if ((int) $row['removed'] === 0) {
            json_error('not_found', 'That subject is not linked to the project.', 404);
        }
        json_response(['deleted' => true, 'id' => $id, 'subject_id' => $sid]);
    }

    default:
        header('Allow: DELETE');
        json_error('method_not_allowed', 'This endpoint supports DELETE only.', 405);
}
