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

### Prompt 17 (continue → Phase 2)
> Yes, continue

**Actions:**
- **Built Phase 2 (Type lists, §4.3):**
  - `subject-types.php` — GET `maludb_subject_type` ordered by `sort_order`; returns `{type, display_name, description, sort_order}` under `subject_types`. + `tests/subject-types_curls.sh`.
  - `verb-types.php` — GET `maludb_verb_type` (adds `semantic_class`) under `verb_types`. + `tests/verb-types_curls.sh`.
  - Both read-only (401 no-token, 405 non-GET). Verified live; confirms the hyphen is preserved through the 1-segment rewrite (`/v1/subject-types` → `subject-types.php`). These are the source of the valid `type` values the create/update triggers enforce.
- Committed.

### Prompts 18–20 (Phase 3 + DB-ownership constraint)
> continue … (interrupt) you cannot create any objects directly on the database … you can [do] any CRUD related DML commands but you cannot execute DDL commands.

**Actions:**
- Investigated the link tables. Found `maludb_subject_verb` is a **non-insertable multi-table view** over `malu$vector_compartment` (no grant for our user); the only create path is `maludb_subject_verb_create(...)`, which needs embedding config the `{verb_id}` contract lacks, and there is **no delete function**. `maludb_subject_relationship` is an **insertable single-table view** (`relationship_type` NOT NULL, otherwise unvalidated).
- **User constraint:** the API must not create/alter DB objects (no DDL); data DML through already-writable objects is fine. Recorded that I had created **no** schema objects (probes were rolled back; only the approved dev token row persists).
- **Decisions:** defer subject↔verb writes (501) and write up the DB-side need; build related-subjects fully; relationship_type defaults to `related_to` (overridable).
- **Built Phase 3 (Subjects sub-resources, §4.1):**
  - `subjects_id_verbs.php` — GET lists linked verbs (works); POST → `501 not_implemented`.
  - `subjects_id_verbs_id.php` — DELETE → `501 not_implemented`.
  - `subjects_id_related-subjects.php` — GET lists related; POST `{related_subject_id, relationship_type?}` inserts into `maludb_subject_relationship` (default `related_to`; `MAX(relationship_id)+1`); 400 missing, 422 self/nonexistent, 409 duplicate.
  - `subjects_id_related-subjects_id.php` — DELETE removes the relationship either direction (200/404).
  - One copy-paste curl test file per endpoint (self-cleaning for the mutating ones).
- Wrote `docs/db-requirements.md` requesting granted `maludb_subject_verb_link`/`_unlink` functions from the DBMS project (to lift the verb-link 501s later). Added `501 not_implemented` to the §2.3 status table.
- Verified the full related-subjects lifecycle live against `fastapi.maludb.org` (link/dupe/self/missing/bidirectional visibility/custom type/delete-both/404); DB left clean. Committed.

### Prompt 27 (→ Phase 9 Episodes)
> Please move to phase 9.

**Actions:**
- Found episode creation = `maludb_core.register_episode(kind,title,summary,payload,occurred_at,occurred_until,sensitivity)`. No public episode facade view exists; the helper is SECURITY INVOKER and derives `owner_schema` from `current_schema()`. Determined that running it under `SET LOCAL search_path TO public, maludb_core` gives correct tenant ownership (`owner_schema='public'`) while resolving the core base tables.
- **Built Phase 9 (Episodes, §4.9):** `episodes.php` — POST only; defined the body contract `{title, summary?, kind? (default 'activity'), payload?, occurred_at?, occurred_until?, sensitivity? (default 'internal')}` (resolves the §6 open question). 400 missing title, 422 bad sensitivity (DB check), 405/401. + `tests/episodes_curls.sh`.
- Verified live (create default + kind/occurred_at variants; 422 path); cleaned up the 2 test episodes (delete works with search_path set; episodes are otherwise an append-only log with no v1 DELETE). Updated `requirements.md` §4.0/§6 and noted a nice-to-have public wrapper in `db-requirements.md` §6. Committed.

### Prompt 26 (→ Phase 8 Documents)
> [skip Notes; do Documents next]

**Actions:**
- Found documents need no storage decision: `maludb_source_package.content_bytes` (bytea) stores file bytes in-DB; `maludb_document` holds metadata; both direct-INSERT (sequence ids), DELETE works.
- **Built Phase 8 (Documents, §4.4):**
  - `documents.php` — GET (q/limit; joins `content_size`) + POST (`multipart/form-data`: `file`/`filename`/`mime_type`/`description`; binds bytea via `PDO::PARAM_LOB`; computes `content_size` + sha256 `content_hash`; 413 on oversize, 400 on missing file). Manual `sql_log` for the bytea insert (bytes redacted).
  - `documents_id.php` — GET (metadata + size/hash + description; no binary — download deferred §6) / DELETE (removes document + its source_package).
  - One copy-paste curl test file per endpoint (multipart, self-cleaning).
- Verified the full upload→list→detail→delete lifecycle live (real file upload, in-DB bytes, correct size/hash); DB left clean (0 orphan source packages). Updated `requirements.md` §4.0. Committed.

### Prompt 25 (continue → Phase 7 Notes)
> Yes, please continue → [Notes investigation]

