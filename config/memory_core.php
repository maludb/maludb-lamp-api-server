<?php
/**
 * Memory pipeline cores — the bodies of the three /v1/memory/* endpoints as
 * includable functions, shared by the REST endpoint files (html/v1/memory_*.php)
 * and the MCP server endpoint (html/mcp.php). No HTTP self-calls: mcp.php calls
 * these in-process.
 *
 *   mem_ingest_core($user_id, $opts)    — text → LLM extraction → graph ingest
 *   mem_search_core($user_id, $opts)    — embed the query → maludb_memory_search
 *   mem_documents_core($user_id, $opts) — chunk → extract → embed → ingest edges
 *
 * Each core takes the already-parsed/validated inputs (the REST files keep
 * their body parsing + validation, then call the core and emit the response —
 * wire behavior unchanged) and returns the response payload array. Failures
 * raise ApiException via json_error(): the REST top-level handler renders them
 * exactly as before; mcp.php catches them and emits isError tool results.
 *
 * The code here is the former endpoint bodies moved verbatim (function-boundary
 * move only); see each endpoint file's docblock for the behavioral contract.
 */

require_once __DIR__ . '/response.php';

/**
 * /v1/memory/ingest core (and the MCP store_memory tool).
 *
 * $opts: text (required, non-empty), hints_json (JSON array string, default '[]'),
 *        namespace (default 'default'), explicit_model (?string), preview (bool).
 *
 * Returns the response payload: the assembled-prompt preview when preview=true,
 * else {document_id, model, api_format, namespace, result}.
 */
