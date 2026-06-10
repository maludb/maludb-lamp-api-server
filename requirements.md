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

The single `.htaccess` in `/var/www/html/` contains the rewrite rules, matched in order from
most-specific to shortest. Numeric `{id}` segments are captured into named query params (`id`
for the first, `sub_id` for the second). Two **handle** rules (added with §4.12) precede the
generic rules because the `(object_kind, object_id)` handle has a *text* kind segment that the
numeric-id rules can't match; they capture `kind` into `$_GET['kind']`.

```apache
RewriteEngine On

# Handle routes (NON-numeric kind segment) — must precede the generic numeric rules:
# /v1/objects/<kind>/<id>  →  objects_id.php?kind=<kind>&id=<id>
RewriteRule ^v1/objects/([a-zA-Z_][a-zA-Z0-9_-]*)/([0-9]+)$ v1/objects_id.php?kind=$1&id=$2 [QSA,L]
# /v1/objects/<kind>       →  objects.php?kind=<kind>
RewriteRule ^v1/objects/([a-zA-Z_][a-zA-Z0-9_-]*)$ v1/objects.php?kind=$1 [QSA,L]

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

**Validation (every request) — resolved against the local MySQL `users` store (Phase 16):**

1. Read `Authorization: Bearer <token>` from headers. Missing → `401 auth_missing`; not `malu_…` → `401 auth_invalid`.
2. Strip the `malu_` prefix. Compute `sha256` of the remainder (hex).
3. Look up the hash in the **local MySQL `users` table** (`LocalDatabase::resolveToken`):
   ```sql
   SELECT user_id, role, pg_dbname, pg_user, pg_password
     FROM users
    WHERE token_hash = ? AND (expires_at IS NULL OR expires_at > NOW());
   ```
4. No row → `401 auth_invalid`. Found → `Database::configure(pg_dbname, pg_user, pg_password)` (the
   request's Postgres connection — `DB_HOST`/`DB_PORT` stay constant in `config/database.php`),
   set `$auth_user_id` + `$auth_role` (`current_role()`) in request scope.

**Why MySQL:** the local store maps each API token to a tenant's Postgres connection + role, so
one API can front many Postgres tenants. `config/local-database.php` holds the MySQL singleton
(`localhost:3306`, db/user `maludb`); `config/local-database.sql` the schema;
`tests/local_db_setup.php` creates + seeds it (migrating existing `api_tokens` hashes, so live
tokens keep working). The Postgres password is stored as plaintext in the localhost-only store.

**Token issuance (self-service) — `/v1/tokens` + `/v1/tokens/{id}`:**

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/tokens` | `tokens.php` | POST, GET | POST mints a token + stores the `users` row (returns the plaintext token ONCE); GET lists tokens for a connection (metadata only). |
| `/v1/tokens/{id}` | `tokens_id.php` | DELETE | Revoke (delete) a token row. |

Authorization is **the Postgres login itself**: the caller supplies `pg_dbname`/`pg_user`/
`pg_password` in the body and the API verifies them with `Database::testCredentials()` (a real
connection); knowing a working Postgres password authorizes minting/listing/revoking tokens for
that connection. These endpoints do not call `require_auth()` (they operate on the MySQL store).
The token value is `malu_<base64url(32 bytes)>`, stored only as a sha256 hash + an 8-char prefix.
List/revoke are scoped to rows matching the authenticated `(pg_dbname, pg_user)`.

**Tenant-connection errors:** if the Postgres connection for a token fails, the API returns a clear status instead of a generic 500 — **502 `tenant_db_auth_failed`** when the stored credential is rejected (auth failure), **503 `tenant_db_unavailable`** when the database is unreachable/missing.

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

