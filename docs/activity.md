# Activity Log

Chronological record of every prompt given on this project and every action taken in response, per `CLAUDE.md` #8. New entries appended each turn.

---

## 2026-05-26 — Begin implementation: first endpoint

### Prompt 7
> Please start the process by the first end point

**Actions:**
- Read project docs (`CLAUDE.md`, `requirements.md`, `tech-stack.md`, `api-calls.md`, `docs/activity.md`) and existing code (`config/database.php`, leftover `html/test_db.php`).
- Confirmed environment: PHP 8.3.6 with `pdo_pgsql`, `mbstring`, `json`, `fileinfo`. DB reachable (`zozocal` on 192.168.100.163).
- **Inspected the live schema and found major divergence from `requirements.md`:**
  - Tables are `maludb_`-prefixed and singular: `maludb_subject` (cols `subject_id`, `subject_type`, `canonical_name`, `aliases`, `description`, `created_at`, `classifier_md`), `maludb_verb`, `maludb_subject_verb`.
  - `maludb_subject_verb` is a name-keyed "compartment" table (`subject_name`, `verb_name`, namespace, embedding…), **not** an id→id join table; currently 0 rows.
  - `api_tokens` exists but with a different shape: `expires_at` (no `revoked_at`), `restaurant_id`, `device_name`; 0 rows.
  - `/var/log/maludb` missing and `/var/log` not writeable by the `maludb` user → logging needs a fallback path.
  - 3 sample subjects present (Edward Honour / Zozocal / Drajeo).