function mem_ingest_core(int $user_id, array $opts): array {
    $text           = (string) $opts['text'];
    $hints_json     = (string) ($opts['hints_json'] ?? '[]');
    $namespace      = (string) ($opts['namespace'] ?? 'default');
    $explicit_model = isset($opts['explicit_model']) && $opts['explicit_model'] !== null
        && trim((string) $opts['explicit_model']) !== '' ? trim((string) $opts['explicit_model']) : null;
    $preview        = !empty($opts['preview']);

    // --- per-model prompt + LLM connection. Resolution order: explicit model (legacy
    //     model_prompts first, then the seeded catalog + the user's provider key) → the user's
    //     'extract' choice → the legacy default ('chatgpt-4o' model_prompts row) → the namespace
    //     config (Store A). ---
    $pr    = mem_resolve_task_config($user_id, 'extract', $explicit_model);
    $model = $pr !== null ? (string) $pr['model_name'] : ($explicit_model ?? 'chatgpt-4o');
    if ($pr === null) {
        $pr = LocalDatabase::modelPrompt($model);
    }
    if ($pr === null) {
        // No model_prompt: fall back to Store A (the Postgres namespace config). Borrow its LLM
        // connection and pair it with the default ingest system prompt — the namespace
        // prompt_template targets the candidate_edges contract, not the ingest contract, so it
        // is not reused here.
        $cfg_raw = mem_namespace_config($namespace);
        if (mem_has_llm_connection($cfg_raw)) {
            $pr = [
                'model_name'        => $model,
                'model_identifier'  => ($cfg_raw['model_identifier'] ?? '') !== '' ? $cfg_raw['model_identifier'] : $model,
                'api_format'        => 'openai',
                'system_prompt'     => mem_default_ingest_prompt(),
                'base_url'          => $cfg_raw['base_url'] ?? '',
                'api_key'           => mem_resolve_token($cfg_raw['secret_ref'] ?? null),
                'max_tokens'        => 2048,
                'generation_params' => json_encode(is_array($cfg_raw['generation_params'] ?? null) ? $cfg_raw['generation_params'] : []),
            ];
        } else {
            json_error(
                'model_not_configured',
                'No prompt configured for model "' . $model . '" and no model config for namespace "'
                . $namespace . '". Set one via POST /v1/model-prompts or POST /v1/memory/config.',
                422
            );
        }
    }

    // --- KNOWN_SUBJECTS / KNOWN_VERBS from Postgres (so the model reuses canonical names) ---
    $subj_rows = db_query("SELECT canonical_name AS name, subject_type AS type FROM maludb_subject ORDER BY canonical_name");
    $verb_rows = db_query("SELECT canonical_name FROM maludb_verb ORDER BY canonical_name");
    $known_subjects_json = json_encode(array_map(fn($r) => ['name' => $r['name'], 'type' => $r['type']], $subj_rows), JSON_UNESCAPED_SLASHES);
    $known_verbs_json    = json_encode(array_map(fn($r) => $r['canonical_name'], $verb_rows), JSON_UNESCAPED_SLASHES);

    // --- SUBJECT TYPE CATALOG (0.96.0): render the entity/event vocabularies straight from the
    //     tenant catalog so the prompt's allowed types can never drift from what the ingest accepts.
    //     The maludb_subject_type facade exposes `category` once a tenant has re-run
    //     enable_memory_schema(); until then we fall back to the maludb_core base table, which
    //     carries `category` immediately after the 0.96.0 extension upgrade. ---
    try {
        $type_rows = db_query(
            "SELECT category, subject_type, description FROM maludb_subject_type ORDER BY category, sort_order"
        );
    } catch (PDOException $e) {
        // single-quoted on purpose: the `$` in malu$svpor_* must not be parsed as a PHP variable
        $type_rows = db_query(
            'SELECT category, subject_type, description FROM maludb_core.malu$svpor_subject_type ORDER BY category, sort_order'
        );
    }
    $entity_lines = [];
    $event_lines  = [];
    foreach ($type_rows as $r) {
        $desc = (isset($r['description']) && trim((string) $r['description']) !== '') ? ' — ' . $r['description'] : '';
        $line = '  - ' . $r['subject_type'] . $desc;
        if (($r['category'] ?? 'entity') === 'event') { $event_lines[] = $line; } else { $entity_lines[] = $line; }
    }
    // Fallbacks keep the model inside the catalog even if a list comes back empty.
    $entity_block = $entity_lines !== [] ? implode("\n", $entity_lines) : '  - other';
    $event_block  = $event_lines  !== [] ? implode("\n", $event_lines)  : '  - task';

    // --- build the messages ---
    // Substitute the rendered catalog into the stored SYSTEM prompt. strtr is a no-op for a legacy
    // prompt with no {{ENTITY_TYPES}}/{{EVENT_KINDS}} placeholders, so this stays backward-compatible.
    $system  = strtr($pr['system_prompt'], [
        '{{ENTITY_TYPES}}' => $entity_block,
        '{{EVENT_KINDS}}'  => $event_block,
    ]);
    $user    = "TEXT:\n{$text}\n\nHINTS:\n{$hints_json}\n\nKNOWN_SUBJECTS:\n{$known_subjects_json}\n\nKNOWN_VERBS:\n{$known_verbs_json}\n";

    if ($preview) {
        return [
            'model'         => $model,
            'api_format'    => $pr['api_format'],
            'system_prompt' => $system,
            'user_message'  => $user,
            'counts'        => [
                'known_subjects' => count($subj_rows),
                'known_verbs'    => count($verb_rows),
                'entity_types'   => count($entity_lines),
                'event_kinds'    => count($event_lines),
            ],
        ];
    }

    if (($pr['api_key'] ?? null) === null || $pr['api_key'] === '') {
        if (in_array($pr['source'] ?? null, ['catalog_explicit', 'user_choice'], true)) {
            $msg = 'No API key stored for provider "' . ($pr['provider'] ?? '') . '".'
                 . ' Set one via PUT /v1/llm/providers/' . ($pr['provider'] ?? '') . '.';
        } else {
            $msg = 'No API key set for model "' . $model . '". Set it via POST /v1/model-prompts.';
        }
        json_error('model_api_key_missing', $msg, 409);
    }

    // The 0.92.0 ingest facade must be present (the model JSON is passed to it verbatim).
    $has_facade = db_one("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'maludb_memory_ingest_extraction') AS ok");
    if (!$has_facade || !$has_facade['ok']) {
        json_error('ingest_unavailable', 'maludb_memory_ingest_extraction is not available in this database (requires maludb_core 0.92.0).', 501);
    }

    // --- call the LLM (OpenAI or Anthropic shape) and parse the extraction JSON ---
    $cfg = [
        'api_format'        => $pr['api_format'],
        'base_url'          => $pr['base_url'],
        'model_identifier'  => ($pr['model_identifier'] !== null && $pr['model_identifier'] !== '') ? $pr['model_identifier'] : $model,
        'token'             => $pr['api_key'],
        'max_tokens'        => (int) $pr['max_tokens'],
        'generation_params' => ($pr['generation_params'] !== null && $pr['generation_params'] !== '') ? json_decode($pr['generation_params'], true) : [],
    ];
    $content    = llm_complete($cfg, $system, $user);
    $extraction = llm_json_from_text($content);
    if ($extraction === null) {
        json_error('upstream_error', 'LLM output was not a JSON object.', 502);
    }

    // --- upload the text + ingest the extraction (one transaction) ---
    $result = db_tx_core(function () use ($text, $extraction) {
        $doc = db_one(
            "SELECT maludb_upload_document(p_title => ?, p_content_text => ?, p_source_type => 'document') AS id",
            [mb_substr(trim($text), 0, 80), $text]
        );
        $document_id = (int) $doc['id'];
        // LLM-derived → stage as 'suggested' (review queue), consistent with the rest of the pipeline
        // (the facade itself defaults to 'accepted'). The model JSON is passed verbatim.
        $row = db_one(
            "SELECT maludb_memory_ingest_extraction(
                        p_extraction => ?::jsonb, p_source_kind => 'document',
                        p_source_id => ?, p_provenance => 'suggested') AS result",
            [json_encode($extraction), $document_id]
        );
        return ['document_id' => $document_id, 'result' => ($row['result'] !== null ? json_decode($row['result']) : null)];
    });

    return [
        'document_id' => $result['document_id'],
        'model'       => $model,
        'api_format'  => $pr['api_format'],
        'namespace'   => $namespace,
        'result'      => $result['result'],
    ];
}