This is the v1 surface. Total: **48 endpoint files** under `/var/www/html/v1/` (evolving as the
maludb_core facade grows; §4.11 added the 0.83.0 typed-attribute layer, §4.12 the object-with-attributes
ergonomics). Each row is one file.

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
| `notes` resource (§4.5) | rows in `maludb_memory`: `id`→memory_id (sequence), `title`→title, `body`→summary, `type`→memory_kind (default `note`; `issue` enables close/reopen), `project_id`→`payload_jsonb.project_id`. Issue state in `issue_closed_at`. CRUD + close/reopen all implemented (needed `validate_payload` + `issue_closed_at`, added server-side 2026-05-27). |
| `episodes` resource (§4.9) | Created via `maludb_core.register_episode(kind,title,summary,payload,occurred_at,occurred_until,sensitivity)`. It's SECURITY INVOKER, so the endpoint runs it with `SET LOCAL search_path TO public, maludb_core` (public first → `owner_schema='public'` tenant ownership; maludb_core in path → resolves base tables). POST-only in v1; readback is qualified within the same txn. |
| `attributes` resource (§4.11) | view `maludb_svpor_attribute` (writable) + `maludb_svpor_attribute_create/_set_provenance/_delete`. Typed property of `(target_kind, target_id)`; `target_kind` is any node kind **or** `svpor_statement` (edge attributes). Upsert on (target, attr_name). Value columns: `value_timestamp/value_range(tstzrange)/value_numeric/value_text/value_jsonb`; carries `unit`, `provenance`, `confidence`, `valid_from/valid_to`, `metadata_jsonb`, and external-ref pointer `ref_source/ref_entity/ref_key`. Same `db_tx_core()` search-path rule as episodes. |
| `attribute-templates` resource (§4.11) | view `maludb_attribute_template` + `maludb_attribute_template_create/_delete`. The form catalog: `applies_to` ∈ (episode_type, document_type, subject_type, verb), `value_type` ∈ (timestamp, tstzrange, numeric, text, jsonb, reference), `requirement` ∈ (required, recommended, optional). Create + delete only (no PATCH). |
| `attribute-check` (§4.11) | `maludb_attribute_check(target_kind, target_id) → jsonb {applies_to, type_value, missing_required[], fields[]}`. Advisory completeness check — the DB never rejects on missing attributes. |

