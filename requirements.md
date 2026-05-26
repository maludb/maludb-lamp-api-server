# MaluDB API — Requirements

The MaluDB API is a PHP-backed JSON API that the desktop client (see [`api-calls.md`](api-calls.md)) calls over HTTPS. This document is the contract: what endpoints exist, how URLs map to files, how authentication works, how errors are returned, and how SQL is traced for debugging.

Technology choices and rationale live in [`tech-stack.md`](tech-stack.md). This document specifies the contracts and conventions.

## Goals

In priority order:

1. **Simplicity.** Smallest possible amount of code per endpoint. No framework, no router, no ORM. No Composer dependency in v1.
2. **SQL traceability.** Given a URL, a developer reaches the SQL behind a failing request in two clicks: URL → file (mechanical transformation) → query (literal text in the file).
3. **One file per endpoint.** Every URL path under `/v1/...` maps to exactly one PHP file under `/var/www/html/v1/`. HTTP methods for that URL are switched inside the file. There are 32 such files in v1.

## 1. Operational

### 1.1 Server layout (production)

```
/var/www/
├── config/
│   ├── database.php         PDO singleton (PostgreSQL). Already exists.
│   └── response.php         Shared response/auth/db helpers. See §3.
└── html/
    ├── .htaccess            URL → file rewrite rules. See §1.3.
    └── v1/                  One PHP file per endpoint path. See §4.
```

Apache `DocumentRoot` is `/var/www/html`. `config/` is **not** web-accessible.

Application logs live outside the docroot:

```
/var/log/maludb/
├── sql.log                  Every executed query. See §2.1.
├── api.log                  One line per request + 500 stack traces. See §2.4.
└── php.log                  PHP `error_log` destination.
```

The directory must be writeable by `www-data`.

### 1.2 Hosting & transport

| | |
|---|---|
| Host | `api.maludb.com` |
| TLS | HTTPS only at the edge (terminated upstream or in Apache) |
| OS | Ubuntu 24.04 LTS |
| Apache | 2.4 + `mod_rewrite`, `mod_headers`, `mod_deflate` |
| PHP | 8.2+ + `pdo`, `pdo_pgsql`, `mbstring`, `json`, `fileinfo` |
| PostgreSQL | 14+ |

### 1.3 URL → file rewriting

The single `.htaccess` in `/var/www/html/` contains exactly four rewrite rules, matched in order from longest path to shortest. Numeric `{id}` segments are captured into named query params (`id` for the first, `sub_id` for the second).

```apache
RewriteEngine On

# 4-segment: /v1/<a>/<id>/<b>/<id>  →  <a>_id_<b>_id.php?id=…&sub_id=…
RewriteRule ^v1/([a-zA-Z][a-zA-Z0-9-]*)/([0-9]+)/([a-zA-Z][a-zA-Z0-9-]*)/([0-9]+)$ \
            v1/$1_id_$3_id.php?id=$2&sub_id=$4 [QSA,L]

# 3-segment: /v1/<a>/<id>/<b>  →  <a>_id_<b>.php?id=…
RewriteRule ^v1/([a-zA-Z][a-zA-Z0-9-]*)/([0-9]+)/([a-zA-Z][a-zA-Z0-9-]*)$ \
            v1/$1_id_$3.php?id=$2 [QSA,L]

# 2-segment: /v1/<a>/<id>  →  <a>_id.php?id=…
RewriteRule ^v1/([a-zA-Z][a-zA-Z0-9-]*)/([0-9]+)$ \
            v1/$1_id.php?id=$2 [QSA,L]

# 1-segment: /v1/<a>  →  <a>.php
RewriteRule ^v1/([a-zA-Z][a-zA-Z0-9-]*)$ \
            v1/$1.php [QSA,L]
```

**Naming rule (URL → file):**

- Segment separator `/` becomes `_` in the file name.
- Numeric ID positions become the literal token `_id` in the file name and are captured as `id` (first) and `sub_id` (second) in `$_GET`.
- Hyphens are **preserved** (`related-subjects` stays `related-subjects`).
- All file names are lowercase.

**Examples:**

