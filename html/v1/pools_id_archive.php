<?php
/**
 * /v1/pools/{id}/archive  (requirements.md §4.7)
 *
 *   POST   Archive the pool (409 already_archived if already archived/sealed/tombstoned).
 *
 * Sets lifecycle_state='archived' + archived_at=now() on maludb_memory_pool.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'POST': {
        $pool = db_one("SELECT lifecycle_state, archived_at FROM maludb_memory_pool WHERE pool_id = ?", [$id]);
        if ($pool === null) {
            json_error('not_found', 'Pool not found.', 404);
        }
        if ($pool['archived_at'] !== null || $pool['lifecycle_state'] === 'archived') {
            json_error('already_archived', 'Pool is already archived.', 409);
        }

        db_exec(
            "UPDATE maludb_memory_pool
                SET lifecycle_state = 'archived', archived_at = now(), updated_at = now()
              WHERE pool_id = ?",
            [$id]
        );

        $updated = db_one(
            "SELECT pool_id AS id, pool_name AS name, task_objective AS description,
                    lifecycle_state, archived_at, created_at
               FROM maludb_memory_pool WHERE pool_id = ?",
            [$id]
        );
        $updated['id'] = (int) $updated['id'];

        json_response(['pool' => $updated]);
    }

    default:
        header('Allow: POST');
        json_error('method_not_allowed', 'This endpoint supports POST only.', 405);
}