- Wrote `tasks/todo.md`: plan for `/v1/subjects` (+ the shared `config/response.php` and `html/.htaccess` foundation), with the schema discrepancies and three open decisions called out.
- Checked in with the user on the three blocking decisions before writing endpoint code (per CLAUDE.md #3).

**User decisions:** (1) build against live `maludb_*` schema + update `requirements.md`; (2) map `canonical_name AS label`; (3) validate auth against `expires_at` + seed a dev token.

**Implementation:**
- Added `config/response.php` — the one shared helper file: `require_auth()` (bearer → sha256 → `api_tokens` lookup on `expires_at > now()`), `body_json()`, `json_response()`/`json_error()` (§2.3 shape), `db_query`/`db_exec`/`db_one` (PDO + `sql.log` trace per §2.1 + `?debug=1` buffer per §2.2), `path_id`/`path_sub_id`/`query_int`/`query_str`. Log dir defaults to `/var/log/maludb/`, falls back to `/var/www/var/log/` when not writeable.
- Added `html/.htaccess` — the four §1.3 rewrite rules + an `Authorization` passthrough line.
- Added `html/v1/subjects.php` — `GET` (list; `q`/`limit`; `subject_id AS id`, `canonical_name AS label`, `subject_type AS type`; `linked_verbs` = count in `maludb_subject_verb` by `subject_name`) and `POST` (create; `subject_id` derived as `MAX+1` since no sequence; returns 201); `405` otherwise.
- Seeded a dev token `malu_devLOCAL…123` (device_name `claude-dev`, user_id 3, restaurant_id 1, 10-yr expiry; only the sha256 hash stored).
- Tested with `php -S` + curl: 401 (missing/bad token), 200 list + field mapping, `q` filter, `?debug=1` trace, 201 create, 400 missing-field, 400 malformed-JSON, 405 PATCH. Verified `sql.log` output and replayed all 8 `.htaccess` example mappings from §1.3 (all pass; built-in server doesn't read `.htaccess`). Deleted the test subject row afterward.
- Updated `requirements.md` with a new §4.0 "Live-schema mapping" table reconciling the spec names with the real `maludb_*` columns and the `expires_at` auth.
- Filled in the `tasks/todo.md` review section. Committed & pushed.

---

## 2026-05-26 — Live testing + relationship/pair design decision

### Prompt 8
> Please create a curl command that I can test with. The url is https://fastapi.maludb.org

**Actions:**
- Provided curl commands for GET/POST/auth against `https://fastapi.maludb.org/v1/subjects`.

### Prompt 9 (404 from fastapi.maludb.org)
> [curl returned Apache 404 Not Found at fastapi.maludb.org Port 80]

**Actions:**
- Diagnosed: this host (`maludb`, LAN `192.168.100.169`, Apache on :80, TLS terminated upstream) serves `/var/www/html` via the default vhost — so `fastapi.maludb.org` does reach this code. The 404 was because `mod_rewrite` is disabled and `AllowOverride None`, so `.htaccess` clean-URL rewriting is ignored. Direct `.php` returns 200.
- User confirmed `fastapi.maludb.org` points to this project's `html/`. User enabled `mod_rewrite` + `AllowOverride All` (root) and `https://fastapi.maludb.org/v1/subjects` now returns 200 end-to-end.

### Prompt 10 (design decision)
> we need decide if we want to include subject to subject relationships, and subject verb pairs combinations and not just counts.

**Actions:**
- Inspected the relevant tables: `maludb_subject_relationship` (from/to subject ids + labels, relationship_type) and `maludb_subject_verb` (name-keyed compartments) — both currently 0 rows. `maludb_svpor_relationship` is a generic edge table (also empty). Pool-level pair views (`maludb_pool_subject_verb`) are permission-denied for our DB user; core `maludb_subject_verb`/`maludb_subject_relationship` read fine.
- Presented three shapes (detail-embeds / list-embeds / sub-endpoints-only). **User chose A: list keeps counts, detail (`/v1/subjects/{id}`) embeds `verbs[]` + `related_subjects[]`; and add a `related_subjects` count to the list.**
- Implemented the list change: `GET /v1/subjects` now returns `related_subjects` (count of `maludb_subject_relationship` rows touching the subject) alongside `linked_verbs`. Verified live (both 0 today — tables empty). Lint clean.
- Documented the decision in `requirements.md` §4.10 (counts in list, full arrays in detail). Next endpoint: `subjects_id.php` (GET embeds the arrays; + PATCH/DELETE).
- Committed & pushed.

### Prompt 11
> Before we move on, can you provide me with a series of test curl commands I can include as in regression test list.

**Actions:**
- Added `tests/regression_subjects.sh` — pure curl + grep (no deps), asserts status code + a key body substring per case. Covers `/v1/subjects`: auth (missing / malformed / unknown token → 401), GET list (200 + `linked_verbs`/`related_subjects` present), `q` no-match → empty list, `limit`, `debug=1`, POST validation (missing label / malformed JSON → 400), and 405 for PATCH/PUT/DELETE on the collection. Destructive POST-create is gated behind `RUN_WRITE=1` (prints created id + cleanup hint, since no DELETE endpoint exists yet). `BASE`/`TOKEN` overridable via env.
- Verified against `https://fastapi.maludb.org`: 14/14 read-only pass; 15/15 with `RUN_WRITE=1`; removed the regression row afterward.
- Committed & pushed.

### Prompt 12
> This isn't quite what I want I want curl commands like: [example] that I can [run] from my desktop.

**Actions:**
- Added `tests/subjects_curls.sh` — a plain list of standalone, copy-paste curl commands (one per case, each preceded by an expected-result comment), matching the user's preferred multi-line `\` style with the dev token inlined. Committed.

### Prompt 13
> Perfect, let's move on to the next endpoint.

**Actions:**
- Built `html/v1/subjects_id.php` (`/v1/subjects/{id}`):
  - `GET` → subject detail with embedded `verbs[]` (joined `maludb_subject_verb`→`maludb_verb` by name) and `related_subjects[]` (from `maludb_subject_relationship`, returning the *other* endpoint with `relationship_type`/`relationship_label`/`direction`). `404 not_found` if absent.
  - `PATCH` → updates `label`/`type`/`description`/`classifier_md` (maps `label`→`canonical_name`, `type`→`subject_type`); `404` if missing, `400 bad_request` if no fields, `422 validation_failed` on empty label; returns the updated detail.
  - `DELETE` → removes the subject; `200 {deleted:true,id}` or `404`.
  - `405` for other methods.
- Verified the full lifecycle live (create → GET → PATCH → 400/422 paths → 405 → DELETE → 404) on a throwaway subject; DB left clean. This also confirms the 2-segment rewrite (`/v1/subjects/{id}` → `subjects_id.php?id=N`).
- Appended matching copy-paste commands to `tests/subjects_curls.sh`. Committed & pushed.

---

## 2026-05-26 — Build plan + Phase 1 (Verbs)

### Prompts 14–16
> What endpoint do you recommend next? … Let's first make a plan for writing the rest of the endpoints in order … one [test] file for each endpoint … Please Begin

**Actions:**
- Recommended Verbs next; agreed (via questions) on a dependency-aware order, copy-paste curl test files (one per endpoint, `tests/<endpoint>_curls.sh`), leaving the existing Subjects tests as-is. Wrote the full build plan into `tasks/todo.md` and committed it.
- **Discovered:** `maludb_subject` and `maludb_verb` are updatable **VIEWS** with triggers validating `*_type` against the type tables. An unregistered type raised a DB error that surfaced as a **blank 500**.
- **Foundation fix:** added a global exception + shutdown handler to `config/response.php` — standard JSON error body for any uncaught exception, logs detail + stack to `api.log`, maps PG SQLSTATEs (`23505`→409 `conflict`; `23502/23503/23514/22023/22P02/P0001`→422 `validation_failed`; else 500 `internal_error`). Verified: invalid `verb_type` now → **422** (was blank 500); valid/`null` type → 201 (`null` normalized to `other`).
- **Built Phase 1 (Verbs, §4.2):**
  - `verbs.php` — GET (`q`/`limit`, `linked_subjects` count) + POST (`MAX(verb_id)+1`, exposes `canonical_name`). + `tests/verbs_curls.sh`.
  - `verbs_id.php` — GET (+ embedded `subjects[]`), PATCH, DELETE. + `tests/verbs_id_curls.sh`.
  - `verbs_id_subjects.php` — GET read-only linked subjects (3-segment rewrite confirmed). + `tests/verbs_id_subjects_curls.sh`.
  - All verified live against `https://fastapi.maludb.org` on throwaway rows; DB left clean.
- Updated `requirements.md` §4.0 (views + triggers + error-handler mapping + verb columns). Committed.

---

## 2026-05-26 — Bootstrap & spec docs

### Prompt 1 (initial)
> We are starting a new project in this folder. It is a PHP based API server for MaluDB. The #1 goal is simplicity and traceable code do debug the SQL that is being called. Each endpoint will be a separate PHP file with one shared file for the connection to the database. That file already exists in the config folder called database.php. The code will run on apache 2, on ubuntu 24.04. The directory path will be /var/www for the project and /var/www/html for the web host of and /var/www/html/v1 for the v1 endpoints. Each endpoint will stand alone and not go through any routing. .htaccess will change an endpoint like v1/subject.php to v1/subject.

**Actions:**
- Read `config/database.php` (PDO singleton, PostgreSQL, host `192.168.100.163`, db `zozocal`).
- Read `tech-stack.md` (existing content is an HTMX/Bootstrap frontend template — mismatched fit for an API server).
- Began to write `tasks/todo.md` per `CLAUDE.md` #1–#3. User interrupted before any file was created.

### Prompt 2 (interrupt + redirect)
> We are not ready to implement or even write a plan. Your job right now is to help update the tech-stack.md and the requirements.md, which does not exist.

**Actions:**
- Asked three clarifying questions:
  1. How to treat existing `tech-stack.md` → **strip frontend, keep server pieces**.
  2. What `requirements.md` should cover → **functional endpoints + non-functional (SQL tracing, logging, errors) + operational (paths, auth, deployment)**.
  3. How to drive the content → **I draft, user corrects**.

### Prompt 3 (sample call shape)
> This is the shape of an expected call: `curl -X GET 'https://api.maludb.com/v1/subjects/17' -H 'Authorization: Bearer malu_pQYTIRdzGeGaoX4u-1uw3u4Ozbq61gPu5aKPbi3_Nmw' -H 'Accept: application/json'`

**Actions:**
- Extracted: dedicated `api.maludb.com` host, path-based versioning (`/v1/...`), plural resource names (`subjects`), path-param IDs (`/17`), bearer-token auth with `malu_` prefix + 43 base64url chars, JSON-only content.
- Listed open design points to be pinned before drafting: path-param convention, methods-per-file, token storage, error body, SQL tracing approach.

### Prompt 4 (point to api-calls.md)
> Also look at api-calls.md, that is a markdown file created by a client application.

**Actions:**
- Read `api-calls.md` — full client-side API call inventory.
- Catalogued the v1 surface: subjects, verbs, subject-types, verb-types, documents (vs. `files` mismatch flagged in source), notes, projects, pools, skills, episodes.
- Surfaced four open questions: (1) `/v1/files` vs `/v1/documents`; (2) inclusion of "⚠️ unverified" endpoints; (3) `/v1/episodes/{id}/replay`; (4) list denormalization (`linked_verbs`, `linked_subjects`); (5) IDs-are-remote-only convention. Proposed Option A (path passthrough + dispatcher) vs Option B (one file per URL path).

### Prompt 5 (decisions)
> Option B, is what I need. When we are debugging the flow I need only location to go to where I can see all the queries related to that endpoint and be able to debug issues. 1) it is /v1/documents. 2) Leave them out. 3) defer until later. 4) yes they need to be denormalized otherwise we make too many calls. 5) defer,