| URL | File | Query params from URL |
|---|---|---|
| `/v1/subjects` | `subjects.php` | — |
| `/v1/subjects/17` | `subjects_id.php` | `id=17` |
| `/v1/subjects/17/verbs` | `subjects_id_verbs.php` | `id=17` |
| `/v1/subjects/17/verbs/42` | `subjects_id_verbs_id.php` | `id=17`, `sub_id=42` |
| `/v1/subjects/17/related-subjects/99` | `subjects_id_related-subjects_id.php` | `id=17`, `sub_id=99` |
| `/v1/notes/5/close-issue` | `notes_id_close-issue.php` | `id=5` |
| `/v1/projects/3/archive` | `projects_id_archive.php` | `id=3` |
| `/v1/subject-types` | `subject-types.php` | — |

Other query-string parameters from the client (`?q=`, `?limit=`, `?visibility=`, `?state=`, `?debug=`) flow through via `[QSA]` and are read normally from `$_GET` inside the endpoint.

### 1.4 Authentication

**Mechanism:** Bearer tokens in the `Authorization` header.

**Token format:** `malu_<43 base64url chars>` (≈32 bytes of entropy after the prefix).
Example: `malu_pQYTIRdzGeGaoX4u-1uw3u4Ozbq61gPu5aKPbi3_Nmw`

**Validation (every request):**

1. Read `Authorization: Bearer <token>` from headers. Missing/malformed → `401 auth_missing`.
2. Strip the `malu_` prefix. Compute `sha256` of the remainder (hex).
3. Look up the hash in `api_tokens`:
   ```sql
   SELECT user_id FROM api_tokens
    WHERE token_hash = ? AND revoked_at IS NULL;
   ```
4. No row → `401 auth_invalid`. Found → set `$auth_user_id` in request scope, update `last_used_at` (best-effort, not gating).

**`api_tokens` table:**

```sql
CREATE TABLE api_tokens (
  id            BIGSERIAL PRIMARY KEY,
  token_hash    TEXT      NOT NULL UNIQUE,    -- sha256 hex of part after malu_
  token_prefix  TEXT      NOT NULL,           -- first 6 chars after malu_, for diagnostics
  user_id       BIGINT    NOT NULL,
  name          TEXT,                         -- e.g., "desktop-app-edward"
  last_used_at  TIMESTAMPTZ,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  revoked_at    TIMESTAMPTZ
);
CREATE INDEX api_tokens_user_id_idx ON api_tokens(user_id);
```

Token issuance/revocation is an admin operation performed via `psql` or a separate admin tool — **not** an API endpoint in v1.

### 1.5 Request/response conventions

- **Request body (POST/PATCH/PUT):** `Content-Type: application/json`, UTF-8 JSON. Exception: `POST /v1/documents` is `multipart/form-data` (see §4.4).
- **Response:** `Content-Type: application/json; charset=utf-8`. UTF-8 JSON.
- **`Accept: application/json`** is sent by the client; the server always replies JSON regardless.
- **CORS:** not required in v1 (the client is Electron, not a browser).
- **HTTP method handling:** each endpoint file branches on `$_SERVER['REQUEST_METHOD']` and returns `405 method_not_allowed` for unsupported verbs.

## 2. Non-functional

### 2.1 SQL tracing

Every prepared statement that runs through `db_query` / `db_exec` / `db_one` (§3) is appended to `/var/log/maludb/sql.log`, one block per query:

```
2026-05-26T14:23:01.123Z  subjects_id_verbs.php  GET  /v1/subjects/17/verbs  user=42
  SQL: SELECT v.id, v.canonical_name, v.type
       FROM verbs v JOIN subject_verbs sv ON sv.verb_id = v.id
       WHERE sv.subject_id = ? ORDER BY v.canonical_name
  PARAMS: [17]
  ROWS: 8
  DUR:  4.2 ms
```

Fields per entry:

- Timestamp (ISO-8601, UTC, ms precision)
- Endpoint file (`basename(__FILE__)`)
- HTTP method
- Request URI (including query string, **tokens stripped**)
- `user_id` from bearer token (or `anon` if logged before auth)
- The literal SQL text as prepared
- Bound parameters (positional list, JSON-encoded)
- Row count (returned for SELECT, affected for INSERT/UPDATE/DELETE)
- Duration in milliseconds

Endpoints that drop to raw `PDO` for an exotic case (rare) are not auto-logged — they should call `sql_log_manual()` if traceability is wanted.

