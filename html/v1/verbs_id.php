<?php
/**
 * /v1/verbs/{id}  (requirements.md §4.2)
 *
 *   GET     Verb detail + embedded subjects[] (the linked subjects).
 *   PATCH   Update {canonical_name?, type?, description?, classifier_md?}.
 *   DELETE  Remove the verb.
 *
 * Live-schema mapping: verb_id->id, verb_type->type. Subject links live in
 * maludb_subject_verb keyed by verb_name (= canonical_name).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

/** Fetch a verb with its embedded subjects[], or null if it doesn't exist. */
function load_verb_detail(int $id): ?array {
    $verb = db_one(
        "SELECT verb_id        AS id,
                canonical_name AS canonical_name,
                verb_type      AS type,
                description,
                classifier_md
           FROM maludb_verb
          WHERE verb_id = ?",
        [$id]
    );
    if ($verb === null) {
        return null;
    }
    $verb['id'] = (int) $verb['id'];

    // Linked subjects — resolve subject details by name through the compartment table.
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
    $verb['subjects'] = $subjects;

    return $verb;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $verb = load_verb_detail($id);
        if ($verb === null) {
            json_error('not_found', 'Verb not found.', 404);
        }
        json_response(['verb' => $verb]);
    }

    case 'PATCH': {
        if (db_one("SELECT 1 FROM maludb_verb WHERE verb_id = ?", [$id]) === null) {
            json_error('not_found', 'Verb not found.', 404);
        }

        $body   = body_json();
        $fields = [];
        $params = [];

        if (array_key_exists('canonical_name', $body)) {
            $name = trim((string) $body['canonical_name']);
            if ($name === '') {
                json_error('validation_failed', 'Field "canonical_name" cannot be empty.', 422);
            }
            $fields[] = 'canonical_name = ?'; $params[] = $name;
        }
        if (array_key_exists('type', $body)) {
            $fields[] = 'verb_type = ?';
            $params[] = $body['type'] === null ? null : (string) $body['type'];
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
            json_error('bad_request', 'No updatable fields provided (canonical_name, type, description, classifier_md).', 400);
        }

        $params[] = $id;
        db_exec("UPDATE maludb_verb SET " . implode(', ', $fields) . " WHERE verb_id = ?", $params);

        json_response(['verb' => load_verb_detail($id)]);
    }

    case 'DELETE': {
        $n = db_exec("DELETE FROM maludb_verb WHERE verb_id = ?", [$id]);
        if ($n === 0) {
            json_error('not_found', 'Verb not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET, PATCH and DELETE.', 405);
}
