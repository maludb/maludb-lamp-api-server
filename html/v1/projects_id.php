<?php
/**
 * /v1/projects/{id}  (requirements.md §4.6)
 *
 *   GET     Project detail + embedded subjects[] / verbs[] (linked identifiers,
 *           read from the SVPOR graph; link writes are deferred — §4.6 notes).
 *   PATCH   Update {name?, description?, classifier_md?}.
 *   DELETE  Remove the project.
 *
 * A project is a subject with subject_type='project'; project id = subject_id.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

/** Fetch a project (subject of type 'project') with linked subjects[]/verbs[], or null. */
function load_project_detail(int $id): ?array {
    $project = db_one(
        "SELECT subject_id AS id, canonical_name AS name, description, classifier_md
           FROM maludb_project
          WHERE subject_id = ?",
        [$id]
    );
    if ($project === null) {
        return null;
    }
    $project['id'] = (int) $project['id'];

    // Linked identifiers come from the SVPOR graph (source = this project subject).
    $edges = db_query(
        "SELECT target_kind, target_id, target_name, relationship_type
           FROM maludb_svpor_relationship
          WHERE source_kind = 'subject' AND source_id = ?
          ORDER BY target_kind, target_name",
        [$id]
    );
    $subjects = [];
    $verbs    = [];
    foreach ($edges as $e) {
        $item = [
            'id'                => (int) $e['target_id'],
            'name'              => $e['target_name'],
            'relationship_type' => $e['relationship_type'],
        ];
        if ($e['target_kind'] === 'verb') { $verbs[] = $item; }
        else                              { $subjects[] = $item; }
    }
    $project['subjects'] = $subjects;
    $project['verbs']    = $verbs;

    return $project;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $project = load_project_detail($id);
        if ($project === null) {
            json_error('not_found', 'Project not found.', 404);
        }
        json_response(['project' => $project]);
    }

    case 'PATCH': {
        if (db_one("SELECT 1 FROM maludb_project WHERE subject_id = ?", [$id]) === null) {
            json_error('not_found', 'Project not found.', 404);
        }

        $body   = body_json();
        $fields = [];
        $params = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                json_error('validation_failed', 'Field "name" cannot be empty.', 422);
            }
            $fields[] = 'canonical_name = ?'; $params[] = $name;
        }
        if (array_key_exists('description', $body)) {
            $fields[] = 'description = ?';
            $params[] = $body['description'] === null ? null : (string) $body['description'];
        }
        if (array_key_exists('classifier_md', $body)) {
            $fields[] = 'classifier_md = ?';
            $params[] = $body['classifier_md'] === null ? null : (string) $body['classifier_md'];
        }
        if (!$fields) {
            json_error('bad_request', 'No updatable fields provided (name, description, classifier_md).', 400);
        }

        $params[] = $id;
        db_exec(
            "UPDATE maludb_subject SET " . implode(', ', $fields) . " WHERE subject_id = ? AND subject_type = 'project'",
            $params
        );

        json_response(['project' => load_project_detail($id)]);
    }

    case 'DELETE': {
        $n = db_exec("DELETE FROM maludb_subject WHERE subject_id = ? AND subject_type = 'project'", [$id]);
        if ($n === 0) {
            json_error('not_found', 'Project not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET, PATCH and DELETE.', 405);
}
