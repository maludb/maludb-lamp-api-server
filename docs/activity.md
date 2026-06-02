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

## 2026-05-28 — Row-level relationship endpoint (GET/PATCH/DELETE by id)

### Prompt 32
> We need endpoints to delete and update the subject to subject relationship. [DB notes: view supports SELECT/INSERT/UPDATE/DELETE; relationship_type free-text; time-order CHECK]

**Actions:**
- Added new endpoint `subject-relationships_id.php` — GET/PATCH/DELETE at `/v1/subject-relationships/{relationship_id}`, operating directly on the writable `maludb_subject_relationship` view. PATCH accepts `{relationship_type?, label?, valid_from?, valid_to?}` (null clears `valid_*`); 400 no-fields; 404 missing; 405 otherwise.
- Surfaced `relationship_id` in existing related-subjects responses (POST, GET list, and the subject-detail `related_subjects[]` embedding) so clients can reference the new endpoint.
- **Foundation fix uncovered:** the time-order constraint on `valid_from`/`valid_to` raises **SQLSTATE 22000** (the underlying `tstzrange(from,to)` rejects inverted bounds), which my global error handler didn't map → it was returning 500. Added `22000` to the `422 validation_failed` mapping in `config/response.php`.
- Discovered (contrary to the DB note) `maludb_subject_relationship.relationship_type` is **not** FK-constrained on this table — accepts free-text (the FK note was about the object-graph `maludb_svpor_relationship` table). The endpoint reflects this; no client-side type validation.
- Verified live: full lifecycle (create→GET→PATCH→422 time-order→PATCH-clear-bounds→DELETE→404), DB left clean (only your `relationship_id=1` "project manager" row remains). Updated `requirements.md` §4.1, added curl test file, logged in `db-requirements.md`/activity. Committed.

## 2026-05-27 — related-subjects: add valid_from / valid_to

### Prompt 31
> Please check the columns for maludb_subject_relationship the api is missing the valid_from and valid_to columns.

**Actions:**
- Confirmed `maludb_subject_relationship` has `valid_from` / `valid_to` (timestamptz) which the API omitted.
- Added them to the related-subjects output in both places: `subjects_id.php` (subject-detail `related_subjects[]` embedding) and `subjects_id_related-subjects.php` (`map_related()` + GET SELECT). POST now accepts optional `valid_from`/`valid_to` (cast `::timestamptz`) and echoes them via `RETURNING`.
- Verified live: create with bounds → echoed; list + subject detail show `valid_from`/`valid_to`; create without → nulls; cleanup. Updated the curl test file, endpoint doc header, and `requirements.md` §4.10.
- User confirmed the pre-existing `Drajeo(17)→related_to→Edward(9)` row (created 23:11:40) is **their** data (used to identify the missing columns) — left untouched.

## 2026-05-27 — Remaining db-requirements completed server-side

### Prompt 30
> According to the database the remainder of db-requirements.md have been completed.

**Verified live the DBMS project added:** `maludb_svpor_relationship_delete(...)`,
`maludb_subject.archived_at` + `maludb_project_archive`/`_unarchive(p_project_id)`,
`maludb_skill.markdown`, `validate_payload(...)` (memory now writable),
`maludb_memory.issue_closed_at`. (No public episode wrapper — §6 stays as the
search_path approach, which is fine.)

**Batch 1 — Projects (§4.6) now fully implemented:**
- `projects_id_subjects_id.php` / `projects_id_verbs_id.php` — DELETE via `maludb_svpor_relationship_delete` (200/404).
- `projects_id_subjects.php` / `projects_id_verbs.php` — added **PUT** (replace set; transactional delete-then-add).
- `projects_id_archive.php` / `projects_id_unarchive.php` — via `maludb_project_archive`/`_unarchive` (409 already_archived / not_archived).
- `archived_at` surfaced on project list + detail.
- Verified full lifecycle live on a throwaway project (link/dup-409/PUT-replace/DELETE/404/archive-409/unarchive-409); DB left clean. Updated the 6 sub-resource curl test files (now self-cleaning) and db-requirements §1.2/§3 (resolved). Committed.

**Batch 2 — Skills body (§4):** `maludb_skill.markdown` now exists → added `markdown` to `skills.php` POST and `skills_id.php` GET/PATCH (list stays metadata-only). Verified round-trip live. Committed.

