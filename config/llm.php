<?php
/**
 * LLM layer for the MaluDB API.
 *
 * PostgreSQL can't make outbound HTTP calls, so the API is the model worker: it calls the LLM
 * (extraction: text → JSON objects) and the embedding model, then writes the results back via
 * the maludb_* facades. This file centralizes ALL outbound model calls so endpoints share one
 * provider-agnostic layer:
 *
 *   - llm_chat($cfg, $prompt)        → string  (provider-agnostic chat completion)
 *   - llm_extract_json($text, $cfg)  → array   (text → decoded JSON; candidate-edges by default)
 *   - mem_extract($chunk, $cfg)      → array   (SVPO candidate_edges, used by the memory pipeline)
 *   - mem_embed($text, $cfg)         → float[] (embedding; deterministic fallback w/o creds)
 *   - mem_chunk($text, $max, $ov)    → string[] (chunking is the API's job; the DB does not chunk)
 *   - mem_http_post(...)             → string  (JSON POST over cURL)
 *
 * $cfg shape (from maludb_memory_model_config / env): base_url, model_identifier, token,
 * prompt_template, generation_params, embedding_model. No live creds? mem_embed() falls back to
 * a deterministic local vector so the pipeline still round-trips.
 *
 * Required by config/response.php; it may call db_* helpers at runtime (defined there).
 */

/* ----------------------------- embeddings ------------------------------ */

// Dimension of the deterministic fallback embedding. Every embedding in a namespace must share
// one model + dimension; keep this stable per namespace. Override via env.
function mem_embed_dim(): int {
    $d = (int) (getenv('MALUDB_EMBED_DIM') ?: 1536);
    return $d > 0 ? $d : 1536;
}

/**
 * Embed text. If a real embedding endpoint is configured (env MALUDB_EMBED_* or cfg), call it;
 * otherwise return a deterministic unit vector derived from the text so the same span always
 * embeds identically (enough to round-trip search in tests). Returns float[] of mem_embed_dim().
 */
function mem_embed(string $text, array $cfg = []): array {
    $base  = $cfg['embedding_base_url'] ?? (getenv('MALUDB_EMBED_BASE_URL') ?: '');
    $tok   = $cfg['embedding_token']    ?? (getenv('MALUDB_EMBED_TOKEN') ?: '');
    $model = $cfg['embedding_model']    ?? (getenv('MALUDB_EMBED_MODEL') ?: '');
    if ($base !== '' && $tok !== '' && $model !== '') {
        return mem_embed_http($text, $base, $tok, $model);   // real provider (OpenAI-shape)
    }
    return mem_embed_deterministic($text);
}

/** Deterministic sha256-seeded unit vector of mem_embed_dim() floats in [-1,1], L2-normalized. */
function mem_embed_deterministic(string $text): array {
    $dim = mem_embed_dim();
    $vec = [];
    $i = 0; $sum = 0.0;
    while (count($vec) < $dim) {
        $block = hash('sha256', $text . ':' . $i, true);
        for ($b = 0; $b < strlen($block) && count($vec) < $dim; $b++) {
            $v = (ord($block[$b]) - 127.5) / 127.5;
            $vec[] = $v; $sum += $v * $v;
        }
        $i++;
    }
    $norm = sqrt($sum) ?: 1.0;
    return array_map(fn($v) => $v / $norm, $vec);
}

/** Call an OpenAI-shape embeddings endpoint (POST {input,model} → {data:[{embedding}]}). */
function mem_embed_http(string $text, string $base, string $token, string $model): array {
    $resp = mem_http_post(
        rtrim($base, '/') . '/embeddings',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode(['input' => $text, 'model' => $model])
    );
    $data = json_decode($resp, true);
    $emb  = $data['data'][0]['embedding'] ?? null;
    if (!is_array($emb)) {
        json_error('upstream_error', 'Embedding provider returned no vector.', 502);
    }
    return array_map('floatval', $emb);
}

/* ------------------------------ chunking ------------------------------- */

