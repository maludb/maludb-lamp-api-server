<?php
/**
 * /v1/projects/{id}/subjects  (requirements.md §4.6)
 *
 *   POST  Link one subject ({subject_id}) via maludb_svpor_relationship_create
 *         ('subject', project_id, 'subject', subject_id, 'has_member').
 *   PUT   Replace the full set — NOT IMPLEMENTED (needs the svpor delete helper to
 *         remove existing edges; see docs/db-requirements.md §1).
 *
 * The create helper is not idempotent and does not validate the target, so the API
 * checks existence + dedupes. Linked subjects are readable via GET /v1/projects/{id}.
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
        if (!array_key_exists('subject_id', $body) || !is_int($body['subject_id'])) {
            json_error('missing_field', 'Field "subject_id" (integer) is required.', 400);
        }
        $sid = (int) $body['subject_id'];
        if ($sid === $id) {
            json_error('validation_failed', 'A project cannot link to itself.', 422);
        }

        $subject = db_one(
            "SELECT subject_id AS id, canonical_name AS name, subject_type AS type
               FROM maludb_subject WHERE subject_id = ?",
            [$sid]
        );
        if ($subject === null) {
            json_error('validation_failed', 'subject_id does not refer to an existing subject.', 422);
        }

        // The svpor create helper is not idempotent — dedupe here.
        $dup = db_one(
            "SELECT 1 FROM maludb_svpor_relationship
              WHERE source_kind='subject' AND source_id=? AND target_kind='subject'
                AND target_id=? AND relationship_type='has_member'",
            [$id, $sid]
        );
        if ($dup !== null) {
            json_error('conflict', 'That subject is already linked to the project.', 409);
        }

        $row = db_one(
            "SELECT maludb_svpor_relationship_create('subject', ?, 'subject', ?, 'has_member', NULL, '{}'::jsonb, NULL) AS edge_id",
            [$id, $sid]
        );
        $subject['id'] = (int) $subject['id'];

        json_response(['subject' => $subject, 'edge_id' => (int) $row['edge_id']], 201);
    }

    case 'PUT': {
        if (db_one("SELECT 1 FROM maludb_project WHERE subject_id = ?", [$id]) === null) {
            json_error('not_found', 'Project not found.', 404);
        }
        $body = body_json();
        if (!array_key_exists('subject_ids', $body) || !is_array($body['subject_ids'])) {
            json_error('missing_field', 'Field "subject_ids" (array of integers) is required.', 400);
        }
        $want = [];
        foreach ($body['subject_ids'] as $v) {
            if (!is_int($v)) {
                json_error('validation_failed', 'subject_ids must be integers.', 422);
            }
            if ($v === $id) {
                json_error('validation_failed', 'A project cannot link to itself.', 422);
            }
            if (db_one("SELECT 1 FROM maludb_subject WHERE subject_id = ?", [$v]) === null) {
                json_error('validation_failed', "subject_id $v does not refer to an existing subject.", 422);
            }
            $want[$v] = true;
        }
        $want = array_keys($want);

        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $cur = array_map('intval', array_column(
                db_query("SELECT target_id FROM maludb_svpor_relationship
                           WHERE source_kind='subject' AND source_id=? AND target_kind='subject'
                             AND relationship_type='has_member'", [$id]),
                'target_id'
            ));
            foreach ($cur as $c) {
                if (!in_array($c, $want, true)) {
                    db_one("SELECT maludb_svpor_relationship_delete('subject', ?, 'subject', ?, 'has_member')", [$id, $c]);
                }
            }
            foreach ($want as $w) {
                if (!in_array($w, $cur, true)) {
                    db_one("SELECT maludb_svpor_relationship_create('subject', ?, 'subject', ?, 'has_member', NULL, '{}'::jsonb, NULL)", [$id, $w]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        $subjects = db_query(
            "SELECT s.subject_id AS id, s.canonical_name AS name, s.subject_type AS type
               FROM maludb_svpor_relationship r
               JOIN maludb_subject s ON s.subject_id = r.target_id
              WHERE r.source_kind='subject' AND r.source_id=? AND r.target_kind='subject'
                AND r.relationship_type='has_member'
              ORDER BY s.canonical_name",
            [$id]
        );
        foreach ($subjects as &$x) { $x['id'] = (int) $x['id']; }
        unset($x);

        json_response(['subjects' => $subjects]);
    }

    default:
        header('Allow: POST, PUT');
        json_error('method_not_allowed', 'This endpoint supports POST and PUT.', 405);
}
