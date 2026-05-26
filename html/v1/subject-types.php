<?php
/**
 * /v1/subject-types  (requirements.md §4.3)
 *
 *   GET   The registered subject types (feeds the "Type" dropdown). Read-only.
 *
 * Source: maludb_subject_type. These are the only values maludb_subject.subject_type
 * accepts (the DB trigger rejects others).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $rows = db_query(
            "SELECT subject_type AS type,
                    display_name,
                    description,
                    sort_order
               FROM maludb_subject_type
              ORDER BY sort_order, subject_type"
        );
        foreach ($rows as &$r) {
            $r['sort_order'] = $r['sort_order'] === null ? null : (int) $r['sort_order'];
        }
        unset($r);

        json_response(['subject_types' => $rows]);
    }

    default:
        header('Allow: GET');
        json_error('method_not_allowed', 'This endpoint supports GET only.', 405);
}
