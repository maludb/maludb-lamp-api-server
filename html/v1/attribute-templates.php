<?php
/**
 * /v1/attribute-templates  (maludb_core 0.83.0+ — the form catalog)
 *
 *   GET   ?applies_to=&type_value=&limit=
 *         The typed-property catalog that drives forms: which attributes apply to a
 *         given node/edge type, their value_type, requirement, label, unit, etc.
 *   POST  Create a template entry.
 *
 * Source: maludb_attribute_template (writable view) + maludb_attribute_template_create(...).
 *   applies_to  ∈ (episode_type, document_type, subject_type, verb)
 *   value_type  ∈ (timestamp, tstzrange, numeric, text, jsonb, reference)
 *   requirement ∈ (required, recommended, optional)
 * Bad enum values raise a DB check/trigger → 422 via the global handler.
 *
 * No PATCH (the 0.83.0 surface exposes only create + delete; re-create to change).
 * Runs in db_tx_core() so the facade resolves its malu$* base tables.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

function shape_template(array &$r): void {
    $r['id']            = (int) $r['id'];
    $r['display_order'] = $r['display_order'] === null ? null : (int) $r['display_order'];
    $r['allowed_values'] = $r['allowed_values'] === null ? null : json_decode($r['allowed_values']);
    $r['default_value']  = $r['default_value']  === null ? null : json_decode($r['default_value']);
}

function template_cols(): string {
    return "template_id AS id, applies_to, type_value, attr_name, value_type, requirement,
            label, description, unit, allowed_values, default_value, display_order, created_at";
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $applies_to = query_str('applies_to', null, 40);
        $type_value = query_str('type_value', null, 200);
        $limit      = query_int('limit', 200, 500);

        $clauses = [];
        $params  = [];
        if ($applies_to !== null && $applies_to !== '') { $clauses[] = "applies_to = ?"; $params[] = $applies_to; }
        if ($type_value !== null && $type_value !== '') { $clauses[] = "type_value = ?"; $params[] = $type_value; }
        $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

        $rows = db_tx_core(fn() => db_query(
            "SELECT " . template_cols() . "
               FROM maludb_attribute_template
               $where
              ORDER BY applies_to, type_value, display_order NULLS LAST, attr_name
              LIMIT $limit",
            $params
        ));
        foreach ($rows as &$r) { shape_template($r); }
        unset($r);

        json_response(['attribute_templates' => $rows]);
    }

    case 'POST': {
        $body = body_json();

        $applies_to = trim((string) ($body['applies_to'] ?? ''));
        $type_value = trim((string) ($body['type_value'] ?? ''));
        $attr_name  = trim((string) ($body['attr_name'] ?? ''));
        $value_type = trim((string) ($body['value_type'] ?? ''));
        foreach (['applies_to' => $applies_to, 'type_value' => $type_value,
                  'attr_name' => $attr_name, 'value_type' => $value_type] as $name => $val) {
            if ($val === '') json_error('missing_field', "Field \"$name\" is required.", 400);
        }

        $requirement = isset($body['requirement']) && trim((string) $body['requirement']) !== ''
            ? (string) $body['requirement'] : 'optional';
        $label       = isset($body['label'])       ? (string) $body['label']       : null;
        $description = isset($body['description'])  ? (string) $body['description']  : null;
        $unit        = isset($body['unit'])         ? (string) $body['unit']         : null;
        $allowed     = array_key_exists('allowed_values', $body) && $body['allowed_values'] !== null ? json_encode($body['allowed_values']) : null;
        $default     = array_key_exists('default_value', $body)  && $body['default_value']  !== null ? json_encode($body['default_value'])  : null;
        $display_order = null;
        if (array_key_exists('display_order', $body) && $body['display_order'] !== null) {
            if (!is_int($body['display_order'])) json_error('validation_failed', '"display_order" must be an integer.', 422);
            $display_order = (int) $body['display_order'];
        }

        $created = db_tx_core(function ($pdo) use ($applies_to, $type_value, $attr_name, $value_type,
                $requirement, $label, $description, $unit, $allowed, $default, $display_order) {
            $row = db_one(
                "SELECT maludb_attribute_template_create(
                            p_applies_to    => ?, p_type_value => ?, p_attr_name => ?, p_value_type => ?,
                            p_requirement   => ?, p_label => ?, p_description => ?, p_unit => ?,
                            p_allowed_values => ?::jsonb, p_default_value => ?::jsonb, p_display_order => ?
                        ) AS id",
                [$applies_to, $type_value, $attr_name, $value_type, $requirement, $label, $description,
                 $unit, $allowed, $default, $display_order]
            );
            $t = db_one("SELECT " . template_cols() . " FROM maludb_attribute_template WHERE template_id = ?", [(int) $row['id']]);
            shape_template($t);
            return $t;
        });

        json_response(['attribute_template' => $created], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
