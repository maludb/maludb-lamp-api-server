<?php
/**
 * /v1/episodes  (maludb_core 0.82.0 — first-class events)
 *
 *   GET   ?q=&kind=&provenance=&limit=   List episodes (newest occurrence first).
 *   POST                                 Create an episode. Returns it (201).
 *
 * Episodes are rows in the writable maludb_episode view. Create goes through the
 * search-path-safe facade maludb_register_episode(...) (named args; trailing
 * p_provenance is new in 0.82.0). Everything runs inside db_tx_core() so the facade
 * can resolve its malu$* base tables + RLS grants.
 *
 * Body:
 *   { title (required), kind? (default 'activity'), summary?, payload? (object),
 *     occurred_at?, occurred_until?, sensitivity? (default 'internal'),
 *     provenance? (default 'provided') }
 * sensitivity ∈ {public,internal,restricted,prohibited} and provenance ∈
 * {provided,suggested,accepted,rejected} are DB-enforced → 422. episode_kind is free text.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

/** SELECT list for an episode row (shared with episodes_id.php's PATCH readback). */
const EPISODE_COLS = "episode_id AS id, episode_kind AS kind, title, summary,
                      payload_jsonb AS payload, occurred_at, occurred_until, recorded_at,
                      sensitivity, lifecycle_state, provenance, created_at";

/** Normalize scalar types on an episode row in place. */
function shape_episode(array &$e): void {
    $e['id']      = (int) $e['id'];
    // Decode as an object (not assoc) so an empty payload stays {} rather than [].
    $e['payload'] = $e['payload'] === null ? null : json_decode($e['payload']);
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $q          = query_str('q', null, 200);
        $kind       = query_str('kind', null, 120);
        $provenance = query_str('provenance', null, 40);
        $limit      = query_int('limit', 50, 200);

        $clauses = [];
        $params  = [];
        if ($kind !== null && $kind !== '')             { $clauses[] = "episode_kind = ?"; $params[] = $kind; }
        if ($provenance !== null && $provenance !== '') { $clauses[] = "provenance = ?";   $params[] = $provenance; }
        if ($q !== null && $q !== '') {
            $clauses[] = "(title ILIKE ? OR summary ILIKE ?)";
            $params[]  = '%' . $q . '%';
            $params[]  = '%' . $q . '%';
        }
        $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

        $rows = db_tx_core(fn() => db_query(
            "SELECT " . EPISODE_COLS . "
               FROM maludb_episode
               $where
              ORDER BY occurred_at DESC NULLS LAST, episode_id DESC
              LIMIT $limit",
            $params
        ));
        foreach ($rows as &$r) { shape_episode($r); }
        unset($r);

        json_response(['episodes' => $rows]);
    }

    case 'POST': {
        $body = body_json();

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            json_error('missing_field', 'Field "title" is required.', 400);
        }
        $kind           = isset($body['kind'])    && trim((string) $body['kind'])    !== '' ? (string) $body['kind']    : 'activity';
        $summary        = isset($body['summary']) ? (string) $body['summary'] : null;
        $occurred_at    = isset($body['occurred_at'])    ? (string) $body['occurred_at']    : null;
        $occurred_until = isset($body['occurred_until']) ? (string) $body['occurred_until'] : null;
        $sensitivity    = isset($body['sensitivity']) && trim((string) $body['sensitivity']) !== '' ? (string) $body['sensitivity'] : 'internal';
        $provenance     = isset($body['provenance'])  && trim((string) $body['provenance'])  !== '' ? (string) $body['provenance']  : 'provided';
        $payload_json   = isset($body['payload']) && is_array($body['payload']) ? json_encode($body['payload']) : '{}';

        $episode = db_tx_core(function ($pdo) use (
            $kind, $title, $summary, $payload_json, $occurred_at, $occurred_until, $sensitivity, $provenance
        ) {
            $row = db_one(
                "SELECT maludb_register_episode(
                            p_episode_kind   => ?,
                            p_title          => ?,
                            p_summary        => ?,
                            p_payload_jsonb  => ?::jsonb,
                            p_occurred_at    => ?::timestamptz,
                            p_occurred_until => ?::timestamptz,
                            p_sensitivity    => ?,
                            p_provenance     => ?
                        ) AS id",
                [$kind, $title, $summary, $payload_json, $occurred_at, $occurred_until, $sensitivity, $provenance]
            );
            return db_one("SELECT " . EPISODE_COLS . " FROM maludb_episode WHERE episode_id = ?", [(int) $row['id']]);
        });

        shape_episode($episode);
        json_response(['episode' => $episode], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