### 4.1 Subjects

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/subjects` | `subjects.php` | GET, POST | GET query params: `q` (search), `limit` (default 50, max 200). List rows include `linked_verbs` (int count) — see §4.10. |
| `/v1/subjects/{id}` | `subjects_id.php` | GET, PATCH, DELETE | PATCH body: `{label?, type?, description?, classifier_md?}` |
| `/v1/subjects/{id}/verbs` | `subjects_id_verbs.php` | GET, POST | POST body: `{verb_id}` |
| `/v1/subjects/{id}/verbs/{verbId}` | `subjects_id_verbs_id.php` | DELETE | Unlink the verb from the subject. |
| `/v1/subjects/{id}/related-subjects` | `subjects_id_related-subjects.php` | GET, POST | POST body: `{related_subject_id}` |
| `/v1/subjects/{id}/related-subjects/{otherId}` | `subjects_id_related-subjects_id.php` | DELETE | Unlink (pair-level: removes any relationship between the two subjects). |
| `/v1/subject-relationships/{relationship_id}` | `subject-relationships_id.php` | GET, PATCH, DELETE | Row-level access to a single relationship. PATCH body: `{relationship_type?, label?, valid_from?, valid_to?}` (null clears `valid_*`; DB enforces time-order → 422). Companion to the pair-DELETE above; `relationship_id` is surfaced on POST/GET responses and the subject detail embedding. |

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
| `/v1/documents` | `documents.php` | GET, POST | POST is `multipart/form-data` with parts `file`, `filename`, `mime_type`, `description`, `document_type`, and optional comma-separated `projects` / `subjects`. Max size driven by PHP `upload_max_filesize` / `post_max_size` (start at 25 MB). |
| `/v1/documents/{id}` | `documents_id.php` | GET, PATCH, DELETE | GET returns metadata + `primary_project_id` + `tags[]` (binary download is an open question §6). PATCH `{link,unlink:{projects[],subjects[]}}` adds/removes graph links. DELETE also removes the document's graph edges. |
| `/v1/documents-backfill` | `documents-backfill.php` | POST | Runs `maludb_document_graph_backfill()` for the tenant schema (onboarding; idempotent) → `{"linked":<int>}`. |

> **Client migration note:** the desktop client today calls `/v1/files`. The server exposes `/v1/documents` (per `api-calls.md` known-mismatch note and confirmed in spec). The client must be updated before this endpoint set is usable end-to-end. Tracked separately from this server-side spec.

> **Documents as graph nodes (maludb_core 0.87.0).** Each `projects`/`subjects` name on a
> document is wired into the unified graph: a `document --concerns|mentions|involves--> subject`
> edge plus a soft tag carrying the resolved `tag_object_type`/`tag_object_id`, and
> `primary_project_id` is set from the first project. Documents are thus reachable from the
> graph endpoints (`/v1/graph/walk`, `/v1/graph/neighbors`, `/v1/edges`) and listed under
> `documents[]` on project/subject detail pages. Subject resolution reuses an existing subject
> as-is (never overriding its type), matching `maludb_upload_document`. Edge writes are
> `provenance='provided'` (explicit user input). `/v1/graph/<op>` routing was added to
> `.htaccess` so the 0.86.0 traversal endpoints resolve.

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
| `/v1/skills` | `skills.php` | GET, POST | GET query params: `visibility`, `q`; `subject`/`verb` (0.97.0) switch to tag-aware discovery via `maludb_skill_search`. |
| `/v1/skills/ingest` | `skills_ingest.php` | POST | Register a Claude Agent Skill bundle (maludb_core 0.97.0): files + canonical bundle hash + materiality + discovery extraction (LLM or deterministic fallback) + `maludb_skill_register`. `preview` returns the prompt/extraction without writing. |
| `/v1/skills/{id}` | `skills_id.php` | GET, PATCH, DELETE | PATCH on a registered agent skill (bundle_hash set) rejects name/markdown/version/packaging_kind with `409 skill_content_immutable`. |
| `/v1/skills/{id}/bundle` | `skills_id_bundle.php` | GET | Full bundle for reconstruction (files as base64; legacy markdown-only skills synthesize a one-file SKILL.md). |
| `/v1/skills/{id}/duplicate` | `skills_id_duplicate.php` | POST | Returns the new skill object (`201`). |

### 4.9 Episodes (events) + SVO statements — maludb_core 0.82.0

0.82.0 made episodes first-class (writable `maludb_episode` view + `maludb_register_episode` create
+ `maludb_episode_get` aggregate) and added a normalized subject-verb-object statement layer
(`maludb_svpor_statement`) that links people/documents/decisions to an event. The earlier
"POST-only, append-only" episode note (`api-calls.md`) is superseded.

**Search path:** the episode/statement facade views/functions and the `maludb_core.*` resolvers
reference their `malu$*` base tables + RLS grant tables unqualified, so every episode/statement
endpoint runs inside `db_tx_core()` — a txn with `SET LOCAL search_path TO public, maludb_core`
(`public` first → `owner_schema='public'` tenant ownership / RLS; `maludb_core` in path → base
tables resolve). The `*_type` picker views resolve on the default path, so those endpoints stay plain.

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/episodes` | `episodes.php` | GET, POST | GET lists `maludb_episode` (`q`/`kind`/`provenance`/`limit`, by `occurred_at`). POST → `maludb_register_episode(... p_provenance =>)`. Body `{title(req), kind?(='activity'), summary?, payload?, occurred_at?, occurred_until?, sensitivity?(='internal'), provenance?(='provided')}`. |
| `/v1/episodes/{id}` | `episodes_id.php` | GET, PATCH, DELETE | GET → `maludb_episode_get` `{episode, statements[], details[]}` (labels resolved). PATCH UPDATEs the view (title/summary/kind/payload/occurred_at/occurred_until/sensitivity/**provenance**/lifecycle_state — provenance is the accept/reject transition). DELETE removes it. |
| `/v1/episodes/{id}/statements` | `episodes_id_statements.php` | GET, POST | Event-scoped links. GET = statements where `object_kind='episode_object' AND object_id={id}`. POST = create with object defaulted to this episode. `404` if the episode is missing. |
| `/v1/statements` | `statements.php` | GET, POST | GET filters `maludb_svpor_statement` (`provenance`/`object_*`/`subject_*`/`verb_id`/`limit`); the review queue is `?provenance=suggested`. POST = general create. |
| `/v1/statements/{id}` | `statements_id.php` | GET, PATCH, DELETE | PATCH `{provenance?}` → `maludb_svpor_statement_set_provenance`; `{valid_to?}`/`{close:true}` → `maludb_svpor_statement_close`. DELETE → `maludb_svpor_statement_delete`. |
| `/v1/episode-types` | `episode-types.php` | GET, POST | Advisory event-kind picker on `maludb_episode_type` (case-insensitive unique label → 409). |
| `/v1/episode-types/{id}` | `episode-types_id.php` | PATCH, DELETE | Update/remove a picker entry; deleting does not affect episodes already tagged (no FK). |

