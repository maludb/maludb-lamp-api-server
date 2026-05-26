<?php
/**
 * /v1/projects/{id}/archive  (requirements.md §4.6)
 *
 *   POST  Archive the project (409 already_archived if already archived).
 *
 * NOT IMPLEMENTED in v1: maludb_subject/maludb_project has no archive column
 * (no archived_at / status / state), so there is nowhere to record archived state
 * without a schema change. See docs/db-requirements.md.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'POST':
        json_error(
            'not_implemented',
            'Project archiving needs an archive column on the subject/project schema, which is a DBMS-project change. See docs/db-requirements.md.',
            501
        );

    default:
        header('Allow: POST');
        json_error('method_not_allowed', 'This endpoint supports POST only.', 405);
}
