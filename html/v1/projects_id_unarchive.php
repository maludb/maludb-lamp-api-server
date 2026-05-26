<?php
/**
 * /v1/projects/{id}/unarchive  (requirements.md §4.6)
 *
 *   POST  Unarchive the project (409 not_archived if not archived).
 *
 * NOT IMPLEMENTED in v1: no archive column exists (see projects_id_archive.php /
 * docs/db-requirements.md).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'POST':
        json_error(
            'not_implemented',
            'Project unarchiving needs an archive column on the subject/project schema, which is a DBMS-project change. See docs/db-requirements.md.',
            501
        );

    default:
        header('Allow: POST');
        json_error('method_not_allowed', 'This endpoint supports POST only.', 405);
}
