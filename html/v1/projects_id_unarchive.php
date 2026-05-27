<?php
/**
 * /v1/projects/{id}/unarchive  (requirements.md §4.6)
 *
 *   POST  Unarchive the project (409 not_archived if not archived).
 *
 * Uses maludb_project_unarchive(p_project_id); archived state is maludb_subject.archived_at.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'POST': {
        $project = db_one("SELECT archived_at FROM maludb_project WHERE subject_id = ?", [$id]);
        if ($project === null) {
            json_error('not_found', 'Project not found.', 404);
        }
        if ($project['archived_at'] === null) {
            json_error('not_archived', 'Project is not archived.', 409);
        }

        db_one("SELECT maludb_project_unarchive(?)", [$id]);

        $updated = db_one(
            "SELECT subject_id AS id, canonical_name AS name, description, classifier_md, archived_at
               FROM maludb_project WHERE subject_id = ?",
            [$id]
        );
        $updated['id'] = (int) $updated['id'];
        json_response(['project' => $updated]);
    }

    default:
        header('Allow: POST');
        json_error('method_not_allowed', 'This endpoint supports POST only.', 405);
}
