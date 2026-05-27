<?php
/**
 * /v1/subjects/{id}/verbs  (requirements.md §4.1)
 *
 *   GET    List the verbs linked to this subject.
 *   POST   Link a verb ({verb_id}) via maludb_subject_verb_link(subject_id, verb_id),
 *          which creates the vector compartment.
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

    case 'POST': {
        $subject = db_one("SELECT canonical_name FROM maludb_subject WHERE subject_id = ?", [$id]);
        if ($subject === null) {
            json_error('not_found', 'Subject not found.', 404);
        }

        $body = body_json();
        if (!array_key_exists('verb_id', $body) || !is_int($body['verb_id'])) {
            json_error('missing_field', 'Field "verb_id" (integer) is required.', 400);
        }
        $verb_id = (int) $body['verb_id'];

        $verb = db_one(
            "SELECT verb_id AS id, canonical_name, verb_type AS type FROM maludb_verb WHERE verb_id = ?",
            [$verb_id]
        );
        if ($verb === null) {
            json_error('validation_failed', 'verb_id does not refer to an existing verb.', 422);
        }

        // Already linked? maludb_subject_verb is keyed by name.
        $exists = db_one(
            "SELECT 1 FROM maludb_subject_verb WHERE subject_name = ? AND verb_name = ?",
            [$subject['canonical_name'], $verb['canonical_name']]
        );
        if ($exists !== null) {
            json_error('conflict', 'That verb is already linked to the subject.', 409);
        }

        $row = db_one("SELECT maludb_subject_verb_link(?, ?) AS compartment_id", [$id, $verb_id]);
        $verb['id'] = (int) $verb['id'];

        json_response([
            'verb'           => $verb,
            'compartment_id' => (int) $row['compartment_id'],
        ], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
