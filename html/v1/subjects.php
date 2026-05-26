<?php
/**
 * /v1/subjects  (requirements.md §4.1)
 *
 *   GET  ?q=&limit=   List subjects. Each row carries linked_verbs (count).
 *   POST              Create a subject. Body: {label, type?, description?, classifier_md?}
 *
 * Live-schema mapping (DB column -> API field):
 *   subject_id     -> id
 *   canonical_name -> label
 *   subject_type   -> type
 * Verb links live in maludb_subject_verb keyed by subject_name (= canonical_name).
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
            $where    = "WHERE s.canonical_name ILIKE ? OR s.description ILIKE ?";
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $sql = "SELECT s.subject_id     AS id,
                       s.canonical_name AS label,
                       s.subject_type   AS type,
                       s.description,
                       s.classifier_md,
                       (SELECT count(*) FROM maludb_subject_verb sv
                          WHERE sv.subject_name = s.canonical_name) AS linked_verbs
                  FROM maludb_subject s
                  $where
                 ORDER BY s.canonical_name
                 LIMIT $limit";

        $rows = db_query($sql, $params);
        foreach ($rows as &$r) {
            $r['id']           = (int) $r['id'];
            $r['linked_verbs'] = (int) $r['linked_verbs'];
        }
        unset($r);

        json_response(['subjects' => $rows]);
    }

    case 'POST': {
        $body = body_json();

        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '') {
            json_error('missing_field', 'Field "label" is required.', 400);
        }
        $type          = isset($body['type'])          ? (string) $body['type']          : null;
        $description   = isset($body['description'])   ? (string) $body['description']   : null;
        $classifier_md = isset($body['classifier_md']) ? (string) $body['classifier_md'] : null;

        // subject_id has no sequence/default in this DB — derive it inline so the
        // id assignment is part of the same statement.
        $created = db_one(
            "INSERT INTO maludb_subject
                 (subject_id, canonical_name, subject_type, description, classifier_md, created_at)
             SELECT COALESCE(MAX(subject_id), 0) + 1, ?, ?, ?, ?, now()
               FROM maludb_subject
             RETURNING subject_id     AS id,
                       canonical_name AS label,
                       subject_type   AS type,
                       description,
                       classifier_md",
            [$label, $type, $description, $classifier_md]
        );

        $created['id']           = (int) $created['id'];
        $created['linked_verbs'] = 0;

        json_response(['subject' => $created], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
