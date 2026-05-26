<?php
/**
 * /v1/verbs  (requirements.md §4.2)
 *
 *   GET  ?q=&limit=   List verbs. Each row carries linked_subjects (count).
 *   POST              Create a verb. Body: {canonical_name, type?, description?, classifier_md?}
 *
 * Live-schema mapping (DB column -> API field):
 *   verb_id   -> id
 *   verb_type -> type
 *   canonical_name, description, classifier_md -> same
 * Subject links live in maludb_subject_verb keyed by verb_name (= canonical_name).
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
            $where    = "WHERE v.canonical_name ILIKE ? OR v.description ILIKE ?";
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $sql = "SELECT v.verb_id        AS id,
                       v.canonical_name AS canonical_name,
                       v.verb_type      AS type,
                       v.description,
                       v.classifier_md,
                       (SELECT count(*) FROM maludb_subject_verb sv
                          WHERE sv.verb_name = v.canonical_name) AS linked_subjects
                  FROM maludb_verb v
                  $where
                 ORDER BY v.canonical_name
                 LIMIT $limit";

        $rows = db_query($sql, $params);
        foreach ($rows as &$r) {
            $r['id']              = (int) $r['id'];
            $r['linked_subjects'] = (int) $r['linked_subjects'];
        }
        unset($r);

        json_response(['verbs' => $rows]);
    }

    case 'POST': {
        $body = body_json();

        $name = trim((string) ($body['canonical_name'] ?? ''));
        if ($name === '') {
            json_error('missing_field', 'Field "canonical_name" is required.', 400);
        }
        $type          = isset($body['type'])          ? (string) $body['type']          : null;
        $description   = isset($body['description'])   ? (string) $body['description']   : null;
        $classifier_md = isset($body['classifier_md']) ? (string) $body['classifier_md'] : null;

        // verb_id has no sequence/default in this DB — derive it inline.
        $created = db_one(
            "INSERT INTO maludb_verb
                 (verb_id, canonical_name, verb_type, description, classifier_md, created_at)
             SELECT COALESCE(MAX(verb_id), 0) + 1, ?, ?, ?, ?, now()
               FROM maludb_verb
             RETURNING verb_id        AS id,
                       canonical_name AS canonical_name,
                       verb_type      AS type,
                       description,
                       classifier_md",
            [$name, $type, $description, $classifier_md]
        );

        $created['id']              = (int) $created['id'];
        $created['linked_subjects'] = 0;

        json_response(['verb' => $created], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
