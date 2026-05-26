<?php
/**
 * /v1/skills/{id}  (requirements.md §4.8)
 *
 *   GET     Skill detail.
 *   PATCH   Update {name?, description?, version?, visibility?, packaging_kind?, enabled?}.
 *   DELETE  Remove the skill.
 *
 * name -> skill_name. DB enforces visibility/packaging_kind value sets (→ 422).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

function load_skill(int $id): ?array {
    $skill = db_one(
        "SELECT skill_id AS id, skill_name AS name, description, version,
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
        if (db_one("SELECT 1 FROM maludb_skill WHERE skill_id = ?", [$id]) === null) {
            json_error('not_found', 'Skill not found.', 404);
        }

        $body   = body_json();
        $fields = [];
        $params = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                json_error('validation_failed', 'Field "name" cannot be empty.', 422);
            }
            $fields[] = 'skill_name = ?'; $params[] = $name;
        }
        foreach (['description', 'version', 'visibility', 'packaging_kind'] as $f) {
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
