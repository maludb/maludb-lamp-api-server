<?php
/**
 * /v1/episodes/{id}/statements  (maludb_core 0.82.0)
 *
 *   GET   The event's links — statements whose object is this episode
 *         (object_kind='episode_object' AND object_id={id}): attendees, attached
 *         documents, decisions. (GET /v1/episodes/{id} via maludb_episode_get also
 *         returns these, with *_label fields resolved.)
 *   POST  Add a link to this event. Same body as POST /v1/statements, except
 *         object_kind/object_id default to this episode.
 *
 * Example (the meeting model — one statement per row, object = the event):
 *   { "verb":"attended",     "subject":"Edward Honour" }          # subject defaults to a person
 *   { "verb":"generated_by", "subject_kind":"document", "subject_id":42 }
 *   { "verb":"made_during",  "subject_kind":"memory",   "subject_id":7 }
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $result = db_tx_core(function ($pdo) use ($id) {
            if (db_one("SELECT 1 FROM maludb_episode WHERE episode_id = ?", [$id]) === null) {
                return null;
            }
            return db_query(
                "SELECT " . svpor_statement_cols() . "
                   FROM maludb_svpor_statement
                  WHERE object_kind = 'episode_object' AND object_id = ?
                  ORDER BY statement_id DESC",
                [$id]
            );
        });
        if ($result === null) {
            json_error('not_found', 'Episode not found.', 404);
        }
        foreach ($result as &$r) { shape_statement($r); }
        unset($r);

        json_response(['statements' => $result]);
    }

    case 'POST': {
        $body = body_json();
        $stmt = db_tx_core(function ($pdo) use ($id, $body) {
            if (db_one("SELECT 1 FROM maludb_episode WHERE episode_id = ?", [$id]) === null) {
                return null;
            }
            return svpor_create_statement($body, ['kind' => 'episode_object', 'id' => $id]);
        });
        if ($stmt === null) {
            json_error('not_found', 'Episode not found.', 404);
        }
        json_response(['statement' => $stmt], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
