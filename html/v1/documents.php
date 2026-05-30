<?php
/**
 * /v1/documents  (requirements.md §4.4)
 *
 *   GET  ?q=&limit=   List documents (metadata + size).
 *   POST              multipart/form-data upload. Parts: file, filename, mime_type, description.
 *
 * Bytes are stored in maludb_source_package.content_bytes (bytea); maludb_document holds
 * the metadata and links to the package. Both are direct-INSERT views; ids are sequence
 * assigned. Binary download is out of v1 (requirements §6) — GET returns metadata only.
 */

require_once __DIR__ . '/../../config/response.php';

require_auth();

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET': {
        $q     = query_str('q', null, 200);
        $limit = query_int('limit', 50, 200);

        $where  = '';
        $params = [];
        if ($q !== null && $q !== '') {
            $where    = "WHERE d.title ILIKE ?";
            $params[] = '%' . $q . '%';
        }

        $sql = "SELECT d.document_id              AS id,
                       d.title,
                       d.source_type,
                       d.media_type,
                       d.document_type,
                       d.metadata_jsonb->>'description' AS description,
                       sp.content_size,
                       d.created_at
                  FROM maludb_document d
                  LEFT JOIN maludb_source_package sp ON sp.source_package_id = d.source_package_id
                  $where
                 ORDER BY d.created_at DESC NULLS LAST, d.document_id DESC
                 LIMIT $limit";

        $rows = db_query($sql, $params);
        foreach ($rows as &$r) {
            $r['id']           = (int) $r['id'];
            $r['content_size'] = $r['content_size'] === null ? null : (int) $r['content_size'];
        }
        unset($r);

        if (query_str('with', null, 40) === 'attributes') {
            attach_attributes($rows, 'maludb_document_with_attributes', 'document_id');
        }

        json_response(['documents' => $rows]);
    }

    case 'POST': {
        // multipart/form-data (NOT JSON). A body exceeding post_max_size arrives with empty
        // $_FILES/$_POST — treat that as too large.
        if (empty($_FILES) && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            json_error('upload_too_large', 'Upload exceeded the server size limit.', 413);
        }
        if (!isset($_FILES['file'])) {
            json_error('missing_field', 'Missing "file" upload part (multipart/form-data).', 400);
        }
        $err = $_FILES['file']['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            json_error('upload_too_large', 'Uploaded file exceeds the size limit.', 413);
        }
        if ($err !== UPLOAD_ERR_OK) {
            json_error('bad_request', 'File upload failed (PHP upload error ' . $err . ').', 400);
        }

        $bytes = file_get_contents($_FILES['file']['tmp_name']);
        if ($bytes === false) {
            json_error('bad_request', 'Could not read the uploaded file.', 400);
        }
        $filename    = trim((string) ($_POST['filename'] ?? $_FILES['file']['name'] ?? 'upload'));
        $mime        = trim((string) ($_POST['mime_type'] ?? $_FILES['file']['type'] ?? '')) ?: 'application/octet-stream';
        $description = isset($_POST['description']) ? (string) $_POST['description'] : null;
        // document_type (0.81.0): optional free-text picker label; advisory, no FK — any
        // string is allowed, omit/blank means NULL. Stored on the maludb_document view.
        $document_type = (isset($_POST['document_type']) && trim((string) $_POST['document_type']) !== '')
            ? (string) $_POST['document_type'] : null;
        $size        = strlen($bytes);
        $hash        = hash('sha256', $bytes);

        // content_bytes (bytea) must bind as a LOB — db_exec binds strings, so do this one
        // statement on the raw PDO handle and log it manually (tokenless, bytes redacted).
        $pdo  = Database::getInstance()->getConnection();
        $t0   = microtime(true);
        $sql  = "INSERT INTO maludb_source_package
                     (source_type, content_bytes, media_type, content_size, content_hash, ingested_at)
                 VALUES ('document', ?, ?, ?, ?, now()) RETURNING source_package_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $bytes, PDO::PARAM_LOB);
        $stmt->bindValue(2, $mime);
        $stmt->bindValue(3, $size, PDO::PARAM_INT);
        $stmt->bindValue(4, $hash);
        $stmt->execute();
        $spid = (int) $stmt->fetchColumn();
        sql_log($sql, ['<' . $size . ' bytes>', $mime, $size, $hash], 1, (microtime(true) - $t0) * 1000);

        $doc = db_one(
            "INSERT INTO maludb_document
                 (source_package_id, title, source_type, media_type, document_type, metadata_jsonb, created_at)
             VALUES (?, ?, 'document', ?, ?, ?, now())
             RETURNING document_id AS id, title, source_type, media_type, document_type, created_at",
            [$spid, $filename, $mime, $document_type, json_encode(['description' => $description, 'filename' => $filename])]
        );
        $doc['id']           = (int) $doc['id'];
        $doc['description']  = $description;
        $doc['content_size'] = $size;

        json_response(['document' => $doc], 201);
    }

    default:
        header('Allow: GET, POST');
        json_error('method_not_allowed', 'This endpoint supports GET and POST.', 405);
}