### 2.2 Debug mode in responses

When `?debug=1` is present **and** the server-side constant `DEBUG_ENABLED` is `true`, every JSON response gains a `meta.debug` block:

```json
{
  "subject": { "id": 17, "label": "Edward Honour", "type": "person" },
  "meta": {
    "debug": {
      "file": "subjects_id.php",
      "queries": [
        {
          "sql": "SELECT * FROM subjects WHERE id = ?",
          "params": [17],
          "rows": 1,
          "dur_ms": 1.8
        }
      ]
    }
  }
}
```

`DEBUG_ENABLED` is `false` by default in production. It can be set via `config/env.php` (if/when added) or an Apache `SetEnv MALUDB_DEBUG 1` directive.

### 2.3 Error responses

All errors use this shape:

```json
{ "error": { "code": "string_code", "message": "Human-readable explanation." } }
```

`code` is a stable string the client can switch on. `message` is for humans (logs, dev consoles); the client never displays it verbatim to users.

**Status codes used:**

| Status | When | Common `code` values |
|---|---|---|
| `400` | Malformed JSON, missing required field, type error in body, invalid query param | `bad_request`, `body_invalid_json`, `missing_field` |
| `401` | Missing/invalid/revoked bearer token | `auth_missing`, `auth_invalid` |
| `403` | Authenticated but lacks permission for this resource | `forbidden` |
| `404` | Resource not found (after auth + permission check) | `not_found` |
| `405` | Method not supported by this endpoint file | `method_not_allowed` |
| `409` | Conflict (unique constraint, already-archived, etc.) | `conflict`, `already_archived` |
| `413` | Upload too large (`POST /v1/documents`) | `upload_too_large` |
| `415` | Unsupported `Content-Type` on request | `unsupported_media_type` |
| `422` | Validation failed (well-formed JSON, bad values; incl. DB trigger/constraint rejects) | `validation_failed` |
| `500` | Unhandled exception (also written to `api.log` with stack) | `internal_error` |
| `501` | Feature needs a DBMS-project change not yet available (see `docs/db-requirements.md`) | `not_implemented` |

The full set of `code` values grows as endpoints are implemented and is enumerated here over time.

### 2.4 Logging

Two files under `/var/log/maludb/`:

- **`sql.log`** — every query (see §2.1). Rotated daily by `logrotate`. Retain 14 days.
- **`api.log`** — one line per request: timestamp, method, path, status, duration, `user_id`, `auth_token_prefix`. Plus stack traces for any `500`. Rotated daily. Retain 14 days.

**Token redaction:** full bearer tokens are **never** written to any log. Only the 6-character `token_prefix` after `malu_`.

## 3. Shared helpers

To keep "one file per endpoint" practical, the only shared application code lives at `/var/www/config/response.php`. Endpoints `require_once __DIR__ . '/../../config/response.php';` at the top.

| Helper | Signature | Purpose |
|---|---|---|
| `require_auth()` | `(): int` | Validate bearer token, return `user_id` or emit `401` + exit. |
| `body_json()` | `(): array` | Decode `php://input` as JSON; emit `400` on malformed. |
| `json_response()` | `($data, int $status = 200): never` | Emit JSON + status + exit. Adds `meta.debug` if applicable. |
| `json_error()` | `(string $code, string $message, int $status): never` | Emit standard error body + exit. |
| `db_query()` | `(string $sql, array $params = []): array` | Prepare/execute/log/`fetchAll`. |
| `db_exec()` | `(string $sql, array $params = []): int` | Prepare/execute/log; return affected row count. |
| `db_one()` | `(string $sql, array $params = []): ?array` | Prepare/execute/log/`fetch` first row (or `null`). |
| `path_id()` | `(): int` | Return `(int) $_GET['id']` or emit `400` if absent. |
| `path_sub_id()` | `(): int` | Return `(int) $_GET['sub_id']` or emit `400` if absent. |
| `query_int()` | `(string $name, ?int $default = null, ?int $max = null): ?int` | Read & validate a query-param int. |
| `query_str()` | `(string $name, ?string $default = null, int $max_len = 200): ?string` | Read & validate a query-param string. |

No autoloader. No namespace. No class hierarchy. The file is < 200 lines.

## 4. Functional — Endpoints

