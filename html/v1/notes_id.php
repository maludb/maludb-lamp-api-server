<?php
/**
 * /v1/notes/{id}  (requirements.md §4.5)
 *
 *   GET     Note detail.
 *   PATCH   Update {title?, body?, type?, project_id?}.
 *   DELETE  Remove the note.
 *
 * Backed by maludb_memory (see notes.php for the field mapping).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

function load_note(int $id): ?array {
    $note = db_one(
        "SELECT memory_id AS id, title, summary AS body, memory_kind AS type,
                (payload_jsonb->>'project_id')::bigint AS project_id,
                issue_closed_at, created_at, updated_at
           FROM maludb_memory
          WHERE memory_id = ?",
        [$id]
    );
    if ($note === null) {
        return null;
    }
    $note['id']         = (int) $note['id'];
    $note['project_id'] = $note['project_id'] === null ? null : (int) $note['project_id'];
    return $note;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $note = load_note($id);
        if ($note === null) {
            json_error('not_found', 'Note not found.', 404);
        }
        json_response(['note' => $note]);
    }

    case 'PATCH': {
        if (db_one("SELECT 1 FROM maludb_memory WHERE memory_id = ?", [$id]) === null) {
            json_error('not_found', 'Note not found.', 404);
        }

        $body   = body_json();
        $fields = [];
        $params = [];

        if (array_key_exists('title', $body)) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                json_error('validation_failed', 'Field "title" cannot be empty.', 422);
            }
            $fields[] = 'title = ?'; $params[] = $title;
        }
        if (array_key_exists('body', $body)) {
            $fields[] = 'summary = ?';
            $params[] = $body['body'] === null ? null : (string) $body['body'];
        }
        if (array_key_exists('type', $body)) {
            $type = trim((string) $body['type']);
            if ($type === '') {
                json_error('validation_failed', 'Field "type" cannot be empty.', 422);
            }
            $fields[] = 'memory_kind = ?'; $params[] = $type;
        }
        if (array_key_exists('project_id', $body)) {
            if ($body['project_id'] === null) {
                $fields[] = "payload_jsonb = payload_jsonb - 'project_id'";
            } else {
                if (!is_int($body['project_id'])) {
                    json_error('validation_failed', '"project_id" must be an integer or null.', 422);
                }
                $pid = (int) $body['project_id'];
                if (db_one("SELECT 1 FROM maludb_project WHERE subject_id = ?", [$pid]) === null) {
                    json_error('validation_failed', 'project_id does not refer to an existing project.', 422);
                }
                $fields[] = "payload_jsonb = jsonb_set(COALESCE(payload_jsonb,'{}'::jsonb), '{project_id}', to_jsonb(?::bigint))";
                $params[] = $pid;
            }
        }
        if (!$fields) {
            json_error('bad_request', 'No updatable fields provided (title, body, type, project_id).', 400);
        }

        $fields[] = 'updated_at = now()';
        $params[] = $id;
        db_exec("UPDATE maludb_memory SET " . implode(', ', $fields) . " WHERE memory_id = ?", $params);

        json_response(['note' => load_note($id)]);
    }

    case 'DELETE': {
        $n = db_exec("DELETE FROM maludb_memory WHERE memory_id = ?", [$id]);
        if ($n === 0) {
            json_error('not_found', 'Note not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET, PATCH and DELETE.', 405);
}