**Decisions locked:**
- **Option B** — one file per URL path; methods branched inside the file.
- Documents surface: `/v1/documents` (not `/v1/files`).
- Unverified endpoints (pools join/leave/tags/delete, episodes list/get/patch/close/reopen/delete): **out of v1**.
- `/v1/episodes/{id}/replay`: **deferred**.
- List denormalization: **yes** — `linked_verbs` on subjects list, `linked_subjects` on verbs list.
- "IDs are remote-only" rule: **deferred**.

**Actions:**
- Proposed URL→file naming rule: `/` → `_`, numeric ID positions → `_id`, hyphens preserved, file names lowercase. Provided sample mapping table.
- Computed v1 surface as **32 endpoint files** (subjects 6, verbs 3, type lists 2, documents 2, notes 4, projects 8, pools 3, skills 3, episodes 1).

### Prompt 6 (naming approved, continue)
> The naming rule is fine. Please continue

**Actions:**
- Created `docs/activity.md` (this file).
- Rewrote [`tech-stack.md`](../tech-stack.md): strip-and-keep — removed all frontend content (HTMX, Bootstrap, Alpine, custom CSS, CSRF on forms, sessions, password hashing, MVC partials, browser support, asset caching); kept and updated PHP, Apache, PostgreSQL, PDO, version control, security (re-cast for bearer-token API), code organization, deployment, version info; added new sections on one-file-per-endpoint architecture, shared singleton + response helper, SQL traceability as a first-class concern, and technology-decision rationale (why no router/ORM/framework, why one file per URL path).
- Created [`requirements.md`](../requirements.md): goals; §1 Operational (server layout, hosting, four-rule `.htaccess` rewrites, bearer-token auth with `api_tokens` schema, JSON conventions); §2 Non-functional (SQL log format, `?debug=1` meta block, error response shape + status code table, log files + rotation, token redaction); §3 Shared helpers (single `config/response.php` with ~11 functions); §4 Functional — all 32 endpoints across 9 resource sections, with method lists and per-endpoint notes; §5 Out of scope; §6 Open questions.
- Will commit and push per `CLAUDE.md` #9.

---
