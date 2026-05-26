<?php
/**
 * /v1/projects/{id}/subjects  (requirements.md §4.6)
 *
 *   POST  Link one subject ({subject_id}).
 *   PUT   Replace the full set ({subject_ids: [...]}).
 *
 * NOT IMPLEMENTED in v1: project↔subject links are SVPOR graph edges
 * (maludb_svpor_relationship → malu$relationship_edge), a multi-table view the API
 * user cannot insert into. Needs a granted DBMS-project function — see
 * docs/db-requirements.md. (Linked subjects are already readable via GET /v1/projects/{id}.)
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'POST':
    case 'PUT':
        json_error(
            'not_implemented',
            'Linking subjects to a project writes an SVPOR graph edge, which requires a DBMS-project function not available to the API yet. See docs/db-requirements.md.',
            501
        );

    default:
        header('Allow: POST, PUT');
        json_error('method_not_allowed', 'This endpoint supports POST and PUT.', 405);
}
