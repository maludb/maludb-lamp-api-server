<?php
/**
 * /v1/pools/{id}  (requirements.md §4.7)
 *
 *   GET     Pool detail.
 *   PATCH   Update {name?, description?}.
 *
 * No DELETE in v1. name -> pool_name, description -> task_objective.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

function load_pool(int $id): ?array {
    $pool = db_one(
        "SELECT pool_id AS id, pool_name AS name, task_objective AS description,
                lifecycle_state, archived_at, created_at, updated_at
           FROM maludb_memory_pool
          WHERE pool_id = ?",
        [$id]
    );
    if ($pool === null) {
        return null;
    }
    $pool['id'] = (int) $pool['id'];
    return $pool;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $pool = load_pool($id);
        if ($pool === null) {
            json_error('not_found', 'Pool not found.', 404);
        }
        json_response(['pool' => $pool]);
    }

    case 'PATCH': {
        if (db_one("SELECT 1 FROM maludb_memory_pool WHERE pool_id = ?", [$id]) === null) {
            json_error('not_found', 'Pool not found.', 404);
        }

        $body   = body_json();
        $fields = [];
        $params = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                json_error('validation_failed', 'Field "name" cannot be empty.', 422);
            }
            $fields[] = 'pool_name = ?'; $params[] = $name;
        }
        if (array_key_exists('description', $body)) {
            $fields[] = 'task_objective = ?';
            $params[] = $body['description'] === null ? null : (string) $body['description'];
        }
        if (!$fields) {
            json_error('bad_request', 'No updatable fields provided (name, description).', 400);
        }

        $fields[] = 'updated_at = now()';
        $params[] = $id;
        db_exec("UPDATE maludb_memory_pool SET " . implode(', ', $fields) . " WHERE pool_id = ?", $params);

        json_response(['pool' => load_pool($id)]);
    }

    default:
        header('Allow: GET, PATCH');
        json_error('method_not_allowed', 'This endpoint supports GET and PATCH.', 405);
}
