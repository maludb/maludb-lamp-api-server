<?php
/**
 * /v1/episode-types/{id}  (maludb_core 0.82.0)
 *
 *   PATCH   Update {episode_type?, description?, display_order?}.
 *   DELETE  Remove the type from the picker.
 *
 * Source: maludb_episode_type (writable per-schema view). The label is
 * case-insensitive unique — a colliding update raises 23505, mapped to 409.
 *
 * Deleting a type does NOT affect episodes already tagged with that kind string:
 * episode.episode_kind is free text with no FK to this list.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

function load_episode_type(int $id): ?array {
    $row = db_one(
        "SELECT episode_type_id AS id, episode_type, description, display_order, created_at
           FROM maludb_episode_type
          WHERE episode_type_id = ?",
        [$id]
    );
    if ($row === null) {
        return null;
    }
    $row['id']            = (int) $row['id'];
    $row['display_order'] = $row['display_order'] === null ? null : (int) $row['display_order'];
    return $row;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'PATCH': {
        if (db_one("SELECT 1 FROM maludb_episode_type WHERE episode_type_id = ?", [$id]) === null) {
            json_error('not_found', 'Episode type not found.', 404);
        }

        $body   = body_json();
        $fields = [];
        $params = [];

        if (array_key_exists('episode_type', $body)) {
            $label = trim((string) $body['episode_type']);
            if ($label === '') {
                json_error('validation_failed', 'Field "episode_type" cannot be empty.', 422);
            }
            $fields[] = 'episode_type = ?'; $params[] = $label;
        }
        if (array_key_exists('description', $body)) {
            $fields[] = 'description = ?';
            $params[] = $body['description'] === null ? null : (string) $body['description'];
        }
        if (array_key_exists('display_order', $body)) {
            if ($body['display_order'] !== null && !is_int($body['display_order'])) {
                json_error('validation_failed', '"display_order" must be an integer.', 422);
            }
            $fields[] = 'display_order = ?';
            $params[] = $body['display_order'] === null ? null : (int) $body['display_order'];
        }
        if (!$fields) {
            json_error('bad_request', 'No updatable fields provided (episode_type, description, display_order).', 400);
        }

        $params[] = $id;
        db_exec("UPDATE maludb_episode_type SET " . implode(', ', $fields) . " WHERE episode_type_id = ?", $params);

        json_response(['episode_type' => load_episode_type($id)]);
    }

    case 'DELETE': {
        $n = db_exec("DELETE FROM maludb_episode_type WHERE episode_type_id = ?", [$id]);
        if ($n === 0) {
            json_error('not_found', 'Episode type not found.', 404);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports PATCH and DELETE.', 405);
}
