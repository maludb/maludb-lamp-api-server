<?php
/**
 * /v1/attributes  (maludb_core 0.83.0+ — typed attributes on nodes AND edges)
 *
 *   GET   ?target_kind=&target_id=&attr_name=&provenance=&limit=
 *         List typed attributes from maludb_svpor_attribute. The review queue is
 *         ?provenance=suggested (LLM-derived attrs awaiting accept/reject).
 *   POST  Create/upsert an attribute (idempotent on target_kind+target_id+attr_name).
 *
 * An attribute is a typed property of (target_kind, target_id). target_kind is any
 * node kind OR 'svpor_statement' (edge attributes). See svpor_create_attribute() in
 * config/response.php for the accepted body shape. Runs in db_tx_core() because the
 * facade references its malu$* base tables unqualified.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $target_kind = query_str('target_kind', null, 40);
        $attr_name   = query_str('attr_name', null, 200);
        $provenance  = query_str('provenance', null, 40);
        $target_id   = query_int('target_id', null);
        $limit       = query_int('limit', 50, 200);

        $clauses = [];
        $params  = [];
        if ($target_kind !== null && $target_kind !== '') { $clauses[] = "target_kind = ?"; $params[] = $target_kind; }
        if ($attr_name !== null && $attr_name !== '')     { $clauses[] = "attr_name = ?";   $params[] = $attr_name; }
        if ($provenance !== null && $provenance !== '')   { $clauses[] = "provenance = ?";  $params[] = $provenance; }
        if ($target_id !== null) { $clauses[] = "target_id = ?"; $params[] = $target_id; }
        $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

        $rows = db_tx_core(fn() => db_query(
            "SELECT " . svpor_attribute_cols() . "
               FROM maludb_svpor_attribute
               $where
              ORDER BY attribute_id DESC
              LIMIT $limit",
            $params
        ));
        foreach ($rows as &$r) { shape_attribute($r); }
        unset($r);

        json_response(['attributes' => $rows]);
    }

    case 'POST': {
        $body = body_json();
        $attr = db_tx_core(fn() => svpor_create_attribute($body));
        json_response(['attribute' => $attr], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