**Statement create body** (shared by the general + episode-scoped POST): a statement is
`(subject_kind, subject_id) --verb_id--> (object_kind, object_id)`, created via the idempotent
`maludb_svpor_statement_create(...)`. The endpoint resolves names so callers needn't pre-fetch ids:
`verb` (name) | `verb_id`; `subject_kind`(='subject'), `subject_id` | `subject` (name → create-or-resolve
a person via `register_svpor_subject`, only when kind='subject'); `object_kind`+`object_id` (defaulted to
the episode on the scoped route); optional `predicate`|`predicate_id`, `valid_from`, `valid_to`,
`confidence`, `provenance`(='provided'), `source_package_id`, `metadata`. `*_kind` ∈
('subject','verb','document','episode_object','memory','source_package','claim','fact','memory_detail_object').
FK violation on a bad endpoint id → 422; unknown verb/predicate name → 422; bad kind → 422; idempotent
on the five-tuple (re-link returns the existing id). `document` is a valid kind, so a 0.81.0-uploaded
document links straight to an event by `document_id`.

### 4.11 Typed attributes + templates — maludb_core 0.83.0

0.83.0 adds typed properties ("attributes") on any node **and** edge, a template catalog that
drives forms, and an advisory completeness check. Attributes participate in the same
provenance review workflow as statements (`provided`/`suggested`/`accepted`/`rejected`); the
review queue is `GET /v1/attributes?provenance=suggested`, accepted/rejected via PATCH.

**Search path:** the attribute facade views/functions reference their `malu$*` base tables
unqualified, so every attribute / template / check endpoint runs inside `db_tx_core()`
(`SET LOCAL search_path TO public, maludb_core`), exactly like episodes/statements (§4.9).

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/attributes` | `attributes.php` | GET, POST | GET filters `maludb_svpor_attribute` (`target_kind`/`target_id`/`attr_name`/`provenance`/`limit`). POST = create/**upsert** via `maludb_svpor_attribute_create(...)` (idempotent on target+attr_name). `target_kind` = any node kind **or** `svpor_statement` (edge attrs). Body: `{target_kind(req), target_id(req), attr_name(req), value_timestamp?, value_range?, value_numeric?, value_text?, value_jsonb?, unit?, provenance?(='provided'), confidence?, valid_from?, valid_to?, metadata?, ref_source?, ref_entity?, ref_key?}`. |
| `/v1/attributes/{id}` | `attributes_id.php` | GET, PATCH, DELETE | GET one row. PATCH `{provenance}` → `..._set_provenance` (the accept/reject transition; only provenance is patchable — re-POST to change a value). DELETE → `..._delete`. |
| `/v1/attribute-templates` | `attribute-templates.php` | GET, POST | GET catalog, filter `?applies_to=&type_value=` (drives forms). POST = create via `maludb_attribute_template_create(...)`. Bad enum (`applies_to`/`value_type`/`requirement`) → 422. |
| `/v1/attribute-templates/{id}` | `attribute-templates_id.php` | GET, DELETE | Read/remove one template. No PATCH (the 0.83.0 surface exposes only create + delete) → 405. |
| `/v1/attribute-check` | `attribute-check.php` | GET | `?target_kind=&target_id=` → `maludb_attribute_check(...)` jsonb `{applies_to, type_value, missing_required[], fields[]}`. Advisory only. |

### 4.12 Object-with-attributes ergonomics — maludb_core 0.85.0

The `(object_kind, object_id)` **handle** is the canonical resource identifier across the
graph/attribute/traversal surface. This section exposes reading a handle inline with its
attributes, and creating an object + its attributes atomically.

**Routing:** the handle has a *text* kind segment, so it can't use the generic numeric-id
rewrites. Two `.htaccess` rules (placed before the generic ones) map
`/v1/objects/<kind>/<id>` → `objects_id.php?kind=&id=` and `/v1/objects/<kind>` →
`objects.php?kind=`. `object_kind` ∈ (subject, verb, document, episode_object, memory,
source_package, claim, fact, memory_detail_object, svpor_statement).

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/objects/{kind}/{id}` | `objects_id.php` | GET | `maludb_object_get(kind, id)` → `{kind, id, object, attributes, [statements, details]}`. 404 on unknown handle. |
| `/v1/objects/{kind}` | `objects.php` | POST | Atomic create: register the object then `maludb_attributes_apply(kind, id, attributes)`, return `maludb_object_get`. Supported kinds: **subject** (`{canonical_name, subject_type?, description?, classifier_md?, attributes?[]}` via `register_svpor_subject`) and **episode_object** (`{title, kind?, summary?, payload?, occurred_at?, occurred_until?, sensitivity?, provenance?, attributes?[]}` via `maludb_register_episode`). Other kinds → 422. |

