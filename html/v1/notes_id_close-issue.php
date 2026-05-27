<?php
/**
 * /v1/notes/{id}/close-issue  (requirements.md §4.5)
 *
 *   POST  Close an issue-type note (sets issue_closed_at = now()).
 *         409 if the note is not an issue (type != 'issue') or is already closed.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'POST': {
        $note = db_one(
            "SELECT memory_kind, issue_closed_at FROM maludb_memory WHERE memory_id = ?",
            [$id]
        );
        if ($note === null) {
            json_error('not_found', 'Note not found.', 404);
        }
        if ($note['memory_kind'] !== 'issue') {
            json_error('conflict', 'Note is not an issue.', 409);
        }
        if ($note['issue_closed_at'] !== null) {
            json_error('conflict', 'Issue is already closed.', 409);
        }

        db_exec(
            "UPDATE maludb_memory SET issue_closed_at = now(), updated_at = now() WHERE memory_id = ?",
            [$id]
        );

        $row = db_one(
            "SELECT memory_id AS id, title, summary AS body, memory_kind AS type,
                    issue_closed_at FROM maludb_memory WHERE memory_id = ?",
            [$id]
        );
        $row['id'] = (int) $row['id'];
        json_response(['note' => $row]);
    }

    default:
        header('Allow: POST');
        json_error('method_not_allowed', 'This endpoint supports POST only.', 405);
}