This is the complete v1 surface. Total: **32 endpoint files** under `/var/www/html/v1/`. Each row is one file.

All endpoints require a valid bearer token (§1.4) unless explicitly noted.

### 4.0 Live-schema mapping (verified against the `zozocal` DB, 2026-05-26)

The original draft above assumed table/column names (`subjects.label`, `subject_verbs`,
`api_tokens.revoked_at`) that **do not exist** in the live database. Endpoints are built
against the real schema; the public JSON contract is preserved by aliasing in SQL.

> **`maludb_subject` and `maludb_verb` are updatable VIEWS** (relkind `v`), not base tables.
> INSERT/UPDATE/DELETE go through them fine, but **triggers enforce referential integrity** —
> e.g. an unregistered `verb_type`/`subject_type` is rejected (the valid set is in
> `maludb_verb_type` / `maludb_subject_type`, surfaced by §4.3). The shared error handler maps
> such trigger/constraint violations to `422 validation_failed` (or `409 conflict` for unique
> violations) rather than a 500. A `null` type is normalized by the trigger (verbs → `other`).

| API field / concept | Live DB source |
|---|---|
| `subjects` resource | view `maludb_subject` |
| subject `id` | `maludb_subject.subject_id` (bigint; **no sequence/default** — new ids derived as `MAX(subject_id)+1` at insert) |
| subject `label` | `maludb_subject.canonical_name` (aliased `canonical_name AS label`) |
| subject `type` | `maludb_subject.subject_type` |
| subject `description`, `classifier_md` | same column names |
| `verbs` resource | view `maludb_verb` (`verb_id`, `canonical_name`, `verb_type`, `description`, `classifier_md`) |
| verb `id`/`type` | `verb_id` (no sequence — `MAX(verb_id)+1`) / `verb_type`. Verbs expose `canonical_name` directly (no `label` alias). |
| subject↔verb links / `linked_verbs` / `linked_subjects` | `maludb_subject_verb`, keyed by **text** (`subject_name`, `verb_name`); `linked_verbs` = `count WHERE subject_name = canonical_name`, `linked_subjects` = `count WHERE verb_name = canonical_name` |
| subject types / verb types | `maludb_subject_type` / `maludb_verb_type` |
| **Auth** (§1.4) | `api_tokens` has **no `revoked_at`/`token_prefix`/`last_used_at`**; it has `expires_at` (NOT NULL), `restaurant_id`, `device_name`. Validation is `WHERE token_hash = ? AND expires_at > now()`. `last_used_at` update is omitted (column absent). |
| **Logs** (§1.1) | `/var/log/maludb/` if writeable, else fall back to `/var/www/var/log/` (dev without root). |
| **Errors** (§2.3/2.4) | A global handler returns the standard JSON error for any uncaught exception, logs detail+stack to `api.log`, and maps PG SQLSTATEs: `23505`→`409 conflict`; `23502/23503/23514/22023/22P02/P0001`→`422 validation_failed`; else `500 internal_error`. |
| `projects` resource | `maludb_project` = view of `maludb_subject WHERE subject_type='project'`. A project IS a subject; project id = subject_id; exposes `name`→canonical_name. No archive column (see db-requirements §3). Identifier links are SVPOR edges (read-only via API — see db-requirements §1–2). |
| `pools` resource | `maludb_memory_pool` (direct-INSERT view; `pool_id` sequence). `name`→pool_name, `description`→task_objective; `creation_kind='api'`; archive via `lifecycle_state='archived'`+`archived_at`. DELETE not granted (no v1 delete). |
| `skills` resource | `maludb_skill` (direct-INSERT, DELETE ok; `skill_id` sequence). `name`→skill_name; visibility/packaging_kind DB-enforced. Duplicate via `maludb_skill_fork`. Body/markdown not exposed (db-requirements §4). |
| `documents` resource | metadata in `maludb_document`, **bytes in `maludb_source_package.content_bytes`** (bytea). Upload = direct INSERT into both (ids sequence-assigned); `content_size`+`sha256 content_hash` computed by the API. GET = metadata only; binary download deferred (§6). DELETE removes both rows. |
| **Notes** (§4.5) | **Not built** — blocked server-side (db-requirements §5): `maludb_memory` writes fail (missing `validate_payload`), `maludb_quick_add_note` permission-denied, no issue/closed state. |
| `episodes` resource (§4.9) | Created via `maludb_core.register_episode(kind,title,summary,payload,occurred_at,occurred_until,sensitivity)`. It's SECURITY INVOKER, so the endpoint runs it with `SET LOCAL search_path TO public, maludb_core` (public first → `owner_schema='public'` tenant ownership; maludb_core in path → resolves base tables). POST-only in v1; readback is qualified within the same txn. |

