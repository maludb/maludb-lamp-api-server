<?php
/**
 * /v1/notes  (requirements.md §4.5)
 *
 *   GET  ?q=&limit=&type=   List notes (memories).
 *   POST                    Create a note. Body: {title, body?, type?, project_id?}
 *
 * Notes are rows in maludb_memory:
 *   id -> memory_id (sequence), title -> title, body -> summary, type -> memory_kind
 *   (default 'note'; 'issue' enables close/reopen), project_id -> payload_jsonb.project_id.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $q     = query_str('q', null, 200);
        $type  = query_str('type', null, 60);
        $limit = query_int('limit', 50, 200);

        $clauses = [];
        $params  = [];
        if ($type !== null && $type !== '') { $clauses[] = "memory_kind = ?"; $params[] = $type; }
        if ($q !== null && $q !== '') {
            $clauses[] = "(title ILIKE ? OR summary ILIKE ?)";
            $params[]  = '%' . $q . '%';
            $params[]  = '%' . $q . '%';
        }
        $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

        $sql = "SELECT memory_id   AS id,
                       title,
                       summary     AS body,
                       memory_kind AS type,
                       (payload_jsonb->>'project_id')::bigint AS project_id,
                       issue_closed_at,
                       created_at
                  FROM maludb_memory
                  $where
                 ORDER BY created_at DESC NULLS LAST, memory_id DESC
                 LIMIT $limit";

        $rows = db_query($sql, $params);
        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['project_id'] = $r['project_id'] === null ? null : (int) $r['project_id'];
        }
        unset($r);

        json_response(['notes' => $rows]);
    }

    case 'POST': {
        $body = body_json();

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            json_error('missing_field', 'Field "title" is required.', 400);
        }
        $text = isset($body['body']) ? (string) $body['body'] : null;
        $type = isset($body['type']) && trim((string) $body['type']) !== '' ? (string) $body['type'] : 'note';

        $payload    = '{}';
        $project_id = null;
        if (array_key_exists('project_id', $body) && $body['project_id'] !== null) {
            if (!is_int($body['project_id'])) {
                json_error('validation_failed', '"project_id" must be an integer.', 422);
            }
            $project_id = (int) $body['project_id'];
            if (db_one("SELECT 1 FROM maludb_project WHERE subject_id = ?", [$project_id]) === null) {
                json_error('validation_failed', 'project_id does not refer to an existing project.', 422);
            }
            $payload = json_encode(['project_id' => $project_id]);
        }

        $note = db_one(
            "INSERT INTO maludb_memory (memory_kind, title, summary, payload_jsonb, recorded_at)
             VALUES (?, ?, ?, ?::jsonb, now())
             RETURNING memory_id AS id, title, summary AS body, memory_kind AS type,
                       issue_closed_at, created_at",
            [$type, $title, $text, $payload]
        );
        $note['id']         = (int) $note['id'];
        $note['project_id'] = $project_id;

        json_response(['note' => $note], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
