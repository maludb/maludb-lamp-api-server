<?php
/**
 * /v1/pools  (requirements.md §4.7)
 *
 *   GET  ?q=&limit=   List memory pools (excludes tombstoned).
 *   POST              Create a pool. Body: {name, description?}
 *
 * Source: maludb_memory_pool (direct-INSERT view; pool_id from sequence).
 *   name        -> pool_name
 *   description -> task_objective
 *   creation_kind is set to 'api'; lifecycle_state defaults to 'active'.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $q     = query_str('q', null, 200);
        $limit = query_int('limit', 50, 200);

        $where  = "WHERE (lifecycle_state IS DISTINCT FROM 'tombstoned')";
        $params = [];
        if ($q !== null && $q !== '') {
            $where   .= " AND (pool_name ILIKE ? OR task_objective ILIKE ?)";
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $sql = "SELECT pool_id        AS id,
                       pool_name      AS name,
                       task_objective AS description,
                       lifecycle_state,
                       archived_at,
                       created_at
                  FROM maludb_memory_pool
                  $where
                 ORDER BY pool_name
                 LIMIT $limit";

        $rows = db_query($sql, $params);
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; }
        unset($r);

        json_response(['pools' => $rows]);
    }

    case 'POST': {
        $body = body_json();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_error('missing_field', 'Field "name" is required.', 400);
        }
        $description = isset($body['description']) ? (string) $body['description'] : null;

        // pool_id is sequence-assigned; creation_kind must be one of prompt|api|mcp|sql.
        $created = db_one(
            "INSERT INTO maludb_memory_pool (pool_name, task_objective, creation_kind, created_at)
             VALUES (?, ?, 'api', now())
             RETURNING pool_id AS id, pool_name AS name, task_objective AS description,
                       lifecycle_state, archived_at, created_at",
            [$name, $description]
        );
        $created['id'] = (int) $created['id'];

        json_response(['pool' => $created], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