/**
 * Split text into chunks of ~$max characters with $overlap-char overlap, preferring paragraph
 * then sentence boundaries. Verbatim text is preserved — each chunk is what gets embedded and
 * stored as the edge source_span.
 */
function mem_chunk(string $text, int $max = 2000, int $overlap = 200): array {
    $text = trim($text);
    if ($text === '') return [];
    if (mb_strlen($text) <= $max) return [$text];
    $chunks = [];
    $len = mb_strlen($text);
    $pos = 0;
    while ($pos < $len) {
        $slice = mb_substr($text, $pos, $max);
        if ($pos + $max < $len) {
            $cut = max(
                mb_strrpos($slice, "\n\n") ?: -1,
                mb_strrpos($slice, '. ')   ?: -1,
                mb_strrpos($slice, ' ')    ?: -1
            );
            if ($cut > $max * 0.5) { $slice = mb_substr($slice, 0, $cut + 1); }
        }
        $slice = trim($slice);
        if ($slice !== '') $chunks[] = $slice;
        $advance = max(1, mb_strlen($slice) - $overlap);
        $pos += $advance;
    }
    return $chunks;
}

/* ----------------------------- extraction ------------------------------ */

/**
 * Provider-agnostic chat completion. Sends a single user prompt (OpenAI-shape chat/completions,
 * which most cloud_api adapters accept) and returns the assistant's text content. $cfg needs
 * base_url, model_identifier, token; generation_params is merged into the request body.
 */
function llm_chat(array $cfg, string $prompt): string {
    $base  = $cfg['base_url'] ?? '';
    $token = $cfg['token'] ?? null;
    $model = $cfg['model_identifier'] ?? '';
    if ($base === '' || $token === null || $model === '') {
        json_error('model_not_configured', 'No LLM model/token configured for this call.', 409);
    }
    $gen  = is_array($cfg['generation_params'] ?? null) ? $cfg['generation_params'] : [];
    $body = array_merge([
        'model'    => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ], $gen);
    $resp    = mem_http_post(
        rtrim($base, '/') . '/chat/completions',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode($body)
    );
    $data    = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content)) {
        json_error('upstream_error', 'LLM returned no content.', 502);
    }
    return $content;
}

/**
 * Run the prompt template (with {{chunk}}/{{text}} substituted) through the LLM and decode the
 * JSON object it returns. Returns the decoded associative array. Used to turn raw text into
 * structured JSON objects for the database.
 */
function llm_extract_json(string $text, array $cfg): array {
    $tmpl   = $cfg['prompt_template'] ?? mem_default_prompt();
    $prompt = str_replace(['{{chunk}}', '{{text}}'], [$text, $text], $tmpl);
    $content = llm_chat($cfg, $prompt);
    $parsed  = json_decode($content, true);
    if (!is_array($parsed)) {
        json_error('upstream_error', 'LLM output was not valid JSON.', 502);
    }
    return $parsed;
}

/**
 * Extract SVPO candidate edges from a chunk via the configured LLM. Returns the candidate_edges
 * array (the memory pipeline's contract). Callers without creds should supply pre-extracted edges
 * instead of calling this.
 */
function mem_extract(string $chunk, array $cfg): array {
    $parsed = llm_extract_json($chunk, $cfg);
    $edges  = $parsed['candidate_edges'] ?? null;
    if (!is_array($edges)) {
        json_error('upstream_error', 'LLM output was not the candidate_edges contract.', 502);
    }
    return $edges;
}

/**
 * Provider-agnostic system+user completion, dispatched by $cfg['api_format'] ('openai' |
 * 'anthropic'). $cfg needs: api_format, base_url, model_identifier, token; optional max_tokens,
 * generation_params. Returns the assistant text. Used by /v1/memory/ingest (per-model prompts).
 */
function llm_complete(array $cfg, string $system, string $user): string {
    $fmt = strtolower((string) ($cfg['api_format'] ?? 'openai'));
    return $fmt === 'anthropic'
        ? llm_complete_anthropic($cfg, $system, $user)
        : llm_complete_openai($cfg, $system, $user);
}