**Actions:**
- Investigated Notes mapping and found **multiple hard blockers**: no issue concept in the schema; `maludb_memory` INSERT/DELETE fail (missing `validate_payload(...)` fn); `maludb_quick_add_note` permission-denied (`_upload_document_for_schema`); `maludb_upload_document` works but `maludb_document` exposes no body to read back. Confirmed `maludb_document` supports UPDATE/DELETE.
- **User decision:** skip Notes for now, move to Documents; write the full server-side requirements so they can fix it and we return. Did **not** build any Notes endpoints.
- Wrote `docs/db-requirements.md` §5: the Notes endpoint contract + the exact blockers and a minimal recommended fix path (repair `maludb_memory` writes + add an issue/closed field). Marked Phase 7 deferred in `tasks/todo.md`. Committed.

### Prompt 24 (continue → Phase 6)
> Yes, please continue

**Actions:**
- **Built Phase 6 (Skills, §4.8):**
  - `skills.php` — GET (`visibility`/`q`/`limit` filters) + POST (insert into `maludb_skill`; defaults version `1.0.0`/visibility `private`/enabled true). name→skill_name.
  - `skills_id.php` — GET / PATCH / DELETE (the skill view supports DELETE → clean CRUD).
  - `skills_id_duplicate.php` — POST via `maludb_skill_fork`; forking is DB-gated (only forkable/published skills), so a non-forkable source is caught and returned as `422 validation_failed`; 201 on success.
  - DB enforces visibility ∈ {private,shared,public} and packaging_kind ∈ {system_prompt,markdown,mcp_tool,plugin} → surfaced as 422.
  - One copy-paste curl test file per endpoint (self-cleaning via DELETE).
- Noted in `db-requirements.md` §4 that the skill **body/markdown** isn't exposed by `maludb_skill` (metadata-only via the API).
- Verified the full lifecycle live; DB left clean. Committed.

### Prompt 23 (decisions: keep link writes deferred; continue to Phase 5)
> [keep link writes deferred until unlink helpers exist] + [continue to Phase 5]

**Actions:**
- **Built Phase 5 (Pools, §4.7):**
  - `pools.php` — GET (q/limit; excludes `tombstoned`) + POST (insert into `maludb_memory_pool`, `creation_kind='api'`, `pool_id` sequence-assigned; name→pool_name, description→task_objective).
  - `pools_id.php` — GET + PATCH (name/description); no DELETE in v1 (405).
  - `pools_id_archive.php` — POST sets `lifecycle_state='archived'`+`archived_at`; `409 already_archived`; `404`.
  - One copy-paste curl test file per endpoint (parameterized by `POOL_ID` since pools have no API delete).
- Verified the full lifecycle live (create/detail/patch/archive/409/405/404). The pool view denies DELETE to the API user (no grant; consistent with v1 having no pool DELETE), so the test pool (id=8) was **tombstoned** to neutralize it (can't hard-delete) — flagged to the user.
- Committed.

### Prompt 22 (facade write-path reference)
> Before we go to 5 … how to write to each facade view [Groups A/B/C reference]

**Actions:**
- Recorded the authoritative write-path map in `docs/db-write-paths.md` (48 direct-INSERT views, 8 helper-required, 6 orchestration helpers), with live-verified annotations.
- **Verified against the live DB:** all listed helpers exist and `zozocal` has EXECUTE; `maludb_subject_relationship` (related-subjects) is indeed direct-INSERT (matches what was built); `maludb_memory_pool` has `archived_at`/`lifecycle_state` (pool archive is doable); `svpor_relationship_create.relationship_type` is FK-constrained to `malu$relationship_type` (16 valid values incl. `has_member`, `part_of`, `related_to`); **DELETE is rejected on all helper-only views and no `*_unlink`/`*_delete` helpers exist** → link removal still blocked.
- Reconciled `docs/db-requirements.md`: create side is now unblocked via helpers; the real outstanding asks are (1) unlink/delete helpers (blocks all link DELETE + PUT-replace), (2) subject-verb embedding config or a simple `maludb_subject_verb_link`, (3) project archive column/functions.
- Committed the docs. (No endpoint code changed this turn.)

### Prompt 21 (continue Phase 4 while user resolves db-requirements)
> You continue with Phase 4 while I resolve the db-requirements.md issues

**Actions:**
- **Finding:** `maludb_project` is a view of `maludb_subject WHERE subject_type='project'` — a project IS a subject (project id = subject_id). No archive/status column exists. Project→identifier links live in the SVPOR graph (`maludb_svpor_relationship` → `malu$relationship_edge`), a non-insertable multi-table view (reads work, writes don't). Cleaned up a leftover test row (subject_id=18 `detail-test-renamed`) from an earlier curl-template run.
- **Built Phase 4 (Projects, §4.6) — 8 endpoints:**
  - `projects.php` — GET (q/limit) + POST (create a subject with `subject_type='project'`; `MAX(subject_id)+1`). Projects expose `name` (→ canonical_name).
  - `projects_id.php` — GET (+ embedded `subjects[]`/`verbs[]` read from SVPOR edges), PATCH, DELETE; all scoped to `subject_type='project'` (can't touch a person-subject via /projects).
  - `projects_id_subjects.php` / `_subjects_id.php` / `_verbs.php` / `_verbs_id.php` — link/unlink → **501** (SVPOR edges not insertable by the API).
  - `projects_id_archive.php` / `_unarchive.php` — **501** (no archive column).
  - One copy-paste curl test file per endpoint (self-cleaning for the CRUD ones).
- Extended `docs/db-requirements.md`: §2 project↔subject/verb link/unlink functions, §3 project archive (column or functions). 
- Verified all 8 live against `fastapi.maludb.org` (CRUD lifecycle + scoping + 501/405/404 paths); DB left clean. Committed.

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
