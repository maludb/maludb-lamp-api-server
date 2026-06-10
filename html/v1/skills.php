<?php
/**
 * /v1/skills  (requirements.md §4.8)
 *
 *   GET  ?visibility=&q=&subject=&verb=&limit=   List skills (optional visibility filter).
 *                                 subject/verb (0.97.0) switch to tag-aware discovery via
 *                                 maludb_skill_search (scoring, lineage, visible public skills).
 *   POST                          Create a skill. Body: {name, description?, markdown?,
 *                                 version?, visibility?, packaging_kind?, enabled?}
 *
 * Source: maludb_skill (direct-INSERT view; skill_id from sequence). name -> skill_name.
 * Defaults: version '1.0.0', visibility 'private', enabled true.
 * Constraints (enforced by the DB → 422): visibility ∈ {private,shared,public}
 * (public also needs a published owner_schema); packaging_kind ∈
 * {system_prompt,markdown,mcp_tool,plugin}. The skill body lives in `markdown`.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $visibility = query_str('visibility', null, 40);
        $q          = query_str('q', null, 200);
        $subject    = query_str('subject', null, 200);
        $verb       = query_str('verb', null, 200);
        $limit      = query_int('limit', 50, 200);

        // Tag-aware discovery (0.97.0): subject/verb hit the skill_subject/skill_verb tag
        // tables (and q the keyword/tsquery rails) through maludb_skill_search, which also
        // folds in visible public skills, scoring, and lineage. The plain list (no
        // subject/verb) keeps the original ILIKE semantics below.
        if (($subject !== null && $subject !== '') || ($verb !== null && $verb !== '')) {
            $rows = db_query(
                "SELECT owner_schema, skill_id AS id, skill_name AS name, description,
                        version, visibility, to_jsonb(subjects) AS subjects,
                        to_jsonb(verbs) AS verbs, to_jsonb(keywords) AS keywords, score,
                        to_jsonb(match_reasons) AS match_reasons, is_public, is_forkable,
                        source_owner_schema, source_skill_id, updated_at
                   FROM maludb_skill_search(?, ?, ?, NULL, ?)",
                [$q, $subject, $verb, $limit]
            );
            foreach ($rows as &$r) {
                $r['id']              = (int) $r['id'];
                $r['score']           = $r['score'] === null ? null : (float) $r['score'];
                $r['source_skill_id'] = $r['source_skill_id'] === null ? null : (int) $r['source_skill_id'];
                $r['is_public']       = $r['is_public'] === null ? null : (bool) $r['is_public'];
                $r['is_forkable']     = $r['is_forkable'] === null ? null : (bool) $r['is_forkable'];
                foreach (['subjects', 'verbs', 'keywords', 'match_reasons'] as $jc) {
                    $r[$jc] = $r[$jc] === null ? null : json_decode((string) $r[$jc]);
                }
            }
            unset($r);

            json_response(['skills' => $rows]);
        }

        $clauses = [];
        $params  = [];
        if ($visibility !== null && $visibility !== '') {
            $clauses[] = "visibility = ?"; $params[] = $visibility;
        }
        if ($q !== null && $q !== '') {
            $clauses[] = "(skill_name ILIKE ? OR description ILIKE ?)";
            $params[]  = '%' . $q . '%';
            $params[]  = '%' . $q . '%';
        }
        $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

        $sql = "SELECT skill_id AS id, skill_name AS name, description, version,
                       visibility, packaging_kind, enabled, created_at
                  FROM maludb_skill
                  $where
                 ORDER BY skill_name
                 LIMIT $limit";

        $rows = db_query($sql, $params);
        foreach ($rows as &$r) {
            $r['id']      = (int) $r['id'];
            $r['enabled'] = $r['enabled'] === null ? null : (bool) $r['enabled'];
        }
        unset($r);

        json_response(['skills' => $rows]);
    }

    case 'POST': {
        $body = body_json();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_error('missing_field', 'Field "name" is required.', 400);
        }

        $cols   = ['skill_name'];
        $ph     = ['?'];
        $params = [$name];
        foreach (['description', 'markdown', 'version', 'visibility', 'packaging_kind'] as $f) {
            if (isset($body[$f])) { $cols[] = $f; $ph[] = '?'; $params[] = (string) $body[$f]; }
        }
        if (array_key_exists('enabled', $body)) {
            $cols[] = 'enabled'; $ph[] = '?'; $params[] = $body['enabled'] ? 'true' : 'false';
        }

        $created = db_one(
            "INSERT INTO maludb_skill (" . implode(', ', $cols) . ")
             VALUES (" . implode(', ', $ph) . ")
             RETURNING skill_id AS id, skill_name AS name, description, markdown, version,
                       visibility, packaging_kind, enabled, created_at",
            $params
        );
        $created['id']      = (int) $created['id'];
        $created['enabled'] = $created['enabled'] === null ? null : (bool) $created['enabled'];

        json_response(['skill' => $created], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
