<?php
/**
 * /v1/verb-types  (requirements.md §4.3)
 *
 *   GET   The registered verb types (feeds the "Type" dropdown). Read-only.
 *
 * Source: maludb_verb_type. These are the only values maludb_verb.verb_type
 * accepts (the DB trigger rejects others).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $rows = db_query(
            "SELECT verb_type AS type,
                    display_name,
                    semantic_class,
                    description,
                    sort_order
               FROM maludb_verb_type
              ORDER BY sort_order, verb_type"
        );
        foreach ($rows as &$r) {
            $r['sort_order'] = $r['sort_order'] === null ? null : (int) $r['sort_order'];
        }
        unset($r);

        json_response(['verb_types' => $rows]);
    }

    default:
        header('Allow: GET');
        json_error('method_not_allowed', 'This endpoint supports GET only.', 405);
}