**Batch 3 — Notes (§4.5), now fully built:**
- `notes.php` (GET q/type/limit + POST), `notes_id.php` (GET/PATCH/DELETE), `notes_id_close-issue.php` + `notes_id_reopen-issue.php` (POST, 409 rules). Backed by `maludb_memory` (validate_payload + issue_closed_at added server-side): id→memory_id, title→title, body→summary, type→memory_kind (free-text; `issue` enables close/reopen), project_id→payload.
- Verified full note + issue lifecycle live (create/GET/PATCH/clear-project/delete; close/409-reclose/reopen/409-rereopen; close non-issue→409); DB left clean. 4 self-cleaning curl test files. requirements.md/db-requirements §5 updated. Committed.

**Result: all 32 endpoint files are now implemented** (no remaining 501s). db-requirements §6 (episode public wrapper) remains a non-blocking nice-to-have.

## 2026-05-27 — Subject↔verb linking (un-deferred)

### Prompt 28
> We need to implement: POST /v1/subjects/17/verbs {verb_id:5} … [returning 501]

**Actions:**
- The DBMS project added the helpers requested in db-requirements §1/§2: `public.maludb_subject_verb_link(subject_id, verb_id)` (idempotent, returns compartment_id) and `maludb_subject_verb_unlink(subject_id, verb_id)` (returns rows removed), both granted. Verified live (idempotent re-link, FK violation → 23503 on bad ids, listing reflects the link).
- **Implemented** `subjects_id_verbs.php` POST (link `{verb_id}`: 404 missing subject, 400 missing field, 422 nonexistent verb, 409 already-linked, 201 with verb + compartment_id) and `subjects_id_verbs_id.php` DELETE (unlink: 200 / 404). Removed the 501 stubs.
- Verified the exact failing call now returns 201; full lifecycle (link/dup/bad/missing/list/embed/delete/404) passes live; DB left clean. Updated both curl test files, `db-requirements.md` §1/§2 (subject↔verb marked resolved), `requirements.md`, `tasks/todo.md`. Committed.

### Prompt 29 (wire up project link creation)
> Yes [+ re-pasted the now-fixed subject↔verb 501]

**Actions:**
- Re-confirmed the subject↔verb POST returns **201** live (the pasted 501 was the pre-fix response; OpCache revalidates in 2s).
- Implemented **POST** on `projects_id_subjects.php` / `projects_id_verbs.php` via `maludb_svpor_relationship_create('subject', project_id, 'subject'|'verb', target_id, 'has_member')`. The helper is **not idempotent** and **doesn't FK-validate the target**, so the API checks project (404), field (400), self/nonexistent target (422), and dedupes (409) before creating. `PUT` (replace) and the `_id` `DELETE`s stay **501** — they need the SVPOR delete helper (db-requirements §1.2), so POST-created project links are currently permanent.
- Verified create + readback via rolled-back probe; live-tested only the non-destructive validation paths (404/400/422/501/405) to avoid leaving un-removable test edges (confirmed 0 stray edges). Updated the two curl test files (POST marked permanent), `db-requirements.md` §1, `tasks/todo.md`. Committed.

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

## 2026-05-28 — Phase 10: Document type support (maludb_core 0.81.0)

### Prompt
> Context: MaluDB extension upgraded to 0.81.0 — document type support. [maludb_document gains a nullable `document_type` column; maludb_upload_document gains an appended `p_document_type`; new writable lookup view `maludb_document_type` (case-insensitive unique label, seeded with 10 types, advisory/no-FK).] Update document endpoints to accept/return document_type and add a new document_types CRUD resource backed by maludb_document_type. Handle the case-insensitive unique violation as a conflict, not a 500. Add tests.

