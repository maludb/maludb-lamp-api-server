<?php
/**
 * /v1/projects/{id}/verbs  (requirements.md §4.6)
 *
 *   POST  Link one verb ({verb_id}) via maludb_svpor_relationship_create
 *         ('subject', project_id, 'verb', verb_id, 'has_member').
 *   PUT   Replace the full set — NOT IMPLEMENTED (needs the svpor delete helper to
 *         remove existing edges; see docs/db-requirements.md §1).
 *
 * The create helper is not idempotent and does not validate the target, so the API
 * checks existence + dedupes. Linked verbs are readable via GET /v1/projects/{id}.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'POST': {
        if (db_one("SELECT 1 FROM maludb_project WHERE subject_id = ?", [$id]) === null) {
            json_error('not_found', 'Project not found.', 404);
        }

        $body = body_json();
        if (!array_key_exists('verb_id', $body) || !is_int($body['verb_id'])) {
            json_error('missing_field', 'Field "verb_id" (integer) is required.', 400);
        }
        $vid = (int) $body['verb_id'];

        $verb = db_one(
            "SELECT verb_id AS id, canonical_name AS name, verb_type AS type
               FROM maludb_verb WHERE verb_id = ?",
            [$vid]
        );
        if ($verb === null) {
            json_error('validation_failed', 'verb_id does not refer to an existing verb.', 422);
        }

        $dup = db_one(
            "SELECT 1 FROM maludb_svpor_relationship
              WHERE source_kind='subject' AND source_id=? AND target_kind='verb'
                AND target_id=? AND relationship_type='has_member'",
            [$id, $vid]
        );
        if ($dup !== null) {
            json_error('conflict', 'That verb is already linked to the project.', 409);
        }

        $row = db_one(
            "SELECT maludb_svpor_relationship_create('subject', ?, 'verb', ?, 'has_member', NULL, '{}'::jsonb, NULL) AS edge_id",
            [$id, $vid]
        );
        $verb['id'] = (int) $verb['id'];

        json_response(['verb' => $verb, 'edge_id' => (int) $row['edge_id']], 201);
    }

    case 'PUT':
        json_error(
            'not_implemented',
            'Replacing a project\'s verb set requires removing existing links, which needs the SVPOR delete helper not available yet. See docs/db-requirements.md §1.',
            501
        );

    default:
        header('Allow: POST, PUT');
        json_error('method_not_allowed', 'This endpoint supports POST and PUT.', 405);
}
