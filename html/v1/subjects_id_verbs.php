<?php
/**
 * /v1/subjects/{id}/verbs  (requirements.md §4.1)
 *
 *   GET    List the verbs linked to this subject. (read-only — works today)
 *   POST   Link a verb ({verb_id}). NOT IMPLEMENTED in v1: a subject↔verb link is
 *          a vector "compartment" that can only be created by a DBMS-project
 *          function the API user isn't granted (see docs/db-requirements.md).
 *
 * Links live in maludb_subject_verb keyed by subject_name (= canonical_name).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $subject = db_one("SELECT canonical_name FROM maludb_subject WHERE subject_id = ?", [$id]);
        if ($subject === null) {
            json_error('not_found', 'Subject not found.', 404);
        }

        $verbs = db_query(
            "SELECT v.verb_id        AS id,
                    v.canonical_name AS canonical_name,
                    v.verb_type      AS type
               FROM maludb_subject_verb sv
               JOIN maludb_verb v ON v.canonical_name = sv.verb_name
              WHERE sv.subject_name = ?
              ORDER BY v.canonical_name",
            [$subject['canonical_name']]
        );
        foreach ($verbs as &$v) { $v['id'] = (int) $v['id']; }
        unset($v);

        json_response(['verbs' => $verbs]);
    }

    case 'POST':
        json_error(
            'not_implemented',
            'Linking a verb to a subject creates a vector compartment, which requires a DBMS-project function not available to the API yet. See docs/db-requirements.md.',
            501
        );

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
