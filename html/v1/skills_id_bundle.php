<?php
/**
 * /v1/skills/{id}/bundle  (agent-skill pull — maludb_core 0.97.0)
 *
 *   GET   Full bundle for reconstruction: the skill row (incl. bundle_hash, frontmatter,
 *         fork lineage) + every file as base64. The terminal verifies each file_hash and
 *         the recomputed canonical bundle hash against skill.bundle_hash after writing.
 *
 * Files come from maludb_skill_file joined to maludb_source_package (content_bytes bytea;
 * PDO pgsql returns it as a stream resource). Older (pre-bundle) markdown-only skills
 * still pull as a synthesized one-file SKILL.md bundle.
 */

require_once __DIR__ . '/../../config/response.php';
require_once __DIR__ . '/../../config/skills.php';

require_auth();
$id = path_id();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('method_not_allowed', 'This endpoint supports GET.', 405);
}

$skill = db_one(
    "SELECT skill_id AS id, skill_name AS name, description, markdown, version,
            visibility, enabled, bundle_hash, frontmatter_jsonb,
            source_owner_schema, source_skill_id, created_at
       FROM maludb_skill WHERE skill_id = ?",
    [$id]
);
if ($skill === null) {
    json_error('not_found', 'Skill not found.', 404);
}
$skill['id']                = (int) $skill['id'];
$skill['source_skill_id']   = $skill['source_skill_id'] === null ? null : (int) $skill['source_skill_id'];
$skill['enabled']           = $skill['enabled'] === null ? null : (bool) $skill['enabled'];
// Decode as an object so empty frontmatter stays {} rather than [].
$skill['frontmatter_jsonb'] = $skill['frontmatter_jsonb'] === null ? null : json_decode((string) $skill['frontmatter_jsonb']);

$rows = db_query(
    "SELECT f.relative_path, f.file_hash, f.file_size, f.is_executable,
            f.media_type, sp.content_bytes, sp.content_text
       FROM maludb_skill_file f
       JOIN maludb_source_package sp ON sp.source_package_id = f.source_package_id
      WHERE f.skill_id = ?
      ORDER BY f.relative_path",
    [$id]
);

$files = [];
foreach ($rows as $r) {
    if ($r['content_bytes'] !== null) {
        // PDO pgsql hands bytea back as a stream resource (or a string on some drivers).
        $content = is_resource($r['content_bytes'])
            ? (string) stream_get_contents($r['content_bytes'])
            : (string) $r['content_bytes'];
    } else {
        $content = (string) ($r['content_text'] ?? '');
    }
    $files[] = [
        'relative_path'  => $r['relative_path'],
        'file_hash'      => $r['file_hash'],
        'file_size'      => (int) $r['file_size'],
        'is_executable'  => (bool) $r['is_executable'],
        'media_type'     => $r['media_type'],
        'content_base64' => base64_encode($content),
    ];
}

// Older (pre-bundle) markdown skills still pull as a one-file bundle.
if ($files === [] && $skill['markdown'] !== null && (string) $skill['markdown'] !== '') {
    $content = (string) $skill['markdown'];
    $files[] = [
        'relative_path'  => 'SKILL.md',
        'file_hash'      => skill_file_sha256($content),
        'file_size'      => strlen($content),
        'is_executable'  => false,
        'media_type'     => 'text/markdown',
        'content_base64' => base64_encode($content),
    ];
}

json_response(['skill' => $skill, 'files' => $files]);
