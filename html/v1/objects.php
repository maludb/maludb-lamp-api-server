<?php
/**
 * /v1/objects/{kind}  (maludb_core 0.85.0+ — atomic object + attributes create)
 *
 *   POST  Create an object AND apply its typed attributes in ONE transaction:
 *         register the object, then maludb_attributes_apply(kind, id, attributes), then
 *         return maludb_object_get(kind, id). Either both land or neither does.
 *
 * Supported kinds (those with a register_* helper): 'subject', 'episode_object'.
 * Body = the object's fields + an optional "attributes" array, each element
 *   {attr_name, value_timestamp?|value_range?|value_numeric?|value_text?|value_jsonb?,
 *    unit?, provenance?, confidence?, ref_source?, ref_entity?, ref_key?}.
 *
 *   subject:        {canonical_name|name|label (req), subject_type?(='other'),
 *                    description?, classifier_md?, attributes?[]}
 *   episode_object: {title (req), kind?(='activity'), summary?, payload?, occurred_at?,
 *                    occurred_until?, sensitivity?(='internal'), provenance?, attributes?[]}
 *
 * Routed by .htaccess: /v1/objects/<kind> → objects.php?kind=<kind>.
 * Runs in db_tx_core() (register_* + attributes_apply + object_get all need maludb_core).
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

$kind = query_str('kind', null, 40);
if ($kind === null || $kind === '') {
    json_error('bad_request', 'Missing object kind in path (POST /v1/objects/{kind}).', 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('method_not_allowed', 'This endpoint supports POST.', 405);
}

$body = body_json();

// Validate the optional attributes array up front (no partial writes).
$attributes = [];
if (array_key_exists('attributes', $body) && $body['attributes'] !== null) {
    if (!is_array($body['attributes']) || (count($body['attributes']) && array_keys($body['attributes']) !== range(0, count($body['attributes']) - 1))) {
        json_error('validation_failed', '"attributes" must be an array of attribute objects.', 422);
    }
    $attributes = $body['attributes'];
}

$object = db_tx_core(function ($pdo) use ($kind, $body, $attributes) {

    // ---- 1. create the object via its register_* helper ----
    if ($kind === 'subject') {
        $name = trim((string) ($body['canonical_name'] ?? $body['name'] ?? $body['label'] ?? ''));
        if ($name === '') json_error('missing_field', 'Field "canonical_name" is required for a subject.', 400);
        $type        = isset($body['subject_type']) && trim((string) $body['subject_type']) !== '' ? (string) $body['subject_type']
                     : (isset($body['type']) && trim((string) $body['type']) !== '' ? (string) $body['type'] : 'other');
        $description = isset($body['description'])   ? (string) $body['description']   : null;
        $classifier  = isset($body['classifier_md']) ? (string) $body['classifier_md'] : null;
        $row = db_one(
            "SELECT register_svpor_subject(
                        p_canonical_name => ?, p_description => ?, p_subject_type => ?, p_classifier_md => ?
                    ) AS id",
            [$name, $description, $type, $classifier]
        );
        $target_id = (int) $row['id'];

    } elseif ($kind === 'episode_object') {
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') json_error('missing_field', 'Field "title" is required for an episode.', 400);
        $ekind          = isset($body['kind']) && trim((string) $body['kind']) !== '' ? (string) $body['kind'] : 'activity';
        $summary        = isset($body['summary'])        ? (string) $body['summary']        : null;
        $occurred_at    = isset($body['occurred_at'])    ? (string) $body['occurred_at']    : null;
        $occurred_until = isset($body['occurred_until']) ? (string) $body['occurred_until'] : null;
        $sensitivity    = isset($body['sensitivity']) && trim((string) $body['sensitivity']) !== '' ? (string) $body['sensitivity'] : 'internal';
        $provenance     = isset($body['provenance'])  && trim((string) $body['provenance'])  !== '' ? (string) $body['provenance']  : 'provided';
        $payload_json   = isset($body['payload']) && is_array($body['payload']) ? json_encode($body['payload']) : '{}';
        $row = db_one(
            "SELECT maludb_register_episode(
                        p_episode_kind => ?, p_title => ?, p_summary => ?, p_payload_jsonb => ?::jsonb,
                        p_occurred_at => ?::timestamptz, p_occurred_until => ?::timestamptz,
                        p_sensitivity => ?, p_provenance => ?
                    ) AS id",
            [$ekind, $title, $summary, $payload_json, $occurred_at, $occurred_until, $sensitivity, $provenance]
        );
        $target_id = (int) $row['id'];

    } else {
        json_error('validation_failed', 'Unsupported object kind "' . $kind . '" for atomic create (supported: subject, episode_object).', 422);
    }

    // ---- 2. apply the typed attributes atomically ----
    if ($attributes) {
        db_one("SELECT maludb_attributes_apply(?, ?, ?::jsonb) AS n", [$kind, $target_id, json_encode($attributes)]);
    }

    // ---- 3. return the assembled handle (object + attributes [+ statements/details]) ----
    $got = db_one("SELECT maludb_object_get(?, ?) AS obj", [$kind, $target_id]);
    return ($got && $got['obj'] !== null) ? json_decode($got['obj']) : null;
});

json_response(['object' => $object], 201);
