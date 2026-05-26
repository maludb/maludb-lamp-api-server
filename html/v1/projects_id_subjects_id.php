<?php
/**
 * /v1/projects/{id}/subjects/{sid}  (requirements.md §4.6)
 *
 *   DELETE  Unlink one subject from the project.
 *
 * NOT IMPLEMENTED in v1: see projects_id_subjects.php / docs/db-requirements.md
 * (SVPOR edge writes need a granted DBMS-project function).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
path_id();
path_sub_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'DELETE':
        json_error(
            'not_implemented',
            'Unlinking a subject from a project removes an SVPOR graph edge, which requires a DBMS-project function not available to the API yet. See docs/db-requirements.md.',
            501
        );

    default:
        header('Allow: DELETE');
        json_error('method_not_allowed', 'This endpoint supports DELETE only.', 405);
}
