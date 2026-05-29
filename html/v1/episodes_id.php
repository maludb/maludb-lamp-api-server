<?php
/**
 * /v1/episodes/{id}  (maludb_core 0.82.0)
 *
 *   GET     The assembled event: { episode, statements[], details[] } via
 *           maludb_episode_get(id). statements[] are every SVO link whose subject or
 *           object is this episode (attendees, attached documents, decisions) with
 *           *_label fields already resolved.
 *   PATCH   Update the episode (title/summary/kind/payload/occurred_at/occurred_until/
 *           sensitivity/provenance/lifecycle_state) via UPDATE maludb_episode.
 *   DELETE  Remove the episode.
 *
 * provenance ∈ {provided,suggested,accepted,rejected} (PATCH provenance is the
 * accept/reject transition for machine-suggested events). lifecycle_state /
 * sensitivity value sets are DB-enforced → 422.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

/**
 * Assembled event via maludb_episode_get(); null when the episode doesn't exist.
 * Decoded as objects (not assoc) so the DB's jsonb shape is preserved faithfully —
 * an empty payload_jsonb stays {} rather than collapsing to [].
 */
function load_episode(int $id): ?\stdClass {
    $row = db_one("SELECT maludb_episode_get(?) AS j", [$id]);
    if ($row === null || $row['j'] === null) {
        return null;
    }
    return json_decode($row['j']);
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $event = db_tx_core(fn() => load_episode($id));
        if ($event === null) {
            json_error('not_found', 'Episode not found.', 404);
        }
        json_response($event);
    }

    case 'PATCH': {
        $body = body_json();

        // Map request fields → (column, value, placeholder-with-optional-cast).
        $fields = [];
        $params = [];
        $set = function (string $col, $val, string $ph = '?') use (&$fields, &$params) {
            $fields[] = "$col = $ph";
            $params[] = $val;
        };

        if (array_key_exists('title', $body)) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                json_error('validation_failed', 'Field "title" cannot be empty.', 422);
            }
            $set('title', $title);
        }
        if (array_key_exists('summary', $body))     { $set('summary', $body['summary'] === null ? null : (string) $body['summary']); }
        if (array_key_exists('kind', $body))        { $set('episode_kind', (string) $body['kind']); }
        if (array_key_exists('payload', $body))     { $set('payload_jsonb', $body['payload'] === null ? null : json_encode($body['payload']), '?::jsonb'); }
        if (array_key_exists('occurred_at', $body)) { $set('occurred_at', $body['occurred_at'] === null ? null : (string) $body['occurred_at'], '?::timestamptz'); }
        if (array_key_exists('occurred_until', $body)) { $set('occurred_until', $body['occurred_until'] === null ? null : (string) $body['occurred_until'], '?::timestamptz'); }
        if (array_key_exists('sensitivity', $body))     { $set('sensitivity', (string) $body['sensitivity']); }
        if (array_key_exists('provenance', $body))      { $set('provenance', (string) $body['provenance']); }
        if (array_key_exists('lifecycle_state', $body)) { $set('lifecycle_state', (string) $body['lifecycle_state']); }

        if (!$fields) {
            json_error('bad_request', 'No updatable fields provided (title, summary, kind, payload, occurred_at, occurred_until, sensitivity, provenance, lifecycle_state).', 400);
        }

        $params[] = $id;
        $event = db_tx_core(function ($pdo) use ($fields, $params, $id) {
            $n = db_exec("UPDATE maludb_episode SET " . implode(', ', $fields) . " WHERE episode_id = ?", $params);
            if ($n === 0) {
                return null;
            }
            return load_episode($id);
        });
        if ($event === null) {
            json_error('not_found', 'Episode not found.', 404);
        }
        json_response($event);
    }

    case 'DELETE': {
        $n = db_tx_core(fn() => db_exec("DELETE FROM maludb_episode WHERE episode_id = ?", [$id]));
        if ($n === 0) {
            json_error('not_found', 'Episode not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET, PATCH and DELETE.', 405);
}
