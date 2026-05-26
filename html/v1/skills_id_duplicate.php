<?php
/**
 * /v1/skills/{id}/duplicate  (requirements.md §4.8)
 *
 *   POST   Duplicate (fork) the skill. Body: {name?, version?}. Returns the new skill (201).
 *
 * Uses the DB helper maludb_skill_fork(source_owner_schema, source_skill_id,
 * new_skill_name, new_version). Forking is gated by the DB (only published/forkable
 * skills); a non-forkable source yields 422.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'POST': {
        $src = db_one(
            "SELECT skill_id, skill_name, COALESCE(owner_schema, current_schema()) AS owner_schema
               FROM maludb_skill WHERE skill_id = ?",
            [$id]
        );
        if ($src === null) {
            json_error('not_found', 'Skill not found.', 404);
        }

        $body        = body_json();
        $new_name    = isset($body['name']) && trim((string) $body['name']) !== '' ? (string) $body['name'] : null;
        $new_version = isset($body['version']) && trim((string) $body['version']) !== '' ? (string) $body['version'] : '1.0.0';

        try {
            $row = db_one(
                "SELECT maludb_skill_fork(?, ?, ?, ?) AS id",
                [$src['owner_schema'], $id, $new_name, $new_version]
            );
        } catch (PDOException $e) {
            // e.g. "source skill … is not forkable" — a precondition, not a server error.
            json_error('validation_failed', pg_error_message($e), 422);
        }

        $new_id = (int) $row['id'];
        $skill  = db_one(
            "SELECT skill_id AS id, skill_name AS name, description, version,
                    visibility, packaging_kind, enabled, source_skill_id, created_at
               FROM maludb_skill WHERE skill_id = ?",
            [$new_id]
        );
        $skill['id']              = (int) $skill['id'];
        $skill['source_skill_id'] = $skill['source_skill_id'] === null ? null : (int) $skill['source_skill_id'];
        $skill['enabled']         = $skill['enabled'] === null ? null : (bool) $skill['enabled'];

        json_response(['skill' => $skill], 201);
    }

    default:
        header('Allow: POST');
        json_error('method_not_allowed', 'This endpoint supports POST only.', 405);
}
