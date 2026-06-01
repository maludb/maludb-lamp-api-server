<?php
/**
 * /v1/documents-backfill  (maludb_core 0.87.0 — document graph onboarding)
 *
 *   POST   Run maludb_document_graph_backfill() for the current tenant schema: resolve/link
 *          every pre-0.87 document tag (project/subject/stakeholder) into the unified graph —
 *          document→subject edges + resolved tag_object_id + primary_project_id. Idempotent;
 *          safe to re-run. Returns { "linked": <int> } (tags newly linked this run).
 *
 * Admin/onboarding action: call once after enabling memory for a schema that already holds
 * documents. Runs in db_tx_core() (the facade resolves under current_schema()).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('method_not_allowed', 'This endpoint supports POST.', 405);
}

$linked = db_tx_core(fn() => db_one("SELECT maludb_document_graph_backfill() AS n"));

json_response(['linked' => (int) $linked['n']]);