**Attribute-bearing lists:** the existing list endpoints `GET /v1/subjects`, `/v1/episodes`,
`/v1/documents` accept **`?with=attributes`** — each row gains an `attributes` jsonb fetched
from the corresponding `maludb_*_with_attributes` view (one extra batched query; existing
fields unchanged).

> **No-cascade caveat:** deleting an episode/subject does **not** delete its typed attributes
> (no FK cascade in 0.85.0). Callers that hard-delete an object should delete its attributes
> first (filter `GET /v1/attributes?target_kind=&target_id=`). Test files self-clean this way.

### 4.13 Memory pipeline — document → SVPO-extraction → vector-memory (maludb_core memory)

The API is the **orchestrator and model worker**: PostgreSQL can't make outbound HTTP calls, so
the API chunks text, calls the LLM (extraction) + the embedding model, and writes results back
via the `maludb_memory_*` facades. Embeddings pass as `'[..]'::maludb_core.malu_vector`; every
embedding in a namespace must share one model + dimension.

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/memory/config` | `memory_config.php` | GET, POST, PUT | GET → `maludb_memory_model_config(namespace)`. POST/PUT: `secret_set` (token encrypted, redacted from logs) + `register_model_provider` + `register_model_alias` + `maludb_memory_set_model_config`, then read-back. |
| `/v1/memory/documents` | `memory_documents.php` | POST | Upload → chunk (in code) → extract (LLM, or caller-supplied `edges`) → embed → one tx: `maludb_upload_document` then `maludb_memory_ingest_edge` per edge. Edges default `provenance='suggested'`. |
| `/v1/memory/search` | `memory_search.php` | POST | Embed the query (same model) → `maludb_memory_search(...)`. A `subject` and/or `verb` is required (compartment pre-filter before the ANN). |
| `/v1/memory/ingest` | `memory_ingest.php` | POST | `{text, model?='chatgpt-4o', hints?, namespace?, preview?}`. Loads the model's SYSTEM prompt + LLM connection from MySQL `model_prompts`; builds the USER message `TEXT` / `HINTS` / `KNOWN_SUBJECTS` (from `maludb_subject`) / `KNOWN_VERBS` (from `maludb_verb`); calls the LLM in its `api_format` (**openai** or **anthropic**, with `model_prompts.generation_params` e.g. `{"temperature":0.1,"response_format":{"type":"json_object"}}`); then uploads the text and passes the model's JSON object **verbatim** to `maludb_memory_ingest_extraction(<json>::jsonb,'document',<doc_id>)` (maludb_core **0.92.0** — endpoint returns **501 `ingest_unavailable`** if the facade is absent). `hints` is a list of `{"subject-type","subject-name"}`. `preview:true` returns the assembled SYSTEM + USER messages without calling the model or writing. |
| `/v1/model-prompts` | `model-prompts.php` | GET, POST | Manage the per-model prompts (MySQL `model_prompts`: model_name, api_format, system_prompt, base_url, api_key, max_tokens). POST upserts; GET lists (api_key never returned, only `api_key_set`). Authorized by the Postgres login (like `/v1/tokens`). |

The `model_prompts` MySQL table stores one row per model — the system prompt may differ per model,
and `api_format` selects the request shape (OpenAI chat/completions with a system+user message, vs
Anthropic `/v1/messages` with a top-level `system`). `base_url` + `api_key` are the per-model LLM
connection. `tests/local_db_setup.php` seeds a default `chatgpt-4o` (openai) row; replace its prompt
and set its `api_key` via `POST /v1/model-prompts`. Anthropic and OpenAI request/response shapes both
live in `config/llm.php` (`llm_complete` dispatches on `api_format`).

> **Deployment privilege notes (verified against `zozocal`, maludb_core 0.91.0).** Provider kind ∈
> `{cloud_api, local_http, local_socket, local_runtime, shell_adapter, stub}` (NOT
> 'anthropic'/'openai'). Registration uses the **per-tenant self-service facades** added in 0.91.0
> — schema-local `maludb_register_model_provider` / `maludb_register_model_alias` (SECURITY
> DEFINER, granted to `maludb_memory_executor` by `enable_memory_schema`) — so the full config
> flow works for the API role with **no global model-admin grant**, and `__secret_resolve`
> (DB-resolved token) works too. (Do NOT call the global owner-only `maludb_core.register_model_*`.)
> `set_model_config`/`model_config`/`ingest_edge`/`search`/`secret_set` also work as
> `maludb_memory_executor`+`reader`. **Append-only for the executor role:** there are no delete
> facades for vector chunks (`malu$vector_chunk`/`tombstone_vector_chunk` are owner-only) or for
> providers/aliases/config bindings — only `secret_revoke` exists; GC of the rest needs a superuser.
>
> **No-creds path:** `mem_embed()` falls back to a deterministic local embedding and the process
> endpoint accepts pre-extracted `edges`, so upload→ingest→search round-trips without live models.
> Async path (`request_extraction`/`harvest_extractions`) exists but is out of scope (no worker).

### 4.14 Per-user LLM configuration — /v1/llm/* (seeded catalog, provider keys, model choices)

`tests/local_db_setup.php` seeds a `default_prompts` catalog in MySQL (one row per model × task —
OpenAI, Anthropic, Google, xAI, DeepSeek, Ollama; tasks `extract` / `skill_extract` / `embed`;
prompts from `config/prompts/extract.rich.system.txt` / `extract.simple.system.txt` /
`skill-extract.system.txt`; `INSERT IGNORE`, so re-running never overwrites operator edits). A
bearer-token holder then only stores a provider key and picks a model per task — no raw Postgres
credentials (unlike `/v1/model-prompts`). Keys and choices are keyed by `user_id` (all of a
user's tokens share them) in `user_provider_keys` / `user_model_choices`.

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/llm/catalog` | `llm_catalog.php` | GET | The seeded models × tasks with the caller's state (`key_set`, `is_choice`). Prompt text not returned (only `has_system_prompt`). |
| `/v1/llm/providers` | `llm_providers.php` | GET | The caller's stored providers — the key value is **never** returned, only `key_set`. |
| `/v1/llm/providers/{provider}` | `llm_providers.php` | PUT, DELETE | PUT `{api_key, base_url?}` — `api_key` required on first set (400); omitted on update preserves the stored key (COALESCE, like `/v1/model-prompts`); unknown provider → 422 listing the known ones. DELETE → 404 when none stored. |
| `/v1/llm/models` | `llm_models.php` | GET | One entry per task with the **effective** model; `chosen:false` rows show the legacy/server default (`chatgpt-4o` for extract; null for skill_extract/embed). |
| `/v1/llm/models/{task}` | `llm_models.php` | PUT, DELETE | PUT `{model_name, system_prompt?}` — `(model_name, task)` must exist in the catalog (422 → see GET /v1/llm/catalog); allowed before a key is stored (the response carries a `warning`). DELETE reverts to the server default (404 when no row). |