**Check-in (per CLAUDE.md #3) — two decisions confirmed by user:**
1. Keep the **direct INSERT into the `maludb_document` view** for uploads rather than switching to `maludb_upload_document(...)`: our upload writes binary bytes (PDO::PARAM_LOB → `maludb_source_package.content_bytes`) and the function only accepts *text* content (`p_content_text`/`p_content_jsonb`, no `p_content_bytes`). The view is INSERT/UPDATE-able on the new column, so adding `document_type` to the existing INSERT is the minimal change.
2. URL slug **`/v1/document-types`** (hyphen), consistent with the existing `subject-types`/`verb-types` lookup endpoints (the prompt wrote `document_types`). No PATCH added to `documents/{id}` — none exists today ("if present").

**Actions:**
- `html/v1/documents.php` — GET now selects/returns `d.document_type`; POST reads the optional multipart `document_type` field (blank ⇒ NULL) and includes it in the `maludb_document` INSERT and the response.
- `html/v1/documents_id.php` — GET now selects/returns `d.document_type`.
- **New** `html/v1/document-types.php` — `GET` (list ordered by `display_order NULLS LAST, document_type`) + `POST` (`{document_type, description?, display_order?}`; 400 missing label, 422 non-integer display_order, **409** on the case-insensitive duplicate — handled by the existing 23505→409 mapping in `config/response.php`, no new code needed).
- **New** `html/v1/document-types_id.php` — `PATCH` (label/description/display_order; 404/400/422/409) + `DELETE` (200/404; does not touch documents already tagged, no FK); 405 otherwise.
- Tests: extended `tests/documents_curls.sh` (upload with seeded "Meeting Notes" + brand-new unseeded "Totally Made Up Type", both 201 and round-tripped via GET) and `tests/documents_id_curls.sh` (document_type "Report" round-trip). Added `tests/document-types_curls.sh` and `tests/document-types_id_curls.sh` (full CRUD incl. case-insensitive 409 on both POST and PATCH).
- `php -l` clean on all four PHP files. Verified the whole suite live against `https://fastapi.maludb.org` with the dev token: 10 seeded types listed; create/PATCH/DELETE lifecycle; 400/422/409/404/405/401 paths; seeded + unseeded uploads round-trip `document_type` in GET. All created rows self-cleaned; DB left clean.
- Updated `tasks/todo.md` (Phase 10 plan + review) and this log. Commit & push pending (user asked to push everything together at the end).

---

## 2026-05-29 — Phase 11: Episodes/events + SVO statements (maludb_core 0.82.0)

### Prompt
> Context: MaluDB extension upgraded to 0.82.0 — episodes/events + subject-verb-object statements.
> [maludb_episode writable view + maludb_register_episode (new p_provenance) + maludb_episode_get
> aggregate + maludb_episode_type picker; maludb_svpor_statement writable view + idempotent
> maludb_svpor_statement_create/_close/_delete/_set_provenance; maludb_core.resolve_/register_svpor_*
> resolvers.] Build episodes (GET/GET{id}/POST/PATCH/DELETE), episode-types CRUD, statements
> (create/list/close/delete/set-provenance, both episode-scoped and general), and a
> suggested→accepted review transition. Add tests.

**Check-in (per CLAUDE.md #3):** wrote the Phase 11 plan to `tasks/todo.md`, then confirmed three
decisions with the user — (1) statement input accepts verb + subject **by name** (create-or-resolve),
(2) build **both** general `/statements`(+`/{id}`) and episode-scoped `/episodes/{id}/statements`,
(3) factor the txn+search_path boilerplate into a **shared helper**.

**Discovery (live DB introspection, txn rolled back):** all 0.82.0 objects present; the facade
views/functions and `maludb_core.*` resolvers reference `malu$*` base tables + RLS grant tables
unqualified, so they require `SET LOCAL search_path TO public, maludb_core` (current_schema stays
`public`). Seeded verbs attended/generated_by/made_during resolve to 6/7/8. `maludb_svpor_statement_create`
is idempotent; bad endpoint id → 23503; `maludb_episode_get` → `{episode, statements[], details[]}`
with labels, NULL when missing. (One probe episode leaked via autocommit and was deleted.)

**Actions:**
- `config/response.php` — added `db_tx_core()` and the SVO statement helpers
  (`svpor_create_statement` with verb/subject/predicate name resolution + shape-validate-before-write,
  `svpor_statement_cols`, `shape_statement`).
- Rewrote `episodes.php` onto the facade (GET list + POST with provenance); added `episodes_id.php`
  (GET via episode_get, PATCH incl. provenance/lifecycle_state, DELETE), `episode-types.php` +
  `episode-types_id.php` (picker CRUD), `statements.php` + `statements_id.php` (general create/list +
  row-level GET/PATCH-provenance-or-close/DELETE), and `episodes_id_statements.php` (event-scoped).
- Wrote 7 curl test files. `php -l` clean on all 8 PHP files. Verified the whole suite live against
  `https://fastapi.maludb.org`: provided+suggested episodes, full meeting model (attendee/document/decision
  links with resolved labels), idempotent re-link, FK→422, unknown-verb→422, episode-type dup→409,
  suggested→accepted on both episode and statement, close/valid_to. DB left clean (0 episodes / 0
  statements; one reusable "Regression Attendee" svpor subject persists by design — no API to delete it).
- Fixed an empty-jsonb fidelity issue (`{}` was serializing as `[]`) by decoding payload/metadata as objects.
- Updated `tasks/todo.md` (Phase 11 plan + review) and `requirements.md`. Commit/push pending with the
  earlier unpushed commits.

---

## Phase 12 — Typed attributes + templates + attribute-check (maludb_core 0.83.0) — 2026-05-29

### Prompt
> Context: expand the application API over MaluDB maludb_core 0.86.1. [Big spec covering §1 documents,
> §2 episodes, §3 SVO statements, §4 attributes + templates, §5 object-with-attributes ergonomics,
> §6 external references, §7 graph traversal + embeddings + semantic search; honor the
> provenance/confidence review flow and caller-supplied bytea embeddings everywhere.] Build documents,
> episodes, statements, attributes (+templates/check), object-with-attributes, external references,
> graph traversal + semantic search, with tests.

**Check-in (per CLAUDE.md #3):** wrote a grounded plan to `tasks/todo.md` for the whole remaining
0.86.1 surface (Phases 12–16) after live-introspecting the DB, then confirmed four decisions with the
user: (1) build all of 12–16 in sequence (commit per phase); (2) add an `.htaccess` rewrite rule for
non-numeric `(kind,id)` / `/v1/graph/*` handles; (3) reference-view scaffolder = **preview DDL only**
(honor the no-DDL rule); (4) attributes use a **general `/v1/attributes`** surface. Took the recommended
defaults on the minor decisions (with=attributes flag, episode+subject single-POST, base64 embeddings,
build search-related, dedicated /v1/references, template create+delete, top-level attribute-check).

**Discovery (live DB introspection, read-only):** the full 0.86.1 facade is already deployed in
`zozocal` (we're catching the API up from 0.82.0). Confirmed every view/function from the spec and
captured exact named-arg signatures (recorded in `tasks/todo.md`). The attribute facade
views/functions reference `malu$*` unqualified → same `db_tx_core()` search-path rule as episodes.

**Actions:**
- `config/response.php` — added the typed-attribute helpers `svpor_attribute_cols()`,
  `shape_attribute()` (int/float casts; value_jsonb + metadata decoded as objects; tstzrange left as
  text), and `svpor_create_attribute()` (parse + shape-validate before any write, then upsert via
  `maludb_svpor_attribute_create(...)` with all 17 named args).
- Added 5 endpoint files: `attributes.php` (GET filter incl. `?provenance=suggested` review queue +
  POST upsert), `attributes_id.php` (GET / PATCH-provenance / DELETE), `attribute-templates.php`
  (catalog GET + POST create), `attribute-templates_id.php` (GET + DELETE, no PATCH → 405),
  `attribute-check.php` (GET advisory completeness).
- 5 self-cleaning curl test files. `php -l` clean on all 6 files.
- Verified live against `https://fastapi.maludb.org`: node attribute create + idempotent upsert (same
  id, value updated), GET filter, suggested→accepted PATCH, missing attr_name→400, bad value_numeric→422,
  bad target→422, **edge attribute** (target_kind=svpor_statement), attribute-check before/after
  (seeded `duration_minutes` template for Meeting), template create/get/delete, bad value_type→422,
  PATCH-on-template→405. DB left clean (one reusable "Regression Attendee" svpor subject persists by
  design). Two probe episodes leaked via the test harness and were deleted (ids 25, 26).
- Updated `requirements.md` (new §4.11 + three §4.0 mapping rows + endpoint count) and this log.

---

## Phase 13 — Object-with-attributes ergonomics (maludb_core 0.85.0) — 2026-05-29

**Actions:**
- `config/response.php` — added `attach_attributes()` (batched `?with=attributes` post-merge from a
  `maludb_*_with_attributes` view; one extra query, existing row fields untouched).
- Added `html/v1/objects_id.php` (`GET /v1/objects/{kind}/{id}` → `maludb_object_get`) and
  `html/v1/objects.php` (`POST /v1/objects/{kind}` → atomic register_* + `maludb_attributes_apply` +
  `maludb_object_get`, supporting subject + episode_object).
- `html/.htaccess` — two handle rewrite rules (text kind segment) ahead of the generic numeric rules.
- Wired `?with=attributes` into `subjects.php`, `episodes.php`, `documents.php` GET (minimal, additive).
- 2 self-cleaning curl files (`objects_curls.sh`, `objects_id_curls.sh`).
- `php -l` clean on all touched files. Verified live against `https://fastapi.maludb.org`: atomic
  episode+2 attrs and subject+attr creates, handle GET round-trips, `?with=attributes` on episode/subject
  lists, unknown handle→404, unsupported kind→422, missing field→400, bad attributes shape→422,
  non-GET on handle→405. Confirmed the `.htaccess` handle rules resolve under the production Apache.
- **Finding:** deleting an episode/subject does NOT cascade its typed attributes (no FK cascade in
  0.85.0) — orphan attributes remain. Documented in requirements.md §4.12; test files delete attributes
  first. Cleaned up the 3 probe orphans (attr ids 4/5/6); DB left clean.
- Updated `requirements.md` (§4.12 + §1.3 routing + §4.0 count) and this log.

---

## Phase 14 — Documents as first-class graph nodes (maludb_core 0.87.0) — 2026-06-01

**Prompt:** "MaluDB maludb_core upgraded to 0.87.0 — documents are now first-class graph nodes.
Update the API to expose and edit those graph links." (Surface `primary_project_id` + resolved
tag `tag_object_type/tag_object_id`; add project/subject→documents listings via the graph; make
upload + edit paths maintain the document→subject edges; add a backfill onboarding endpoint;
keep edge writes `provenance='provided'`. Tests: upload-with-project, edit, remove, backfill.)
Scope decision (asked): build the full write side — **upload wiring + PATCH edit**.

**Finding that shaped the work:** the API's upload (`documents.php` POST) does *direct INSERTs*
and never called `maludb_upload_document`, so it accepted no projects/subjects and created no
tags/edges; `documents_id.php` was GET/DELETE only. So "upload already wires the graph" did not
hold for this API — the wiring had to be added in API code via the public facades.

**Actions:**
- `config/response.php` — added the document↔graph helpers (all run inside `db_tx_core()`):
  `document_link_spec()` (tag_kind → [subject_type, verb]: project/concerns, subject/mentions,
  stakeholder/involves), `document_link_subject()` (resolve-or-create subject WITHOUT clobbering
  an existing type — mirrors `maludb_core._document_graph_link`, since `register_svpor_subject`
  would override the type — then idempotent `maludb_svpor_statement_create('document'→'subject')`
  + upsert the soft tag's resolved object), `document_unlink_subject()` (delete edge via
  `maludb_svpor_statement_delete`, delete the tag, repoint `primary_project_id` to the first
  remaining project else NULL), and `document_neighbors()` (`maludb_graph_neighbors('subject',id,
  'both', ARRAY['concerns','mentions','involves'])` filtered to `neighbor_kind='document'`).
- `documents.php` — GET list now returns `primary_project_id`; POST accepts comma-separated
  `projects`/`subjects`, wires each into the graph in one tx, and sets `primary_project_id` from
  the first project.
- `documents_id.php` — GET now returns `primary_project_id` + `tags[]` (with
  `tag_object_type`/`tag_object_id`); added **PATCH** `{link,unlink:{projects[],subjects[]}}`
  (unlink-then-link, adopt primary when unset); DELETE now also removes the document's
  `svpor_statement` edges (deleting a document cascades soft tags but NOT edges → would dangle).
- `projects_id.php` / `subjects_id.php` — detail GET now embeds `documents[]` from the graph.
- `documents-backfill.php` (new) — POST → `maludb_document_graph_backfill()` (idempotent) →
  `{"linked":<int>}`; GET→405, no token→401.
- `html/.htaccess` — added the missing `/v1/graph/<op> → graph_<op>.php` rewrite (the 0.86.0
  `graph_neighbors.php`/`graph_walk.php` were present but unrouted → 404; their own docstrings
  assumed this rule). `/v1/edges` already routed via the 1-segment rule.
- Tests: extended `documents_curls.sh` (graph-linked upload + walk + project documents[],
  self-cleaning) and `documents_id_curls.sh` (replaced the now-wrong PATCH→405 case with a full
  link/swap/remove lifecycle + 400/422 cases, self-cleaning); added `documents-backfill_curls.sh`.

**Verified live against https://fastapi.maludb.org:** upload-with-project → `primary_project_id`
set, tag `tag_object_id` resolved, `graph/walk` + `graph/neighbors` reach the document, project
detail `documents[]` lists it; PATCH link/swap (primary repoints)/remove (edge gone, primary
cleared, tag removed); bad PATCH shapes → 400/422; subject detail `documents[]`; backfill connects
a tag-only (edge-less) document and is idempotent (edges 0→1, second run stays 1), 405/401 guards.
`php -l` clean on all touched files. **DB left clean** — verified 0 leftover test docs/subjects, 0
orphan tags, 0 dangling document edges (the persistent "Regression Attendee" fixture remains by
design). Confirmed deleting a document leaves dangling edges without the DELETE fix (found + swept
2 orphans during testing) — hence the edge cleanup added to DELETE.
- Updated `requirements.md` (§4.4 table + graph-nodes note) and this log.

---

## Phase 15 — document → SVPO-extraction → vector-memory endpoints (maludb_core memory) — 2026-06-02

**Prompt:** "Build: document → SVPO-extraction → vector-memory API endpoints over MaluDB."
Three endpoint groups — (1) model config (model choice + base URL + token), (2) document
processing (upload → chunk → extract SVPO → embed → ingest into the graph-bound vector store),
(3) search. The API is the orchestrator + model worker (Postgres can't call out): it chunks,
calls the LLM + embedding model, then writes back via the maludb_memory_* facades. Decisions
(asked): **grant the API role elevated rights** for the config flow; **build the real HTTP path
but verify with a deterministic embedding (no live creds yet)**; **build groups 1+2+3**.

**Review findings before building (validated live):** the facades existed only partly until the
user upgraded the DB. After the upgrade: `set_model_config`/`model_config`/`request_extraction`/
`harvest_extractions` now exist; `ingest_edge`/`search`/`secret_set`/`upload_document` work as our
role (`zozocal` = maludb_memory_executor + maludb_memory_reader + maludb_read). Still owner-only
(grants NOT yet applied): `register_model_provider`/`register_model_alias` (malu$model_provider/
alias grant write to no role) and `__secret_resolve` (needs maludb_secret_consumer). Provider kind
∈ {cloud_api, local_http, local_socket, local_runtime, shell_adapter, stub}. malu$vector_chunk is
owner-only → the vector store is append-only for the executor role.

**Actions:**
- `config/response.php` — memory helpers: `mem_vector_literal`, `mem_embed` (real OpenAI-shape
  HTTP if MALUDB_EMBED_* env set, else deterministic sha256 unit vector of MALUDB_EMBED_DIM=1536),
  `mem_chunk` (paragraph/sentence splitter w/ overlap), `mem_extract` (LLM chat → candidate_edges
  contract), `mem_resolve_token` (`__secret_resolve` w/ env fallback), `mem_http_post`,
  `db_one_redacted` (logs writes with the token param redacted). Added SQLSTATE 42501 →
  403 insufficient_privilege to the global error map.
- `html/v1/memory_config.php` (GET read-back; POST/PUT: secret_set + register provider/alias +
  set_model_config → read-back), `memory_documents.php` (POST process: config → chunk → extract
  or caller `edges` → embed → one db_tx_core(): upload_document + ingest_edge per edge; edges
  default provenance='suggested'), `memory_search.php` (POST: embed query w/ same model →
  memory_search; subject/verb pre-filter required → clean 400).
- `html/.htaccess` — `/v1/memory/<op> → memory_<op>.php` (mirrors the graph rule).
- Tests: `memory_config_curls.sh`, `memory_documents_curls.sh`, `memory_search_curls.sh`.

**Verified live against https://fastapi.maludb.org (no-creds path):** process a doc with
caller-supplied edges + deterministic embeddings → 201, 2 edges ingested ('suggested'); search
filtered by subject/verb → the edge at similarity ≈ 1.0; validation 400s (missing query /
no subject+verb / missing title|text), 409 model_not_configured (no edges + no model), config
GET read-back, config POST → 403 insufficient_privilege (expected until grant), 405/401 guards.
`php -l` clean on all files. Cleanup removed the test documents, document→subject edges, and
subjects; **append-only residue:** a few vector chunks remain in the `apismoke` namespace because
`malu$vector_chunk`/`tombstone_vector_chunk` are owner-only — a superuser must GC them.

**DBA action required to enable group-1 writes + DB-resolved token (per the chosen decision):**
```sql
GRANT maludb_llm_model_admin TO zozocal;   -- register_model_provider / register_model_alias
GRANT maludb_secret_consumer TO zozocal;   -- __secret_resolve (DB-resolved LLM token)
-- (optional) a memory-admin/superuser path to GC malu$vector_chunk (tombstone) for cleanup.
```
Until then `/v1/memory/config` POST returns 403 (everything else works). For live extraction/
embedding set env on the API host: MALUDB_LLM_TOKEN (or store via config+secret_consumer),
MALUDB_EMBED_BASE_URL / MALUDB_EMBED_TOKEN / MALUDB_EMBED_MODEL / MALUDB_EMBED_DIM.
- Updated `requirements.md` (new §4.13) and this log.

---

## Phase 15.1 — wire group-1 config to the 0.91.0 per-tenant self-service facades — 2026-06-02

**Prompt:** "We changed the enable memory function in the schema to grant the required
permissions so the administrator does not need to get involved with the creation of each role
and schema." (Re-verify and finish the config flow.)

**Finding:** maludb_core 0.91.0 (schema re-enabled 2026-06-02) adds per-tenant **self-service**
registration: schema-local `maludb_register_model_provider` / `maludb_register_model_alias`
(SECURITY DEFINER wrappers over `_register_*_for_schema`, granted to `maludb_memory_executor` by
`enable_memory_schema` via `_enable_memory_schema_0910_facade`) + read views
`maludb_model_provider`/`maludb_model_alias`. The global `maludb_core.register_model_*` remain
owner-only. `__secret_resolve` now also works for the executor role. So NO global model-admin /
secret-consumer grant is needed after all — the earlier blocker is resolved by the self-service
facades. Verified the full chain (secret_set → register provider → register alias →
set_model_config → model_config read-back → __secret_resolve) live in a rolled-back tx.

**Actions:**
- `memory_config.php` — switched the two registration calls from the global owner-only
  `maludb_core.register_model_provider`/`register_model_alias` to the schema-local
  `maludb_register_model_provider`/`maludb_register_model_alias` (resolved via search_path);
  updated the header note. `mem_resolve_token` already uses `maludb_core.__secret_resolve` (now works).
- `tests/memory_config_curls.sh` — POST now expects 200 (self-service), uses a throwaway
  `cfgtest` namespace + names; documents that provider/alias/config rows are append-only and the
  secret is revocable via `maludb_core.secret_revoke`.
- `requirements.md` §4.13 privilege note rewritten for the 0.91.0 self-service model.

**Verified live:** `POST /v1/memory/config` → 200 with full read-back (secret_ref = the NAME,
never the token); `GET` round-trips. Revoked the test secret (`secret_revoke`) and confirmed
`__secret_resolve` then fails. **Residue:** provider `zz_apitest_prov`, alias `zz_apitest_ext`,
and the `cfgtest` config binding persist (append-only for the executor role — superuser to remove).
`php -l` clean.

---

## Phase 16 — local MySQL auth/routing layer + centralized LLM layer — 2026-06-02

**Prompt:** "Integrate a local mysql database into the API structure. The local database stores
users with their auth token, role and password to connect to the Postgres database. The
connection info in config/database.php needs DB_NAME/DB_USER/DB_PASS pulled from the local
database based on the API key passed in. Also build an LLM layer to run extractions from text to
create JSON objects passed into the database. Create config/local-database.php (localhost:3306,
user/db `maludb`, password = the one in config/database.php)." Decisions (asked): MySQL
**replaces** the Postgres api_tokens auth; **centralize** the LLM layer into config/llm.php;
pg_password stored **plaintext** (localhost-only).

**Findings:** MariaDB 10.11 reachable at localhost:3306 as maludb/maludb (pass = the Postgres
password); pdo_mysql present; `maludb` DB empty. Postgres `api_tokens` had 1 row (user_id=3, dev
token, expires 2036) stored as a sha256 hash — migratable by hash without the plaintext.

**Actions:**
- `config/local-database.php` (new) — `LocalDatabase` MySQL PDO singleton (localhost:3306,
  maludb/maludb, password matching the Postgres config) + `resolveToken(hash)` →
  {user_id, role, pg_dbname, pg_user, pg_password} (expiry-checked).
- `config/local-database.sql` (new) — `users` schema (token_hash unique, user_id, role,
  pg_dbname/user/password, expires_at, device_name).
- `config/database.php` — DB_HOST/DB_PORT stay constant; name/user/pass now set per-request via
  `Database::configure(...)` (drops any stale connection). Throws if used before configure.
- `config/response.php` — `require_auth()` now hashes the token, resolves it via
  `LocalDatabase::resolveToken`, calls `Database::configure(...)` with the row's Postgres creds,
  and sets `$auth_user_id` + `$auth_role` (new `current_role()` helper). Added requires for
  local-database.php + llm.php. Moved the LLM helpers out (see below); kept mem_vector_literal +
  mem_resolve_token (DB-facing).
- `config/llm.php` (new) — centralized LLM layer: `llm_chat` (provider-agnostic chat),
  `llm_extract_json` (text→JSON), `mem_extract` (SVPO candidate_edges), `mem_embed`
  (+deterministic fallback), `mem_embed_http`, `mem_chunk`, `mem_default_prompt`, `mem_http_post`.
  The memory endpoints call these unchanged (functions relocated, names stable).
- `tests/local_db_setup.php` (idempotent create+seed/migrate), `tests/local_db_auth_curls.sh`.

**Verified live against https://fastapi.maludb.org:** dev token → MySQL hash lookup →
Database::configure(zozocal creds) → `GET /v1/subjects` 200 with real data; `/v1/memory/*` still
work (search round-trips via the relocated `mem_embed`; unconfigured model → 409); unknown /
malformed / missing token → 401. `php -l` clean on all four config files. Setup script re-runnable.

**Note:** pg_password is plaintext in the localhost MySQL store (per decision) — relies on MySQL
being localhost-only/access-controlled. The token is stored only as a sha256 hash.
- Updated `requirements.md` §1.4 and this log.

---

## Phase 16.1 — token issuance/list/revoke endpoints — 2026-06-02

**Prompt:** "I need an endpoint for generating the token and storing the data in the database."
Decision (asked): authorization is the Postgres login itself — "if they know the password to
connect to the database, they should be able to create the token"; scope = create + list + revoke.

**Actions:**
- `config/database.php` — added `Database::testCredentials($db,$user,$pass)`: verifies a Postgres
  login by connecting (fixed host/port, 5s timeout). This is the authorization primitive.
- `config/local-database.php` — added `LocalDatabase::nextUserId()`.
- `config/local-database.sql` + `tests/local_db_setup.php` — added a `token_prefix` column
  (idempotent `ALTER ... ADD COLUMN IF NOT EXISTS`) for safe listing.
- `html/v1/tokens.php` — POST (verify pg creds → mint `malu_<base64url(32)>` → store users row
  with sha256 hash + 8-char prefix → return plaintext token ONCE) and GET (list tokens for the
  authenticated connection; metadata only — never the token value or pg_password).
- `html/v1/tokens_id.php` — DELETE (verify pg creds; the token must belong to that connection →
  delete). Routes via the existing 1-seg/2-seg .htaccess rules.
- `tests/tokens_curls.sh` — self-cleaning (create → authenticate → list → revoke + negative cases).

**Verified live against https://fastapi.maludb.org:** wrong pg password → 403 pg_auth_failed;
missing creds → 400; valid creds → 201 with a token that then authenticates `GET /v1/subjects`
(200); list returns metadata only; DELETE revokes (revoked token → 401); revoke with wrong
password → 403. MySQL left clean (only the migrated seed row). `php -l` clean.

**Security:** pg_password travels in the request body (HTTPS) and is never logged (MySQL writes
bypass the Postgres sql.log tracer); the token is stored only as a sha256 hash.
- Updated `requirements.md` §1.4 and this log.

---

## Phase 16.2 — clear 502/503 for tenant connection failures — 2026-06-02

**Prompt:** "Give a better response than 500 for a bad password."

**Actions:**
- `config/database.php` — new `TenantDatabaseException` (carries SQLSTATE + isAuthFailure). The
  Database constructor now classifies a failed Postgres connect: PDO's pgsql driver surfaces
  "password authentication failed" as 08006 (not 28P01), so auth failures are detected by message
  as well as SQLSTATE.
- `config/response.php` — `handle_uncaught()` maps `TenantDatabaseException` →
  **502 `tenant_db_auth_failed`** (stored credential rejected) or **503 `tenant_db_unavailable`**
  (unreachable/missing DB), instead of the previous opaque 500.

**Verified live:** throwaway token with a wrong stored pg_password → 502 tenant_db_auth_failed;
with a bad pg_dbname (valid pw) → 503 tenant_db_unavailable; dev token unaffected (200). MySQL
left clean. `php -l` clean. Updated requirements.md §1.4 + this log.

---
