<?php
/**
 * Agent-skill ingest helpers (maludb_core 0.97.0).
 *
 * A Claude Agent Skill is a directory bundle: SKILL.md (YAML frontmatter +
 * markdown body) plus optional scripts/, references/, assets/. The terminal
 * parses the frontmatter and uploads the bundle; this file owns the parts
 * the server is responsible for:
 *
 *   - the canonical bundle hash (identity of a skill version),
 *   - the deterministic materiality screens (does a revision supersede its
 *     parent or coexist with it?),
 *   - the deterministic (no-LLM) discovery extraction and the post-processing
 *     that makes any extraction safe for the one-call ingest.
 *
 * Pure functions only — no DB, no HTTP. Mirrors the Python reference
 * implementation (app/helpers/skills.py) exactly. Required by
 * html/v1/skills_ingest.php and html/v1/skills_id_bundle.php.
 */

/** Frontmatter keys whose change always makes a revision materially different. */
const SKILL_MATERIAL_FRONTMATTER_KEYS = [
    'description',
    'when_to_use',
    'allowed-tools',
    'disallowed-tools',
    'compatibility',
];

/* ---------------------------------------------------------------------------
 * Canonical bundle hash
 * ------------------------------------------------------------------------- */

/** sha256 hex of a file's raw bytes. */
function skill_file_sha256(string $bytes): string {
    return hash('sha256', $bytes);
}

/**
 * sha256 over the sorted per-file hashes.
 *
 * Canonical line format: "<file sha256>  <relative_path>\n" (TWO spaces),
 * sorted by line. A script edit changes the bundle hash even when SKILL.md is
 * untouched. The terminal computes the same value client-side; the server's
 * recomputation is authoritative.
 *
 * $files: list of ['file_hash' => ..., 'relative_path' => ...].
 */
function skill_bundle_hash(array $files): string {
    $lines = [];
    foreach ($files as $f) {
        $lines[] = $f['file_hash'] . '  ' . $f['relative_path'] . "\n";
    }
    sort($lines, SORT_STRING);
    return hash('sha256', implode('', $lines));
}

/* ---------------------------------------------------------------------------
 * Materiality screens
 * ------------------------------------------------------------------------- */

/** Collapse all whitespace runs to single spaces and trim. */
function skill_normalize_ws(?string $text): string {
    return trim((string) preg_replace('/\s+/', ' ', (string) $text));
}

/**
 * Deterministic comparison of a revision against its parent skill row.
 *
 * Returns ['verdict' => 'material'|'non_material'|'gray', 'reasons' => [...]].
 *
 *   material     — capability surface changed (description / tool policy /
 *                  any non-SKILL.md file): versions must coexist.
 *   non_material — bundles differ only in SKILL.md whitespace: supersede.
 *   gray         — SKILL.md body text changed but nothing else did; a
 *                  judgment call (LLM judge when available, else treated as
 *                  material so nothing is hidden wrongly).
 *
 * $parent carries the maludb_skill row (markdown, frontmatter_jsonb — array
 * or JSON string) plus a 'files' list of {relative_path, file_hash} from
 * maludb_core.malu$skill_file.
 */
function skill_materiality_screens(array $parent, string $new_markdown, array $new_frontmatter, array $new_files): array {
    $reasons = [];

    $old_fm = $parent['frontmatter_jsonb'] ?? [];
    if (is_string($old_fm)) {
        $decoded = json_decode($old_fm, true);
        $old_fm = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($old_fm)) {
        $old_fm = [];
    }

    // Python's `value or None`: falsy values collapse to null before comparison.
    $falsy_null = static function ($v) {
        return ($v === null || $v === '' || $v === [] || $v === false || $v === 0 || $v === 0.0) ? null : $v;
    };
    // Arrays compare structurally (order-insensitive on keys, like Python dicts);
    // scalars compare strictly.
    $differ = static function ($a, $b): bool {
        if (is_array($a) && is_array($b)) return $a != $b;
        return $a !== $b;
    };

    foreach (SKILL_MATERIAL_FRONTMATTER_KEYS as $key) {
        if ($differ($falsy_null($old_fm[$key] ?? null), $falsy_null($new_frontmatter[$key] ?? null))) {
            $reasons[] = 'frontmatter:' . $key;
        }
    }

    // SKILL.md content is judged by text below, not by its manifest hash.
    $old_files = [];
    foreach (($parent['files'] ?? []) as $f) {
        if (($f['relative_path'] ?? null) !== 'SKILL.md' && isset($f['relative_path'])) {
            $old_files[$f['relative_path']] = $f['file_hash'] ?? null;
        }
    }
    $new_files_map = [];
    foreach ($new_files as $f) {
        if (($f['relative_path'] ?? null) !== 'SKILL.md' && isset($f['relative_path'])) {
            $new_files_map[$f['relative_path']] = $f['file_hash'] ?? null;
        }
    }
    $paths = array_unique(array_merge(array_keys($old_files), array_keys($new_files_map)));
    sort($paths, SORT_STRING);
    foreach ($paths as $path) {
        if (($old_files[$path] ?? null) !== ($new_files_map[$path] ?? null)) {
            $reasons[] = 'file:' . $path;
        }
    }

    if ($reasons !== []) {
        return ['verdict' => 'material', 'reasons' => $reasons];
    }

    if (skill_normalize_ws($parent['markdown'] ?? '') === skill_normalize_ws($new_markdown)) {
        return ['verdict' => 'non_material', 'reasons' => ['skill_md_whitespace_only']];
    }

    return ['verdict' => 'gray', 'reasons' => ['skill_md_body_changed']];
}