The pipelines resolve their model via `mem_resolve_task_config()` (config/llm.php), in order:
**explicit `model` in the body** (legacy `model_prompts` row first — byte-for-byte today's
behavior — then the catalog row + the caller's provider key) → **the user's choice**
(`user_model_choices` + key, with optional per-user system-prompt/base_url overrides) → **null**,
upon which each endpoint keeps its exact legacy fallback (the `chatgpt-4o` model_prompts row +
namespace config for `/v1/memory/ingest`; deterministic discovery for `/v1/skills/ingest`;
env/deterministic embedding via `mem_resolve_embed_config()` for documents/search). A missing
provider key on a catalog-resolved model → **409 `model_api_key_missing`** pointing at
`PUT /v1/llm/providers/{provider}`. Regression curls: `tests/llm_config_curls.sh`.

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
- `related_subjects` — the related subjects with `relationship_type`/`label`, the other subject's id+label, `direction`, and the relationship's temporal bounds `valid_from`/`valid_to` (same data as `GET /v1/subjects/{id}/related-subjects`). POST accepts optional `valid_from`/`valid_to`.
The dedicated sub-endpoints remain for backward compatibility with the current desktop client.

Other list endpoints may add similar fields as the client surfaces concrete need. Each addition is recorded here.

## 5. Out of scope for v1

The following are explicitly **not** in v1:

- `/v1/pools/{id}/join`, `/leave`, `/tags`, and `DELETE /v1/pools/{id}` — unverified client conventions.
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
