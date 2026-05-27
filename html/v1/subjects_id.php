<?php
/**
 * /v1/subjects/{id}  (requirements.md §4.1)
 *
 *   GET     Subject detail + embedded verbs[] and related_subjects[] (§4.10).
 *   PATCH   Update {label?, type?, description?, classifier_md?}.
 *   DELETE  Remove the subject.
 *
 * Live-schema mapping: subject_id->id, canonical_name->label, subject_type->type.
 * Verb links: maludb_subject_verb (keyed by subject_name = canonical_name).
 * Relationships: maludb_subject_relationship (from/to subject ids + labels).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

/** Fetch a subject with its embedded verbs[] and related_subjects[], or null. */
function load_subject_detail(int $id): ?array {
    $subject = db_one(
        "SELECT subject_id     AS id,
                canonical_name AS label,
                subject_type   AS type,
                description,
                classifier_md
           FROM maludb_subject
          WHERE subject_id = ?",
        [$id]
    );
    if ($subject === null) {
        return null;
    }
    $subject['id'] = (int) $subject['id'];

    // Linked verbs — resolve verb details by name through the compartment table.
    $verbs = db_query(
        "SELECT v.verb_id        AS id,
                v.canonical_name AS canonical_name,
                v.verb_type      AS type
           FROM maludb_subject_verb sv
           JOIN maludb_verb v ON v.canonical_name = sv.verb_name
          WHERE sv.subject_name = ?
          ORDER BY v.canonical_name",
        [$subject['label']]
    );
    foreach ($verbs as &$v) { $v['id'] = (int) $v['id']; }
    unset($v);
    $subject['verbs'] = $verbs;

    // Related subjects — either endpoint of a relationship; the "other" side is returned.
    $rels = db_query(
        "SELECT relationship_id,
                from_subject_id,
                to_subject_id,
                from_subject_label,
                to_subject_label,
                relationship_type,
                label AS relationship_label,
                valid_from,
                valid_to
           FROM maludb_subject_relationship
          WHERE from_subject_id = ? OR to_subject_id = ?
          ORDER BY relationship_id",
        [$id, $id]
    );
    $related = [];
    foreach ($rels as $r) {
        $outgoing = ((int) $r['from_subject_id'] === $id);
        $related[] = [
            'id'                 => (int) ($outgoing ? $r['to_subject_id']    : $r['from_subject_id']),
            'label'              =>        $outgoing ? $r['to_subject_label']  : $r['from_subject_label'],
            'relationship_type'  => $r['relationship_type'],
            'relationship_label' => $r['relationship_label'],
            'direction'          => $outgoing ? 'outgoing' : 'incoming',
            'valid_from'         => $r['valid_from'],
            'valid_to'           => $r['valid_to'],
        ];
    }
    $subject['related_subjects'] = $related;

    return $subject;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $subject = load_subject_detail($id);
        if ($subject === null) {
            json_error('not_found', 'Subject not found.', 404);
        }
        json_response(['subject' => $subject]);
    }

    case 'PATCH': {
        // Must exist before we attempt an update.
        if (db_one("SELECT 1 FROM maludb_subject WHERE subject_id = ?", [$id]) === null) {
            json_error('not_found', 'Subject not found.', 404);
        }

        $body   = body_json();
        $fields = [];
        $params = [];

        if (array_key_exists('label', $body)) {
            $label = trim((string) $body['label']);
            if ($label === '') {
                json_error('validation_failed', 'Field "label" cannot be empty.', 422);
            }
            $fields[] = 'canonical_name = ?'; $params[] = $label;
        }
        if (array_key_exists('type', $body)) {
            $fields[] = 'subject_type = ?';
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
            json_error('bad_request', 'No updatable fields provided (label, type, description, classifier_md).', 400);
        }

        $params[] = $id;
        db_exec("UPDATE maludb_subject SET " . implode(', ', $fields) . " WHERE subject_id = ?", $params);

        json_response(['subject' => load_subject_detail($id)]);
    }

    case 'DELETE': {
        $n = db_exec("DELETE FROM maludb_subject WHERE subject_id = ?", [$id]);
        if ($n === 0) {
            json_error('not_found', 'Subject not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET, PATCH and DELETE.', 405);
}