/**
 * /v1/memory/search core (and the MCP search_memory tool).
 *
 * $opts: query (required), subject (?string), verb (?string), namespace
 *        (default 'default'), limit (int), metric (default 'cosine'),
 *        embedding_model (?string — overrides the namespace/user/env default).
 *
 * The compartment pre-filter (subject and/or verb required) is the CALLER's
 * check: the REST route 400s, the MCP tool returns subject suggestions.
 *
 * Returns {namespace, embedding_model, results}.
 */
function mem_search_core(int $user_id, array $opts): array {
    $query     = (string) $opts['query'];
    $subject   = $opts['subject'] ?? null;
    $verb      = $opts['verb'] ?? null;
    $namespace = (string) ($opts['namespace'] ?? 'default');
    $limit     = (int) ($opts['limit'] ?? 20);
    $metric    = (string) ($opts['metric'] ?? 'cosine');

    // Same embedding model (and precedence) as document ingest:
    // caller > namespace config > the user's 'embed' choice > env default.
    $cfg        = mem_namespace_config($namespace);
    $user_embed = mem_resolve_embed_config($user_id);
    $embedding_model = isset($opts['embedding_model']) && $opts['embedding_model'] !== null && trim((string) $opts['embedding_model']) !== ''
        ? (string) $opts['embedding_model']
        : (($cfg['embedding_model'] ?? '') ?: (($user_embed['embedding_model'] ?? '') ?: (getenv('MALUDB_EMBED_MODEL') ?: 'maludb-local-dev')));

    $vector = mem_vector_literal(mem_embed($query, array_merge($user_embed, ['embedding_model' => $embedding_model])));

    $rows = db_tx_core(fn() => db_query(
        "SELECT chunk_id, statement_id, document_id, source_text, distance, similarity,
                rank_no, subject_name, verb_name
           FROM maludb_memory_search(
                    p_query_embedding => ?::maludb_core.malu_vector,
                    p_subject         => ?,
                    p_verb            => ?,
                    p_namespace       => ?,
                    p_limit           => ?,
                    p_metric          => ?)",
        [$vector, $subject, $verb, $namespace, $limit, $metric]
    ));
    foreach ($rows as &$r) {
        foreach (['chunk_id', 'statement_id', 'document_id', 'rank_no'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
        foreach (['distance', 'similarity'] as $k) {
            $r[$k] = $r[$k] === null ? null : (float) $r[$k];
        }
    }
    unset($r);

    return [
        'namespace'       => $namespace,
        'embedding_model' => $embedding_model,
        'results'         => $rows,
    ];
}

/**
 * /v1/memory/documents core (and the MCP store_document tool).
 *
 * $opts: title (required), text (required), source_type (default 'document'),
 *        media_type (?string), document_type (?string), metadata_json (JSON
 *        object string, default '{}'), projects/subjects/verbs/events
 *        (string[]), chunk_max (int), chunk_overlap (int), embedding_model
 *        (?string), explicit_model (?string), provided_edges (?array — bypass
 *        the LLM), namespace (default 'default').
 *
 * Returns {document_id, namespace, embedding_model, extractor, chunk_count, edges}.
 */
function mem_documents_core(int $user_id, array $opts): array {
    $title       = (string) $opts['title'];
    $text        = (string) $opts['text'];
    $namespace   = (string) ($opts['namespace'] ?? 'default');
    $source_type = (string) ($opts['source_type'] ?? 'document');
    $media_type  = $opts['media_type'] ?? null;
    $doc_type    = $opts['document_type'] ?? null;
    $metadata    = (string) ($opts['metadata_json'] ?? '{}');

    $projects = $opts['projects'] ?? [];
    $subjects = $opts['subjects'] ?? [];
    $verbs    = $opts['verbs'] ?? [];
    $events   = $opts['events'] ?? [];

    $chunk_max     = (int) ($opts['chunk_max'] ?? 2000);
    $chunk_overlap = (int) ($opts['chunk_overlap'] ?? 200);

    // --- config — Store A (namespace) is primary here (may be empty if no model is bound yet) ---
    $cfg = mem_namespace_config($namespace);

    // Embedding model precedence: caller > namespace config > the user's 'embed' choice > env default.
    $user_embed = mem_resolve_embed_config($user_id);
    $embedding_model = isset($opts['embedding_model']) && $opts['embedding_model'] !== null && trim((string) $opts['embedding_model']) !== ''
        ? (string) $opts['embedding_model']
        : (($cfg['embedding_model'] ?? '') ?: (($user_embed['embedding_model'] ?? '') ?: (getenv('MALUDB_EMBED_MODEL') ?: 'maludb-local-dev')));
    $default_subject = $cfg['default_subject_type'] ?? 'other';
    $default_prov    = $cfg['default_provenance'] ?? 'suggested';

    // Extraction connection: Store A (namespace) first, else borrow from Store B (model_prompts /
    // the per-user resolver) so a tenant that only configured /v1/memory/ingest can still extract
    // here. The candidate_edges contract (prompt_template) is never taken from Store B — its
    // prompt targets a different contract.
    if (mem_has_llm_connection($cfg)) {
        $extract_cfg = [
            'api_format'        => 'openai',
            'base_url'          => $cfg['base_url'] ?? '',
            'model_identifier'  => $cfg['model_identifier'] ?? '',
            'prompt_template'   => $cfg['prompt_template'] ?? null,
            'generation_params' => $cfg['generation_params'] ?? [],
            'max_tokens'        => 2048,
            'token'             => mem_resolve_token($cfg['secret_ref'] ?? null),
        ];
    } else {
        // Borrow a connection from Store B: explicit model → the user's 'extract' choice → the
        // legacy 'chatgpt-4o' model_prompts row. Only the connection crosses over — the
        // candidate_edges contract (prompt_template) never comes from Store B.
        $fb_model = isset($opts['explicit_model']) && $opts['explicit_model'] !== null
            && trim((string) $opts['explicit_model']) !== '' ? trim((string) $opts['explicit_model']) : null;
        $pr = mem_resolve_task_config($user_id, 'extract', $fb_model);
        if ($pr === null) {
            $pr = LocalDatabase::modelPrompt($fb_model ?? 'chatgpt-4o');
        }
        if ($pr !== null && trim((string) ($pr['base_url'] ?? '')) !== '') {
            $extract_cfg = [
                'api_format'        => $pr['api_format'] ?? 'openai',
                'base_url'          => $pr['base_url'] ?? '',
                'model_identifier'  => ($pr['model_identifier'] ?? '') !== '' && $pr['model_identifier'] !== null
                    ? $pr['model_identifier'] : (string) ($pr['model_name'] ?? ''),
                'prompt_template'   => $cfg['prompt_template'] ?? null,   // default candidate_edges template
                'generation_params' => (($pr['generation_params'] ?? null) !== null && $pr['generation_params'] !== '')
                    ? json_decode((string) $pr['generation_params'], true) : [],
                'max_tokens'        => (int) ($pr['max_tokens'] ?? 2048),
                'token'             => $pr['api_key'] ?? null,
            ];
        } else {
            // Neither store configured — only caller-supplied "edges" can be ingested;
            // mem_extract (if reached) errors model_not_configured.
            $extract_cfg = [
                'api_format'        => 'openai',
                'base_url'          => '',
                'model_identifier'  => '',
                'prompt_template'   => $cfg['prompt_template'] ?? null,
                'generation_params' => $cfg['generation_params'] ?? [],
                'max_tokens'        => 2048,
                'token'             => mem_resolve_token($cfg['secret_ref'] ?? null),
            ];
        }
    }

    $model_id = (string) ($extract_cfg['model_identifier'] ?? '');
    // embedding config — the user's stored embed connection (if any), with the resolved
    // embedding_model name on top.
    $embed_cfg = array_merge($user_embed, ['embedding_model' => $embedding_model]);

    // --- 1. obtain candidate edges: caller-supplied (bypass) OR LLM extraction per chunk ---
    $provided = $opts['provided_edges'] ?? null;
    $chunks   = mem_chunk($text, $chunk_max, $chunk_overlap);

    $edges = [];
    $extractor = 'provided';
    if ($provided !== null) {
        foreach ($provided as $e) { if (is_array($e)) $edges[] = $e; }
    } else {
        $extractor = 'llm';
        foreach ($chunks as $chunk) {
            foreach (mem_extract($chunk, $extract_cfg) as $e) {
                if (is_array($e)) {
                    if (!isset($e['source_span']) || trim((string) $e['source_span']) === '') $e['source_span'] = $chunk;
                    $edges[] = $e;
                }
            }
        }
    }
    if (!$edges) {
        json_error('no_edges', 'No SVPO edges to ingest (supply "edges" or configure an extraction model).', 422);
    }

    // --- 2. embed each edge (HTTP if configured, else deterministic) ---
    foreach ($edges as &$e) {
        $span = isset($e['source_span']) && trim((string) $e['source_span']) !== ''
            ? (string) $e['source_span']
            : trim((string) ($e['subject_text'] ?? '') . ' ' . (string) ($e['verb_text'] ?? ''));
        $e['__vector'] = mem_vector_literal(mem_embed($span, $embed_cfg));
        $e['source_span'] = $span;
    }
    unset($e);

    // --- 3. one transaction per document: upload, then ingest every edge ---
    $result = db_tx_core(function () use (
        $title, $text, $source_type, $media_type, $doc_type, $metadata,
        $projects, $subjects, $verbs, $events,
        $edges, $embedding_model, $default_subject, $default_prov, $model_id, $extractor, $namespace
    ) {
        $doc = db_one(
            "SELECT maludb_upload_document(
                        p_title => ?, p_content_text => ?, p_source_type => ?,
                        p_media_type => ?, p_document_type => ?,
                        p_projects => ?::text[], p_subjects => ?::text[],
                        p_verbs => ?::text[], p_events => ?::text[],
                        p_metadata_jsonb => ?::jsonb) AS id",
            [$title, $text, $source_type, $media_type, $doc_type,
             '{' . implode(',', array_map(fn($s) => '"' . str_replace('"', '\"', $s) . '"', $projects)) . '}',
             '{' . implode(',', array_map(fn($s) => '"' . str_replace('"', '\"', $s) . '"', $subjects)) . '}',
             '{' . implode(',', array_map(fn($s) => '"' . str_replace('"', '\"', $s) . '"', $verbs)) . '}',
             '{' . implode(',', array_map(fn($s) => '"' . str_replace('"', '\"', $s) . '"', $events)) . '}',
             $metadata]
        );
        $document_id = (int) $doc['id'];

        $out = [];
        foreach ($edges as $e) {
            $subject_text = trim((string) ($e['subject_text'] ?? ''));
            $verb_text    = trim((string) ($e['verb_text'] ?? ''));
            if ($subject_text === '' || $verb_text === '') {
                json_error('validation_failed', 'Each edge needs subject_text and verb_text.', 422);
            }
            $predicate  = is_array($e['predicate'] ?? null) ? json_encode($e['predicate']) : '[]';
            $subject_ty = isset($e['subject_type']) && trim((string) $e['subject_type']) !== '' ? (string) $e['subject_type'] : $default_subject;
            $confidence = (array_key_exists('confidence', $e) && $e['confidence'] !== null) ? (string) $e['confidence'] : null;
            $provenance = isset($e['provenance']) && trim((string) $e['provenance']) !== '' ? (string) $e['provenance'] : $default_prov;
            $extr_model = $model_id !== '' ? $model_id : $extractor;

            $st = db_one(
                "SELECT maludb_memory_ingest_edge(
                            p_source_kind      => 'document', p_source_id => ?,
                            p_subject_text     => ?, p_verb_text => ?,
                            p_predicate        => ?::jsonb,
                            p_embedding        => ?::maludb_core.malu_vector,
                            p_embedding_model  => ?,
                            p_subject_type     => ?,
                            p_source_span      => ?,
                            p_confidence       => ?::numeric,
                            p_provenance       => ?,
                            p_extraction_model => ?,
                            p_namespace        => ?,
                            p_document_id      => ?) AS statement_id",
                [$document_id, $subject_text, $verb_text, $predicate, $e['__vector'], $embedding_model,
                 $subject_ty, (string) $e['source_span'], $confidence, $provenance, $extr_model, $namespace, $document_id]
            );
            $out[] = [
                'statement_id' => (int) $st['statement_id'],
                'subject_text' => $subject_text,
                'verb_text'    => $verb_text,
                'subject_type' => $subject_ty,
                'provenance'   => $provenance,
            ];
        }
        return ['document_id' => $document_id, 'edges' => $out];
    });

    return [
        'document_id'     => $result['document_id'],
        'namespace'       => $namespace,
        'embedding_model' => $embedding_model,
        'extractor'       => $extractor,
        'chunk_count'     => count($chunks),
        'edges'           => $result['edges'],
    ];
}
