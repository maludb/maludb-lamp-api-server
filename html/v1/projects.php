<?php
/**
 * /v1/projects  (requirements.md §4.6)
 *
 *   GET  ?q=&limit=   List projects.
 *   POST              Create a project. Body: {name, description?, classifier_md?}
 *
 * A "project" is a subject with subject_type='project' (maludb_project is a view of
 * maludb_subject WHERE subject_type='project'). project id = subject_id. Projects
 * expose `name` (-> canonical_name).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $q     = query_str('q', null, 200);
        $limit = query_int('limit', 50, 200);

        $where  = '';
        $params = [];
        if ($q !== null && $q !== '') {
            $where    = "WHERE canonical_name ILIKE ? OR description ILIKE ?";
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $sql = "SELECT subject_id     AS id,
                       canonical_name AS name,
                       description,
                       classifier_md,
                       archived_at
                  FROM maludb_project
                  $where
                 ORDER BY canonical_name
                 LIMIT $limit";

        $rows = db_query($sql, $params);
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; }
        unset($r);

        json_response(['projects' => $rows]);
    }

    case 'POST': {
        $body = body_json();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_error('missing_field', 'Field "name" is required.', 400);
        }
        $description   = isset($body['description'])   ? (string) $body['description']   : null;
        $classifier_md = isset($body['classifier_md']) ? (string) $body['classifier_md'] : null;

        // A project is a subject of type 'project'; subject_id has no sequence.
        $created = db_one(
            "INSERT INTO maludb_subject
                 (subject_id, canonical_name, subject_type, description, classifier_md, created_at)
             SELECT COALESCE(MAX(subject_id), 0) + 1, ?, 'project', ?, ?, now()
               FROM maludb_subject
             RETURNING subject_id AS id, canonical_name AS name, description, classifier_md",
            [$name, $description, $classifier_md]
        );
        $created['id'] = (int) $created['id'];

        json_response(['project' => $created], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