### 4.1 Subjects

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/subjects` | `subjects.php` | GET, POST | GET query params: `q` (search), `limit` (default 50, max 200). List rows include `linked_verbs` (int count) — see §4.10. |
| `/v1/subjects/{id}` | `subjects_id.php` | GET, PATCH, DELETE | PATCH body: `{label?, type?, description?, classifier_md?}` |
| `/v1/subjects/{id}/verbs` | `subjects_id_verbs.php` | GET, POST | POST body: `{verb_id}` |
| `/v1/subjects/{id}/verbs/{verbId}` | `subjects_id_verbs_id.php` | DELETE | Unlink the verb from the subject. |
| `/v1/subjects/{id}/related-subjects` | `subjects_id_related-subjects.php` | GET, POST | POST body: `{related_subject_id}` |
| `/v1/subjects/{id}/related-subjects/{otherId}` | `subjects_id_related-subjects_id.php` | DELETE | Unlink. |

### 4.2 Verbs

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/verbs` | `verbs.php` | GET, POST | GET query params: `q`, `limit`. List rows include `linked_subjects` (count) — see §4.10. |
| `/v1/verbs/{id}` | `verbs_id.php` | GET, PATCH, DELETE | PATCH body: `{canonical_name?, type?, description?, classifier_md?}` |
| `/v1/verbs/{id}/subjects` | `verbs_id_subjects.php` | GET | Read-only listing of linked subjects. |

### 4.3 Identifier type lists

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/subject-types` | `subject-types.php` | GET | Distinct values from the `subject_types` enum/table. |
| `/v1/verb-types` | `verb-types.php` | GET | Distinct values from the `verb_types` enum/table. |

### 4.4 Documents

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/documents` | `documents.php` | GET, POST | POST is `multipart/form-data` with parts `file`, `filename`, `mime_type`, `description`. Max size driven by PHP `upload_max_filesize` / `post_max_size` (start at 25 MB). |
| `/v1/documents/{id}` | `documents_id.php` | GET, DELETE | GET returns metadata only; binary download endpoint is an open question (§6). |

> **Client migration note:** the desktop client today calls `/v1/files`. The server exposes `/v1/documents` (per `api-calls.md` known-mismatch note and confirmed in spec). The client must be updated before this endpoint set is usable end-to-end. Tracked separately from this server-side spec.

### 4.5 Notes

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/notes` | `notes.php` | GET, POST | POST body: `{title, body, type?, project_id?}` |
| `/v1/notes/{id}` | `notes_id.php` | GET, PATCH, DELETE | |
| `/v1/notes/{id}/close-issue` | `notes_id_close-issue.php` | POST | Sets `issue_closed_at = now()` on notes where `type = 'issue'`. `409` if not an issue. |
| `/v1/notes/{id}/reopen-issue` | `notes_id_reopen-issue.php` | POST | Clears `issue_closed_at`. `409` if not an issue or not closed. |

### 4.6 Projects

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/projects` | `projects.php` | GET, POST | |
| `/v1/projects/{id}` | `projects_id.php` | GET, PATCH, DELETE | |
| `/v1/projects/{id}/archive` | `projects_id_archive.php` | POST | `409 already_archived` if already archived. |
| `/v1/projects/{id}/unarchive` | `projects_id_unarchive.php` | POST | `409 not_archived` if not archived. |
| `/v1/projects/{id}/subjects` | `projects_id_subjects.php` | POST, PUT | POST links one (`{subject_id}`); PUT replaces the full set (`{subject_ids: [...]}`). |
| `/v1/projects/{id}/subjects/{sid}` | `projects_id_subjects_id.php` | DELETE | Unlink one subject. |
| `/v1/projects/{id}/verbs` | `projects_id_verbs.php` | POST, PUT | Same shape as `/subjects` above, `verb_id`/`verb_ids`. |
| `/v1/projects/{id}/verbs/{vid}` | `projects_id_verbs_id.php` | DELETE | Unlink one verb. |

