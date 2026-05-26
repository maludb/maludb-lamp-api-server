<?php
/**
 * /v1/subjects/{id}/related-subjects  (requirements.md §4.1)
 *
 *   GET    List the subjects related to this subject (either endpoint).
 *   POST   Link a related subject. Body: {related_subject_id, relationship_type?}
 *          relationship_type defaults to 'related_to'.
 *
 * Relationships live in maludb_subject_relationship (insertable single-table view).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

/** The other endpoint of each relationship row, mapped for output. */
function map_related(array $rels, int $id): array {
    $out = [];
    foreach ($rels as $r) {
        $outgoing = ((int) $r['from_subject_id'] === $id);
        $out[] = [
            'id'                 => (int) ($outgoing ? $r['to_subject_id']   : $r['from_subject_id']),
            'label'              =>        $outgoing ? $r['to_subject_label'] : $r['from_subject_label'],
            'relationship_type'  => $r['relationship_type'],
            'relationship_label' => $r['relationship_label'],
            'direction'          => $outgoing ? 'outgoing' : 'incoming',
        ];
    }
    return $out;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $subject = db_one("SELECT subject_id FROM maludb_subject WHERE subject_id = ?", [$id]);
        if ($subject === null) {
            json_error('not_found', 'Subject not found.', 404);
        }
        $rels = db_query(
            "SELECT relationship_id, from_subject_id, to_subject_id,
                    from_subject_label, to_subject_label,
                    relationship_type, label AS relationship_label
               FROM maludb_subject_relationship
              WHERE from_subject_id = ? OR to_subject_id = ?
              ORDER BY relationship_id",
            [$id, $id]
        );
        json_response(['related_subjects' => map_related($rels, $id)]);
    }

    case 'POST': {
        $me = db_one("SELECT canonical_name FROM maludb_subject WHERE subject_id = ?", [$id]);
        if ($me === null) {
            json_error('not_found', 'Subject not found.', 404);
        }

        $body = body_json();
        if (!array_key_exists('related_subject_id', $body) || !is_int($body['related_subject_id'])) {
            json_error('missing_field', 'Field "related_subject_id" (integer) is required.', 400);
        }
        $other_id = (int) $body['related_subject_id'];
        if ($other_id === $id) {
            json_error('validation_failed', 'A subject cannot be related to itself.', 422);
        }
        $rtype = isset($body['relationship_type']) && trim((string) $body['relationship_type']) !== ''
            ? (string) $body['relationship_type']
            : 'related_to';

        $other = db_one("SELECT canonical_name FROM maludb_subject WHERE subject_id = ?", [$other_id]);
        if ($other === null) {
            json_error('validation_failed', 'related_subject_id does not refer to an existing subject.', 422);
        }

        // Reject an exact duplicate (same direction + type).
        $dup = db_one(
            "SELECT 1 FROM maludb_subject_relationship
              WHERE from_subject_id = ? AND to_subject_id = ? AND relationship_type = ?",
            [$id, $other_id, $rtype]
        );
        if ($dup !== null) {
            json_error('conflict', 'That related-subject link already exists.', 409);
        }

        db_exec(
            "INSERT INTO maludb_subject_relationship
                 (relationship_id, from_subject_id, to_subject_id,
                  from_subject_label, to_subject_label, relationship_type, created_at)
             SELECT COALESCE(MAX(relationship_id), 0) + 1, ?, ?, ?, ?, ?, now()
               FROM maludb_subject_relationship",
            [$id, $other_id, $me['canonical_name'], $other['canonical_name'], $rtype]
        );

        json_response([
            'related_subject' => [
                'id'                 => $other_id,
                'label'              => $other['canonical_name'],
                'relationship_type'  => $rtype,
                'relationship_label' => null,
                'direction'          => 'outgoing',
            ],
        ], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