/* ---------------------------------------------------------------------------
 * Deterministic (no-LLM) discovery extraction
 * ------------------------------------------------------------------------- */

/** Stopwords excluded from deterministic discovery keywords. */
function skill_discovery_stopwords(): array {
    static $set = null;
    if ($set === null) {
        $set = array_fill_keys(explode(' ',
            'a an and are as at be by for from in into is it of on or the this to use '
            . 'used uses using when with you your'
        ), true);
    }
    return $set;
}

/**
 * Frontmatter-only discovery tags — the credential-free fallback.
 *
 * The skill name and the description's content words become keywords; the
 * skill itself is the only subject. No verbs are guessed: a wrong verb tag
 * poisons verb search, while keywords degrade gracefully.
 *
 * Returns ['keywords' => [...max 24], 'subjects' => [['name' => $name]], 'verbs' => []].
 */
function skill_deterministic_discovery(string $name, array $frontmatter): array {
    $stop = skill_discovery_stopwords();
    $keywords = [];
    $seen = [];
    foreach (preg_split('/[^a-z0-9]+/', strtolower($name)) as $token) {
        if ($token !== '' && !isset($stop[$token]) && !isset($seen[$token])) {
            $seen[$token] = true;
            $keywords[] = $token;
        }
    }
    $description = (string) ($frontmatter['description'] ?? '');
    foreach (preg_split('/[^a-z0-9]+/', strtolower($description)) as $token) {
        if (strlen($token) > 2 && !isset($stop[$token]) && !isset($seen[$token])) {
            $seen[$token] = true;
            $keywords[] = $token;
        }
    }
    return [
        'keywords' => array_slice($keywords, 0, 24),
        'subjects' => [['name' => $name]],
        'verbs'    => [],
    ];
}

/* ---------------------------------------------------------------------------
 * Skill extraction JSON post-processing
 * ------------------------------------------------------------------------- */

/**
 * Make an LLM extraction safe for the one-call ingest.
 *
 * Guarantees the document section (SKILL.md as an agent_skill document) and a
 * subject of type 'skill' carrying the skill's own name, whatever the model
 * produced. The model's "keywords" key is left in place: ingest ignores
 * unknown sections and the register step reads it.
 */
function skill_coerce_extraction(array $extraction, string $name, string $markdown, array $frontmatter): array {
    $out = $extraction;
    $out['document'] = [
        'title'         => $name,
        'content_text'  => $markdown,
        'source_type'   => 'document',
        'document_type' => 'agent_skill',
        'metadata'      => ['frontmatter' => $frontmatter],
    ];

    $subjects = [];
    foreach (($extraction['subjects'] ?? []) as $s) {
        if (is_array($s)) $subjects[] = $s;
    }
    $skill_key = null;
    foreach ($subjects as &$s) {
        if (strtolower(trim((string) ($s['name'] ?? ''))) === strtolower(trim($name))) {
            $s['type'] = 'skill';
            $skill_key = $s['key'] ?? null;
            break;
        }
    }
    unset($s);
    if ($skill_key === null) {
        $description = (string) ($frontmatter['description'] ?? '');
        array_unshift($subjects, [
            'key'         => 'skill_self',
            'name'        => $name,
            'type'        => 'skill',
            'description' => $description !== '' ? $description : null,
        ]);
    }
    $out['subjects'] = $subjects;
    return $out;
}
