<?php
/**
 * /v1/documents/{id}  (requirements.md §4.4)
 *
 *   GET     Document metadata + primary_project_id + tags[] (no binary; download is out of
 *           v1 — §6). Each tag carries its resolved tag_object_type/tag_object_id so the UI
 *           can link the tag to the real subject/project record.
 *   PATCH   Add/remove project & subject links, maintaining the graph (0.87.0). Body:
 *             { "link":   { "projects": ["X"], "subjects": ["Y"] },
 *               "unlink": { "projects": ["Z"], "subjects": ["W"] } }
 *   DELETE  Remove the document and its source package.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();
$id = path_id();

/** Document metadata + resolved tags[], or null if the document does not exist. */
function load_document_detail(int $id): ?array {
    $doc = db_one(
        "SELECT d.document_id              AS id,
                d.title,
                d.source_type,
                d.media_type,
                d.document_type,
                d.primary_project_id,
                d.metadata_jsonb->>'description' AS description,
                sp.content_size,
                sp.content_hash,
                d.created_at,
                d.updated_at
           FROM maludb_document d
           LEFT JOIN maludb_source_package sp ON sp.source_package_id = d.source_package_id
          WHERE d.document_id = ?",
        [$id]
    );
    if ($doc === null) {
        return null;
    }
    $doc['id']                 = (int) $doc['id'];
    $doc['content_size']       = $doc['content_size'] === null ? null : (int) $doc['content_size'];
    $doc['primary_project_id'] = $doc['primary_project_id'] === null ? null : (int) $doc['primary_project_id'];

    // Soft tags now carry the resolved graph object (tag_object_type/tag_object_id).
    $tags = db_query(
        "SELECT tag_id, tag_kind, tag_value, tag_object_type, tag_object_id, provenance, confidence
           FROM maludb_document_tag
          WHERE document_id = ?
          ORDER BY tag_kind, tag_value, tag_id",
        [$id]
    );
    foreach ($tags as &$t) {
        $t['tag_id']        = (int) $t['tag_id'];
        $t['tag_object_id'] = $t['tag_object_id'] === null ? null : (int) $t['tag_object_id'];
        $t['confidence']    = $t['confidence'] === null ? null : (float) $t['confidence'];
    }
    unset($t);
    $doc['tags'] = $tags;

    return $doc;
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $doc = load_document_detail($id);
        if ($doc === null) {
            json_error('not_found', 'Document not found.', 404);
        }
        json_response(['document' => $doc]);
    }

    case 'PATCH': {
        if (db_one("SELECT 1 FROM maludb_document WHERE document_id = ?", [$id]) === null) {
            json_error('not_found', 'Document not found.', 404);
        }

        $body = body_json();

        // Pull a list of names for $body[$op][$kind]; reject anything that is not a string array.
        $names = static function (array $body, string $op, string $kind): array {
            $list = $body[$op][$kind] ?? null;
            if ($list === null) return [];
            if (!is_array($list)) json_error('validation_failed', "\"$op.$kind\" must be an array of names.", 422);
            $out = [];
            foreach ($list as $n) {
                if (!is_string($n)) json_error('validation_failed', "\"$op.$kind\" must contain only strings.", 422);
                $n = trim($n);
                if ($n !== '') $out[$n] = $n;
            }
            return array_values($out);
        };

        $link_projects   = $names($body, 'link',   'projects');
        $link_subjects   = $names($body, 'link',   'subjects');
        $unlink_projects = $names($body, 'unlink', 'projects');
        $unlink_subjects = $names($body, 'unlink', 'subjects');

        if (!$link_projects && !$link_subjects && !$unlink_projects && !$unlink_subjects) {
            json_error('bad_request', 'Provide link/unlink projects or subjects to change.', 400);
        }

        db_tx_core(function () use ($id, $link_projects, $link_subjects, $unlink_projects, $unlink_subjects) {
            // Unlink first so a re-link in the same request re-establishes the edge cleanly.
            foreach ($unlink_projects as $p) document_unlink_subject($id, 'project', $p);
            foreach ($unlink_subjects as $s) document_unlink_subject($id, 'subject', $s);

            $first = null;
            foreach ($link_projects as $p) {
                $sid = document_link_subject($id, 'project', $p);
                if ($first === null && $sid !== null) $first = $sid;
            }
            foreach ($link_subjects as $s) document_link_subject($id, 'subject', $s);

            // Adopt a primary project when one isn't set yet (unlink may have just cleared it).
            if ($first !== null) {
                db_exec(
                    "UPDATE maludb_document SET primary_project_id = ? WHERE document_id = ? AND primary_project_id IS NULL",
                    [$first, $id]
                );
            }
        });

        json_response(['document' => load_document_detail($id)]);
    }

    case 'DELETE': {
        $row = db_one("SELECT source_package_id FROM maludb_document WHERE document_id = ?", [$id]);
        if ($row === null) {
            json_error('not_found', 'Document not found.', 404);
        }
        // Remove the document's graph edges first — deleting the document cascades its soft tags
        // but NOT its document→subject svpor_statement edges (0.87.0), which would otherwise
        // dangle. Done in a tx so the facade resolves under maludb_core.
        db_tx_core(fn() => db_exec(
            "DELETE FROM maludb_svpor_statement WHERE subject_kind = 'document' AND subject_id = ?",
            [$id]
        ));
        db_exec("DELETE FROM maludb_document WHERE document_id = ?", [$id]);
        if ($row['source_package_id'] !== null) {
            db_exec("DELETE FROM maludb_source_package WHERE source_package_id = ?", [$row['source_package_id']]);
        }
        json_response(['deleted' => true, 'id' => $id]);
    }

    default:
        header('Allow: GET, PATCH, DELETE');
        json_error('method_not_allowed', 'This endpoint supports GET, PATCH and DELETE.', 405);
}
