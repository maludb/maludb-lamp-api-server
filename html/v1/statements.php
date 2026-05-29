<?php
/**
 * /v1/statements  (maludb_core 0.82.0 — SVO statement layer)
 *
 *   GET   ?provenance=&object_kind=&object_id=&subject_kind=&subject_id=&verb_id=&limit=
 *         List statements from maludb_svpor_statement. The review queue is just
 *         ?provenance=suggested (machine-derived links awaiting accept/reject).
 *   POST  Create a statement (general; object specified in the body).
 *
 * A statement is (subject_kind, subject_id) --verb_id--> (object_kind, object_id).
 * Create is idempotent on those five fields. See svpor_create_statement() in
 * config/response.php for the accepted body shape (verb/subject name resolution).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $provenance   = query_str('provenance', null, 40);
        $object_kind  = query_str('object_kind', null, 40);
        $subject_kind = query_str('subject_kind', null, 40);
        $object_id    = query_int('object_id', null);
        $subject_id   = query_int('subject_id', null);
        $verb_id      = query_int('verb_id', null);
        $limit        = query_int('limit', 50, 200);

        $clauses = [];
        $params  = [];
        if ($provenance !== null && $provenance !== '')     { $clauses[] = "provenance = ?";   $params[] = $provenance; }
        if ($object_kind !== null && $object_kind !== '')   { $clauses[] = "object_kind = ?";  $params[] = $object_kind; }
        if ($subject_kind !== null && $subject_kind !== '') { $clauses[] = "subject_kind = ?"; $params[] = $subject_kind; }
        if ($object_id !== null)  { $clauses[] = "object_id = ?";  $params[] = $object_id; }
        if ($subject_id !== null) { $clauses[] = "subject_id = ?"; $params[] = $subject_id; }
        if ($verb_id !== null)    { $clauses[] = "verb_id = ?";    $params[] = $verb_id; }
        $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

        $rows = db_tx_core(fn() => db_query(
            "SELECT " . svpor_statement_cols() . "
               FROM maludb_svpor_statement
               $where
              ORDER BY statement_id DESC
              LIMIT $limit",
            $params
        ));
        foreach ($rows as &$r) { shape_statement($r); }
        unset($r);

        json_response(['statements' => $rows]);
    }

    case 'POST': {
        $body = body_json();
        $stmt = db_tx_core(fn() => svpor_create_statement($body));
        json_response(['statement' => $stmt], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