/** OpenAI chat/completions with a system + user message. base_url e.g. https://api.openai.com/v1 */
function llm_complete_openai(array $cfg, string $system, string $user): string {
    $base = $cfg['base_url'] ?? ''; $token = $cfg['token'] ?? null; $model = $cfg['model_identifier'] ?? '';
    if ($base === '' || $token === null || $token === '' || $model === '') {
        json_error('model_not_configured', 'OpenAI base_url/api_key/model not configured.', 409);
    }
    $gen  = is_array($cfg['generation_params'] ?? null) ? $cfg['generation_params'] : [];
    $body = array_merge([
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
    ], $gen);
    $resp = mem_http_post(
        rtrim($base, '/') . '/chat/completions',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode($body)
    );
    $data    = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content)) json_error('upstream_error', 'OpenAI returned no content.', 502);
    return $content;
}

/** Anthropic messages API. base_url e.g. https://api.anthropic.com ; system is a top-level field. */
function llm_complete_anthropic(array $cfg, string $system, string $user): string {
    $base = $cfg['base_url'] ?? ''; $token = $cfg['token'] ?? null; $model = $cfg['model_identifier'] ?? '';
    if ($base === '' || $token === null || $token === '' || $model === '') {
        json_error('model_not_configured', 'Anthropic base_url/api_key/model not configured.', 409);
    }
    $body = [
        'model'      => $model,
        'max_tokens' => (int) ($cfg['max_tokens'] ?? 2048),
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $user]],
    ];
    if (is_array($cfg['generation_params'] ?? null)) $body = array_merge($body, $cfg['generation_params']);
    $resp = mem_http_post(
        rtrim($base, '/') . '/v1/messages',
        ['x-api-key: ' . $token, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
        json_encode($body)
    );
    $data = json_decode($resp, true);
    // content is an array of blocks; concatenate the text blocks.
    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && isset($block['text'])) $text .= $block['text'];
    }
    if ($text === '') json_error('upstream_error', 'Anthropic returned no text content.', 502);
    return $text;
}

/**
 * Decode a JSON object from an LLM response that may wrap it in prose or a ```json fence.
 * Tries a straight decode, then a fenced block, then the first {...} span. Returns array or null.
 */
function llm_json_from_text(string $content): ?array {
    $try = json_decode(trim($content), true);
    if (is_array($try)) return $try;
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $m)) {
        $try = json_decode($m[1], true);
        if (is_array($try)) return $try;
    }
    $start = strpos($content, '{');
    $end   = strrpos($content, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $try = json_decode(substr($content, $start, $end - $start + 1), true);
        if (is_array($try)) return $try;
    }
    return null;
}

/** Built-in extraction prompt (used when no template is configured). Must contain {{chunk}}. */
function mem_default_prompt(): string {
    return "Extract Subject-Verb-Predicate-Object edges from the text. Use SMALL canonical verbs "
         . "(e.g. \"upgrade\", not \"performed_upgrade\"); put status/timing/role/detail into the "
         . "predicate array as edge-attributes (value_text / value_timestamp / value_numeric). "
         . "Prefer subject_type in person|software|project|other. Return ONLY JSON of the form "
         . "{\"candidate_edges\":[{\"subject_text\":\"\",\"subject_type\":\"\",\"verb_text\":\"\","
         . "\"predicate\":[{\"attr_name\":\"\",\"value_text\":\"\"}],\"source_span\":\"\",\"confidence\":0.0}]}.\n\n"
         . "Text:\n{{chunk}}";
}

/* ------------------------------- transport ----------------------------- */

/** Minimal JSON POST over cURL with a hard timeout. Returns the response body; maps errors. */
function mem_http_post(string $url, array $headers, string $json): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int) (getenv('MALUDB_HTTP_TIMEOUT') ?: 60),
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        json_error('upstream_error', 'Model HTTP call failed: ' . $err, 502);
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
        json_error('upstream_error', 'Model endpoint returned HTTP ' . $code . '.', 502);
    }
    return (string) $body;
}
