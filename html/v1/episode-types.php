<?php
/**
 * /v1/episode-types  (maludb_core 0.82.0)
 *
 *   GET    The tenant's episode-type picker list (feeds the event-kind dropdown).
 *   POST   Add a type. Body: {episode_type, description?, display_order?}
 *
 * Source: maludb_episode_type (writable per-schema view; episode_type_id from
 * sequence). The label is case-insensitive unique — a duplicate raises 23505,
 * mapped to 409 by the global handler.
 *
 * Advisory only: episode.episode_kind is free text with no FK here, so an episode
 * may carry a kind that isn't in this list. Seeded: Meeting, Daily Standup, Review,
 * Retrospective, 1:1, Incident, Planning.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $rows = db_query(
            "SELECT episode_type_id AS id,
                    episode_type,
                    description,
                    display_order,
                    created_at
               FROM maludb_episode_type
              ORDER BY display_order NULLS LAST, episode_type"
        );
        foreach ($rows as &$r) {
            $r['id']            = (int) $r['id'];
            $r['display_order'] = $r['display_order'] === null ? null : (int) $r['display_order'];
        }
        unset($r);

        json_response(['episode_types' => $rows]);
    }

    case 'POST': {
        $body = body_json();

        $label = trim((string) ($body['episode_type'] ?? ''));
        if ($label === '') {
            json_error('missing_field', 'Field "episode_type" is required.', 400);
        }
        $description   = isset($body['description']) ? (string) $body['description'] : null;
        $display_order = null;
        if (array_key_exists('display_order', $body) && $body['display_order'] !== null) {
            if (!is_int($body['display_order'])) {
                json_error('validation_failed', '"display_order" must be an integer.', 422);
            }
            $display_order = (int) $body['display_order'];
        }

        $created = db_one(
            "INSERT INTO maludb_episode_type (episode_type, description, display_order)
             VALUES (?, ?, ?)
             RETURNING episode_type_id AS id, episode_type, description, display_order, created_at",
            [$label, $description, $display_order]
        );
        $created['id']            = (int) $created['id'];
        $created['display_order'] = $created['display_order'] === null ? null : (int) $created['display_order'];

        json_response(['episode_type' => $created], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
