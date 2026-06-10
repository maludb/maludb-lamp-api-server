<?php
/**
 * /v1/skills/{id}  (requirements.md §4.8)
 *
 *   GET     Skill detail.
 *   PATCH   Update {name?, description?, version?, visibility?, packaging_kind?, enabled?}.
 *   DELETE  Remove the skill.
 *
 * name -> skill_name. DB enforces visibility/packaging_kind value sets (→ 422).
 * Registered agent skills (bundle_hash set, maludb_core 0.97.0) are content-immutable:
 * PATCH rejects name/markdown/version/packaging_kind with 409 skill_content_immutable
 * (re-upload via POST /v1/skills/ingest); description/visibility/enabled stay editable.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

function load_skill(int $id): ?array {
    $skill = db_one(
        "SELECT skill_id AS id, skill_name AS name, description, markdown, version,
                visibility, packaging_kind, enabled, created_at, updated_at
           FROM maludb_skill
          WHERE skill_id = ?",
        [$id]
    );
    if ($skill === null) {
        return null;
    }
    $skill['id']      = (int) $skill['id'];
    $skill['enabled'] = $skill['enabled'] === null ? null : (bool) $skill['enabled'];
    return $skill;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $skill = load_skill($id);
        if ($skill === null) {
            json_error('not_found', 'Skill not found.', 404);
        }
        json_response(['skill' => $skill]);
    }

    case 'PATCH': {
        $row = db_one("SELECT bundle_hash FROM maludb_skill WHERE skill_id = ?", [$id]);
        if ($row === null) {
            json_error('not_found', 'Skill not found.', 404);
        }

        $body   = body_json();

        // Registered agent skills (bundle_hash set, 0.97.0) are content-immutable (a DB
        // trigger enforces this too); a changed bundle must be re-ingested as a new skill
        // version via POST /v1/skills/ingest. Lifecycle fields stay editable.
        if ($row['bundle_hash'] !== null && $row['bundle_hash'] !== '') {
            $blocked = array_values(array_filter(
                ['name', 'markdown', 'version', 'packaging_kind'],
                fn($f) => array_key_exists($f, $body)
            ));
            if ($blocked !== []) {
                json_error(
                    'skill_content_immutable',
                    'Fields ' . implode(', ', $blocked)
                    . ' are immutable on a registered agent skill; re-upload the changed bundle'
                    . ' via POST /v1/skills/ingest (it becomes a new version with fork lineage).'
                    . ' Editable here: description, visibility, enabled.',
                    409
                );
            }
        }

        $fields = [];
        $params = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                json_error('validation_failed', 'Field "name" cannot be empty.', 422);
            }
            $fields[] = 'skill_name = ?'; $params[] = $name;
        }
        foreach (['description', 'markdown', 'version', 'visibility', 'packaging_kind'] as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "$f = ?";
                $params[] = $body[$f] === null ? null : (string) $body[$f];
            }
        }
        if (array_key_exists('enabled', $body)) {
            $fields[] = 'enabled = ?'; $params[] = $body['enabled'] ? 'true' : 'false';
        }
        if (!$fields) {
            json_error('bad_request', 'No updatable fields provided (name, description, version, visibility, packaging_kind, enabled).', 400);
        }

        $fields[] = 'updated_at = now()';
        $params[] = $id;
        db_exec("UPDATE maludb_skill SET " . implode(', ', $fields) . " WHERE skill_id = ?", $params);

        json_response(['skill' => load_skill($id)]);
    }

    case 'DELETE': {
        $n = db_exec("DELETE FROM maludb_skill WHERE skill_id = ?", [$id]);
        if ($n === 0) {
            json_error('not_found', 'Skill not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET, PATCH and DELETE.', 405);
}