### 4.7 Memory pools

(Unverified pool endpoints from `api-calls.md` — `join`, `leave`, `tags`, and `DELETE /v1/pools/{id}` — are intentionally **not** in v1.)

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/pools` | `pools.php` | GET, POST | |
| `/v1/pools/{id}` | `pools_id.php` | GET, PATCH | No DELETE in v1. |
| `/v1/pools/{id}/archive` | `pools_id_archive.php` | POST | |

### 4.8 Skills

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/skills` | `skills.php` | GET, POST | GET query param: `visibility` (optional filter). |
| `/v1/skills/{id}` | `skills_id.php` | GET, PATCH, DELETE | |
| `/v1/skills/{id}/duplicate` | `skills_id_duplicate.php` | POST | Returns the new skill object (`201`). |

### 4.9 Episodes (activity)

Per the client's own contract note (`api-calls.md`), only `POST /v1/episodes` is in the published v1 contract. Episode list / get / patch / close / reopen / delete are unverified client conventions and are **not** included in v1. `/v1/episodes/{id}/replay` is deferred.

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/episodes` | `episodes.php` | POST | Body shape follows the existing `createRemoteEpisode` contract in `src/main/api/episodes.ts` (client). Pinning the exact JSON shape is open (§6). |

### 4.10 List denormalization

To avoid N+1 calls from the client, list endpoints embed **count** fields in each row;
the full related collections are embedded in the single-resource **detail** response, not the list
(decision 2026-05-26 — keeps lists cheap, makes detail a one-call fetch).

- `GET /v1/subjects` → each row includes:
  - `linked_verbs` — count of `maludb_subject_verb` rows where `subject_name = canonical_name`.
  - `related_subjects` — count of `maludb_subject_relationship` rows where the subject is either endpoint (`from_subject_id` or `to_subject_id`).
- `GET /v1/verbs` → each row includes `linked_subjects` (count of `maludb_subject_verb` rows where `verb_name = canonical_name`).

**Detail embedding** (`GET /v1/subjects/{id}`) returns the subject plus the full arrays inline:
- `verbs` — the linked verbs (same data as `GET /v1/subjects/{id}/verbs`).
- `related_subjects` — the related subjects with `relationship_type`/`label` and the other subject's id+label (same data as `GET /v1/subjects/{id}/related-subjects`).
The dedicated sub-endpoints remain for backward compatibility with the current desktop client.

Other list endpoints may add similar fields as the client surfaces concrete need. Each addition is recorded here.

## 5. Out of scope for v1

The following are explicitly **not** in v1:

- `/v1/pools/{id}/join`, `/leave`, `/tags`, and `DELETE /v1/pools/{id}` — unverified client conventions.
- `/v1/episodes/{id}` plus list / get / patch / close / reopen / delete — unverified.
- `/v1/episodes/{id}/replay` — deferred.
- Token issuance/revocation endpoints — admin operation, not API.
- CORS / browser callers — Electron client only.
- A `/v1/files` alias for `/v1/documents` — client must update.
- An "IDs are remote-only" convention statement — deferred.
- Pagination beyond `limit` — deferred until a list grows enough to need cursors.
- Automated test framework — manual + `curl` for v1.

## 6. Open questions

These remain to be answered before — or during — implementation:

- ~~**Episode `POST` body shape.**~~ **Resolved** (2026-05-26): the endpoint defines `{title (required), summary?, kind? (default 'activity'), payload?, occurred_at?, occurred_until?, sensitivity? (default 'internal')}`, mapped to `register_episode(...)`. Revisit if the client's `createRemoteEpisode` contract differs.
- **Episode read path.** With only `POST /v1/episodes` in v1, how does the client retrieve created activities? Is it via a different surface (notes? a derived view?) until the GET endpoints are blessed?
- **Document download.** The client references `GET /v1/documents/{id}/download` in a code comment but doesn't call it. Whether to spec it in v1 or defer.
- **`config/env.php` vs Apache `SetEnv`.** Where `DEBUG_ENABLED` lives in production.
- **Logrotate ownership.** Whether the API rotates logs itself or `logrotate.d/maludb-api` is shipped separately.
