<?php
/**
 * /v1/verbs/{id}/subjects  (requirements.md §4.2)
 *
 *   GET   Read-only listing of the subjects linked to this verb.
 *
 * Links live in maludb_subject_verb keyed by verb_name (= the verb's canonical_name).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $verb = db_one("SELECT canonical_name FROM maludb_verb WHERE verb_id = ?", [$id]);
        if ($verb === null) {
            json_error('not_found', 'Verb not found.', 404);
        }

        $subjects = db_query(
            "SELECT s.subject_id     AS id,
                    s.canonical_name AS label,
                    s.subject_type   AS type
               FROM maludb_subject_verb sv
               JOIN maludb_subject s ON s.canonical_name = sv.subject_name
              WHERE sv.verb_name = ?
              ORDER BY s.canonical_name",
            [$verb['canonical_name']]
        );
        foreach ($subjects as &$s) { $s['id'] = (int) $s['id']; }
        unset($s);

        json_response(['subjects' => $subjects]);
    }

    default:
        header('Allow: GET');
        json_error('method_not_allowed', 'This endpoint supports GET only.', 405);
}
