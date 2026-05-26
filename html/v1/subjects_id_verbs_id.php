<?php
/**
 * /v1/subjects/{id}/verbs/{verbId}  (requirements.md §4.1)
 *
 *   DELETE   Unlink a verb from a subject. NOT IMPLEMENTED in v1: there is no
 *            granted compartment-delete path for the API user (see
 *            docs/db-requirements.md).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
path_id();
path_sub_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'DELETE':
        json_error(
            'not_implemented',
            'Unlinking a verb from a subject removes a vector compartment, which requires a DBMS-project function not available to the API yet. See docs/db-requirements.md.',
            501
        );

    default:
        header('Allow: DELETE');
        json_error('method_not_allowed', 'This endpoint supports DELETE only.', 405);
}
