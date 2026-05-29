# Todo — First endpoint: `GET/POST /v1/subjects`

The "first endpoint" is `/v1/subjects` → `html/v1/subjects.php` (requirements.md §4.1).
No endpoint can run without the shared helpers and URL rewriting, so this first
slice also stands up the foundation that all 32 endpoints will reuse.

## ⚠️ Blocking discrepancies (need your decision — see "Open decisions")

The live `zozocal` DB does **not** match `requirements.md`:

| `requirements.md` | Live database |
|---|---|
| table `subjects` | `maludb_subject` |
| `id`, `label`, `type`, `description`, `classifier_md` | `subject_id`, `canonical_name`, `subject_type`, `description`, `classifier_md` |
| join table `subject_verbs` (by subject_id/verb_id) | `maludb_subject_verb` — keyed by **text** (`subject_name`, `verb_name`), 0 rows |
| `api_tokens` w/ `revoked_at`, `token_prefix`, `last_used_at`, `name` | `api_tokens` w/ `expires_at`, `restaurant_id`, `device_name`, **0 rows** |
| logs in `/var/log/maludb/` | dir missing; `/var/log` not writeable by `maludb` user |

## Decisions (confirmed by user 2026-05-26)

1. Build against the live `maludb_*` schema; update `requirements.md` to match. ✔
2. Map `canonical_name AS label` to preserve the client contract. ✔
3. Validate auth against `expires_at`; seed one dev token for testing. ✔

## Plan

- [x] **Foundation — `config/response.php`** (shared, single file, ~<200 lines)
  - `require_auth(): int` — `SELECT user_id FROM api_tokens WHERE token_hash=? AND expires_at > now()` (no `revoked_at` exists). 401 on miss.
  - `body_json()`, `json_response()`, `json_error()` per §2.3 error shape.
  - `db_query` / `db_exec` / `db_one` — PDO wrappers that append every query to `sql.log` (§2.1).
  - `path_id()`, `query_int()`, `query_str()`.
  - Log dir: default `/var/log/maludb/`, **fallback** to a writeable local dir (e.g. `/var/www/var/log/`) when the default isn't writeable, so dev works without root.
- [x] **Foundation — `html/.htaccess`** — the four rewrite rules verbatim from §1.3.
- [x] **Endpoint — `html/v1/subjects.php`**
  - `GET /v1/subjects?q=&limit=` — list subjects; map `subject_id AS id, canonical_name AS label, subject_type AS type`; include `linked_verbs` count from `maludb_subject_verb` (join by `subject_name = canonical_name`); `q` filters `canonical_name`/`description` ILIKE; `limit` default 50 max 200.
  - `POST /v1/subjects` — insert `canonical_name`, `subject_type`, `description`, `classifier_md`; return the created row (201).
  - `405` for other methods.
- [x] **Auth for testing** — seeded dev `api_tokens` row; token `malu_devLOCAL…123` (hash only in DB; user_id=3, restaurant_id=1, 10-year expiry).
- [x] **Test** — curl GET + POST against `php -S`; auth/validation/405 all pass; `sql.log` writes; `?debug=1` block verified; 8/8 rewrite-rule cases match §1.3.
- [x] **Sync docs** — added §4.0 live-schema mapping to `requirements.md`; updated `docs/activity.md`; commit & push.

## Review

**Files added**
- `config/response.php` — the single shared helper for all 32 endpoints: bearer auth
  (against `expires_at`), `body_json`, `json_response`/`json_error`, `db_query`/`db_exec`/`db_one`
  with `sql.log` tracing + `?debug=1` buffer, `path_id`/`query_int`/`query_str`. Log dir
  auto-falls-back to `/var/www/var/log/` when `/var/log/maludb/` isn't writeable.
- `html/.htaccess` — the four §1.3 rewrite rules + an Authorization-passthrough line.
- `html/v1/subjects.php` — `GET` (list, `q`/`limit`, `linked_verbs`) + `POST` (create, 201),
  `405` otherwise.

**Verified** (local `php -S`, dev token): 401 no/bad token · 200 list with correct field
mapping · `q` filter · `?debug=1` SQL trace · 201 create (`id` = MAX+1) · 400 missing-field ·
400 malformed-JSON · 405 PATCH · `sql.log` format per §2.1 · all 8 rewrite examples from §1.3.
Test row removed after verification.

**Notes / follow-ups**
- The dev token is real data in the live `zozocal` DB. Revoke when no longer needed:
  `DELETE FROM api_tokens WHERE device_name = 'claude-dev';`
- `.htaccess` rewriting was validated by replaying the regexes (built-in PHP server ignores
  `.htaccess`); confirm under real Apache before relying on clean URLs in production.
- `linked_verbs` is name-based (`maludb_subject_verb` is currently empty, so all counts are 0).
- `subject_id` has no sequence — the `MAX(subject_id)+1` insert is fine for v1 but not
  concurrency-safe; revisit if subjects are created in parallel.
- Left `html/test_db.php` in place (pre-existing; uses MySQL-only `SHOW TABLES`, unrelated to v1).

## Follow-up (2026-05-26) — relationship/pair exposure decision

- Verified live against `https://fastapi.maludb.org` (vhost → `/var/www/html`; user enabled mod_rewrite + AllowOverride so clean URLs resolve).
- **Decision A:** list keeps counts; detail (`/v1/subjects/{id}`) embeds `verbs[]` + `related_subjects[]`; sub-endpoints stay for compat. List also gains a `related_subjects` count.
- [x] Added `related_subjects` count to `GET /v1/subjects` (count of `maludb_subject_relationship` rows where subject is `from`/`to`). Verified live. Documented in `requirements.md` §4.10.
- [x] **Endpoint 2:** `html/v1/subjects_id.php` — `GET` (subject + embedded `verbs[]` + `related_subjects[]`), `PATCH` (label/type/description/classifier_md → 200/400/422/404), `DELETE` (200/404), `405` otherwise. Verified full lifecycle live on a throwaway row. Curl commands added to `tests/subjects_curls.sh`.
---

# Build plan — remaining 30 endpoints (agreed 2026-05-26)

**Conventions (all phases):**
- Dependency-aware order (below), not strict spec order.
- For **every** endpoint built, also create **one** copy-paste curl test file: `tests/<endpoint>_curls.sh`
  (e.g. `verbs.php` → `tests/verbs_curls.sh`). Plain standalone curl commands, one per case,
  each with an expected-result comment; mutating cases wrapped in a self-cleaning
  create→…→delete flow against `https://fastapi.maludb.org` with the dev token.
- Existing Subjects tests stay as-is (`tests/subjects_curls.sh`, `tests/regression_subjects.sh`);
  the one-file-per-endpoint rule applies to new endpoints only.
- Each endpoint: build → `php -l` → verify live → write its curl file → update `requirements.md`
  mapping if schema differs → log in `docs/activity.md` → commit (+ push when creds available).

**Done:** `subjects.php` ✓, `subjects_id.php` ✓.

## Phase 1 — Verbs (§4.2) ✓ DONE
- [x] `verbs.php` — GET (q/limit, `linked_subjects` count) + POST. + `tests/verbs_curls.sh`
- [x] `verbs_id.php` — GET (+ embedded `subjects[]`) / PATCH / DELETE. + `tests/verbs_id_curls.sh`
- [x] `verbs_id_subjects.php` — GET (read-only linked subjects). + `tests/verbs_id_subjects_curls.sh`
- [x] **Foundation fix (uncovered here):** `maludb_subject`/`maludb_verb` are updatable VIEWS with type-validation triggers. Added a global exception/fatal handler to `config/response.php` (standard JSON error + `api.log` + SQLSTATE→409/422/500 mapping) so bad input returns clean 4xx instead of a blank 500. Verified live (invalid verb_type → 422).

## Phase 2 — Type lists (§4.3) ✓ DONE
- [x] `subject-types.php` — GET (`maludb_subject_type` by `sort_order`; returns `{type,display_name,description,sort_order}`). + `tests/subject-types_curls.sh`
- [x] `verb-types.php` — GET (`maludb_verb_type` by `sort_order`; adds `semantic_class`). + `tests/verb-types_curls.sh`

## Phase 3 — Subjects sub-resources (§4.1) ✓ DONE
- **Decision (2026-05-26):** verb-linking is a vector compartment owned by the DBMS project;
  the API can't create it (multi-table view, no grant, needs embedding config). Related-subjects
  is normal data CRUD on an insertable view. The API does DML only, no DDL.
- [x] `subjects_id_verbs.php` — GET (list linked verbs) + **POST** link via `maludb_subject_verb_link` (404/400/422/409/201). ✅ implemented 2026-05-27 (helper added by DBMS project). + `tests/subjects_id_verbs_curls.sh`
- [x] `subjects_id_verbs_id.php` — **DELETE** unlink via `maludb_subject_verb_unlink` (200/404). ✅ implemented 2026-05-27. + `tests/subjects_id_verbs_id_curls.sh`
- [x] `subjects_id_related-subjects.php` — GET + POST `{related_subject_id, relationship_type?}` (default `related_to`; 400/422/409). + `tests/subjects_id_related-subjects_curls.sh`
- [x] `subjects_id_related-subjects_id.php` — DELETE (either direction; 200/404). + `tests/subjects_id_related-subjects_id_curls.sh`
- [x] Wrote `docs/db-requirements.md` — requests `maludb_subject_verb_link`/`_unlink` (granted) from the DBMS project so the verb-link 501s can be lifted later.
- Verified the full related-subjects lifecycle live (link/dupe/self/missing/bidirectional/custom-type/delete); DB left clean.

## Phase 4 — Projects (§4.6) ✓ DONE
- **Finding:** `maludb_project` is a view of `maludb_subject WHERE subject_type='project'` — a project IS a subject (project id = subject_id). No archive column; links live in the non-insertable SVPOR graph. Projects expose `name` (→ canonical_name).
- [x] `projects.php` — GET (q/limit) + POST (create subject type='project'). + `tests/projects_curls.sh`
- [x] `projects_id.php` — GET (+ embedded `subjects[]`/`verbs[]` read from SVPOR edges), PATCH, DELETE (all scoped to type='project'). + `tests/projects_id_curls.sh`
- [x] `projects_id_subjects.php` — **POST** (link) + **PUT** (replace set, transactional) via svpor create/delete. ✅ fully implemented 2026-05-27. + test file
- [x] `projects_id_subjects_id.php` — **DELETE** via `maludb_svpor_relationship_delete` (200/404). ✅ + test file
- [x] `projects_id_verbs.php` — **POST** + **PUT**. ✅ + test file
- [x] `projects_id_verbs_id.php` — **DELETE**. ✅ + test file
- [x] `projects_id_archive.php` / `projects_id_unarchive.php` — **POST** via `maludb_project_archive`/`_unarchive` (409 already_archived / not_archived); `archived_at` surfaced on list + detail. ✅ + test files
- Phase 4 now **fully implemented** (all 8 endpoints live). Verified full lifecycle; DB left clean.
- [x] `projects_id_archive.php` — POST → **501** (no archive column). + test file
- [x] `projects_id_unarchive.php` — POST → **501**. + test file
- [x] `docs/db-requirements.md` §2 (project↔subject/verb link functions) + §3 (archive column/functions). Verified all 8 live; DB left clean.

## Phase 5 — Pools (§4.7) ✓ DONE
- **Finding:** `maludb_memory_pool` is direct-INSERT; `pool_id` sequence-assigned; `creation_kind` must be `prompt|api|mcp|sql` (API uses `api`); `lifecycle_state` ∈ `active|sealed|archived|tombstoned`; has `archived_at`. name→pool_name, description→task_objective. **DELETE is permission-denied on the pool view** (consistent w/ no v1 DELETE).
- [x] `pools.php` — GET (q/limit, excludes tombstoned) + POST (creation_kind='api'). + `tests/pools_curls.sh`
- [x] `pools_id.php` — GET + PATCH (name/description); no DELETE → 405. + `tests/pools_id_curls.sh`
- [x] `pools_id_archive.php` — POST sets lifecycle_state='archived'+archived_at; 409 already_archived; 404. + `tests/pools_id_archive_curls.sh`
- Verified full lifecycle live (create/detail/patch/archive/409/405/404). Test pool id=8 left **tombstoned** (can't hard-delete — no grant); flagged to user.

## Phase 6 — Skills (§4.8) ✓ DONE
- **Finding:** `maludb_skill` is a direct-INSERT view, **DELETE works** (clean CRUD); `skill_id` sequence-assigned; defaults version `1.0.0`/visibility `private`/enabled true; visibility ∈ {private,shared,public} & packaging_kind ∈ {system_prompt,markdown,mcp_tool,plugin} (DB-enforced → 422). View exposes no skill body/markdown (db-requirements §4). name→skill_name.
- [x] `skills.php` — GET (`visibility`/`q`/`limit`) + POST. + `tests/skills_curls.sh`
- [x] `skills_id.php` — GET / PATCH / DELETE. + `tests/skills_id_curls.sh`
- [x] `skills_id_duplicate.php` — POST via `maludb_skill_fork` (catches non-forkable → 422); 201 on success. + `tests/skills_id_duplicate_curls.sh`
- Verified full lifecycle live (create/GET/visibility-filter/PATCH/422/DELETE/404); DB left clean (DELETE works). Duplicate happy-path needs a forkable/published source skill.

## Phase 7 — Notes (§4.5) ✓ DONE (2026-05-27, after server-side fixes)
- Server added `validate_payload(...)` (memory writable) + `maludb_memory.issue_closed_at`. Notes = `maludb_memory` (id→memory_id, title→title, body→summary, type→memory_kind, project_id→payload).
- [x] `notes.php` — GET (q/type/limit) + POST `{title, body?, type?, project_id?}`. + `tests/notes_curls.sh`
- [x] `notes_id.php` — GET/PATCH/DELETE. + `tests/notes_id_curls.sh`
- [x] `notes_id_close-issue.php` — POST (409 if not issue / already closed). + test file
- [x] `notes_id_reopen-issue.php` — POST (409 if not issue / not closed). + test file
- Verified full note + issue lifecycle live; DB left clean.

## Phase 8 — Documents (§4.4) ✓ DONE
- **Resolved:** no storage decision needed — `maludb_source_package.content_bytes` (bytea) stores bytes in-DB; `maludb_document` holds metadata. Both direct-INSERT, ids sequence-assigned, DELETE works (no orphans).
- [x] `documents.php` — GET (q/limit, joins content_size) + POST (multipart `file`/`filename`/`mime_type`/`description`; bytea via PDO::PARAM_LOB; computes size + sha256; 413/400 paths). + `tests/documents_curls.sh`
- [x] `documents_id.php` — GET (metadata + size/hash; no binary, download deferred §6) / DELETE (document + source_package). + `tests/documents_id_curls.sh`
- Verified full upload→list→detail→delete lifecycle live; DB left clean (0 orphans).

## Phase 9 — Episodes (§4.9) ✓ DONE
- **Body shape defined** (resolves §6 open question): `{title, summary?, kind? (default 'activity'), payload?, occurred_at?, occurred_until?, sensitivity? (default 'internal')}`.
- [x] `episodes.php` — POST only, via `maludb_core.register_episode(...)` under `SET LOCAL search_path TO public, maludb_core` (tenant-owned `owner_schema='public'`). 400/422/405/401. + `tests/episodes_curls.sh`
- Verified live (create default + with kind/occurred_at; bad sensitivity → 422); cleaned up test episodes. Nice-to-have public wrapper noted in db-requirements §6.

---

## Phase 10 — Document type support (maludb_core 0.81.0)

**Schema change recap (from user):**
- `maludb_document` view gains a nullable `document_type text` column (last column; INSERT/UPDATE-able via the view).
- `maludb_upload_document(...)` gains an appended `p_document_type text DEFAULT NULL` (we don't currently call this function — see decision below).
- **New view** `maludb_document_type` — picker lookup with columns `document_type_id` (PK, generated), `document_type text` (case-insensitive unique on `lower(document_type)`), `description text?`, `display_order integer?`, `created_at`. Supports SELECT/INSERT/UPDATE/DELETE. Seeded on schema enable.
- **Advisory only** — no FK from `maludb_document.document_type` to the lookup; uploading an unseeded type string must succeed.

**Decision — keep direct INSERT into `maludb_document`, do NOT switch to `maludb_upload_document(...)`:**
- Our existing upload (`html/v1/documents.php` POST) writes binary bytes to `maludb_source_package.content_bytes` via PDO::PARAM_LOB, then INSERTs metadata into the `maludb_document` view. The new function only accepts text content (`p_content_text` / `p_content_jsonb`) — there is no `p_content_bytes`, so it can't carry our bytea blob.
- Per CLAUDE.md #6 (Simplicity Principle), we add `document_type` as one more column on the existing view INSERT — minimal change, view is already declared INSERT/UPDATEable for this column.
- If a text-content upload path is added later, that one should use `maludb_upload_document(..., p_document_type => ...)`.

**Plan**
- [x] **`html/v1/documents.php`** — GET selects/returns `d.document_type`; POST reads optional multipart `document_type` (blank ⇒ NULL), appended to the existing view INSERT and echoed back.
- [x] **`html/v1/documents_id.php`** — GET selects/returns `d.document_type`. (No PATCH/PUT today → nothing to extend; spec said "if present".)
- [x] **`html/v1/document-types.php`** (new) — `/v1/document-types` (hyphenated). GET (ordered `display_order NULLS LAST, document_type`) + POST (400 missing label / 422 non-int display_order / 409 case-insensitive dupe via global 23505→409 mapping).
- [x] **`html/v1/document-types_id.php`** (new) — `/v1/document-types/{id}`. PATCH (404/400/422/409) + DELETE (200/404; no FK so tagged documents are untouched); 405 otherwise.
- [x] **Tests** — extended `tests/documents_curls.sh` (seeded + unseeded upload, round-trip) and `tests/documents_id_curls.sh` (round-trip); new `tests/document-types_curls.sh` and `tests/document-types_id_curls.sh` (full CRUD incl. case-insensitive 409 on POST and PATCH).
- [x] **`php -l` clean** on all four files; full suite verified live against `https://fastapi.maludb.org`; all created rows self-cleaned.
- [x] **Docs** — Phase 10 entry appended to `docs/activity.md`; this review block filled in.
- [ ] **Commit and push** — bundled with the prior 7 unpushed commits (user asked to push everything together at the end).

## Review (Phase 10)

**Files changed**
- `html/v1/documents.php` — `document_type` on GET list + POST (read from multipart, NULL when blank, advisory free text).
- `html/v1/documents_id.php` — `document_type` on GET detail.

**Files added**
- `html/v1/document-types.php` — GET (picker list) + POST (create), 405 otherwise.
- `html/v1/document-types_id.php` — PATCH + DELETE, 405 otherwise.
- `tests/document-types_curls.sh`, `tests/document-types_id_curls.sh`.

**Verified live** (dev token, real host): 10 seeded types listed in `display_order`; create→PATCH→DELETE lifecycle; case-insensitive duplicate → **409** on both POST (`"report"` vs seeded `Report`) and PATCH (`"EMAIL"`); 400 missing label · 422 non-integer `display_order` · 404 missing · 405 wrong method · 401 no token. Upload with seeded **"Meeting Notes"** and brand-new unseeded **"Totally Made Up Type"** both 201 and round-trip in GET detail. DB left clean (every created row deleted).

**Notes**
- No new server code for the 409: the case-insensitive unique violation (`malu$document_type_owner_lower_idx`, SQLSTATE 23505) is already mapped to 409 by `config/response.php`'s global handler.
- Kept the direct `maludb_document` view INSERT (not `maludb_upload_document(...)`) — that function is text-content only and can't carry our bytea blob. A future text-content upload path should call it with `p_document_type => …`.
- PATCH-only on `document-types/{id}` (no PUT), matching the existing `*_id.php` convention (subjects/skills/projects).

---

## Phase 11 — Episodes/events + SVO statements (maludb_core 0.82.0)

**Verified live (DB introspection, txn rolled back; one leaked probe episode deleted):**
- Facade lives in `public`; all of `maludb_episode` (view), `maludb_register_episode`,
  `maludb_episode_get`, `maludb_episode_type`, `maludb_svpor_statement` (view),
  `maludb_svpor_statement_create/_close/_delete/_set_provenance` need
  `SET LOCAL search_path TO public, maludb_core` (current_schema stays `public` for tenant
  ownership; `maludb_core` in path resolves `malu$*` base tables + RLS grant checks). Same
  pattern as the existing `episodes.php`.
- `maludb_core.resolve_svpor_verb('attended'|'generated_by'|'made_during')` → 6/7/8 (seeded).
- `maludb_svpor_statement_create(...)` is idempotent on
  (subject_kind,subject_id,verb_id,object_kind,object_id) — repeat returns same id.
- Bad endpoint id → SQLSTATE 23503 (→422 via global handler); bad kind → 22023 (→422).
- `maludb_episode_get(id)` → `{episode, statements[], details[]}` with `*_label`s resolved;
  NULL when the id doesn't exist.

**Conventions reused:** bearer auth, `db_query/db_one/db_exec`, `json_response/json_error`,
`path_id`, global SQLSTATE→HTTP mapping (23505→409, 23503/22023/...→422), hyphenated slugs
for picker resources, one file per URL path, self-cleaning curl test per endpoint.

**Endpoints to build**
1. `episodes.php` (rewrite POST onto the new facade + add GET list)
   - GET `?q=&kind=&provenance=&limit=` — list from `maludb_episode`, ORDER BY
     `occurred_at DESC NULLS LAST, episode_id DESC`. Returns the row columns + `provenance`.
   - POST — `maludb_register_episode(p_episode_kind=>, p_title=>, p_summary=>, p_payload_jsonb=>,
     p_occurred_at=>, p_occurred_until=>, p_sensitivity=>, p_provenance=>)` (named args).
     Body `{title (req), kind? (default 'activity'), summary?, payload?, occurred_at?,
     occurred_until?, sensitivity? (default 'internal'), provenance? (default 'provided')}`.
     Read back via `maludb_episode` in the same txn.
2. `episodes_id.php` (new)
   - GET — `maludb_episode_get(id)`; 404 if NULL. Returns `{episode, statements, details}`.
   - PATCH — `UPDATE maludb_episode SET …` for any of
     {title, summary, kind→episode_kind, payload→payload_jsonb, occurred_at, occurred_until,
      sensitivity, provenance, lifecycle_state}; 400 no-fields, 404 missing; returns episode_get.
   - DELETE — `DELETE FROM maludb_episode WHERE episode_id=?`; 200/404.
3. `episode-types.php` + `episode-types_id.php` (new) — picker CRUD on `maludb_episode_type`,
   mirroring `document-types.php`/`document-types_id.php` (case-insensitive dup → 409).
4. `statements.php` (new) — general statement surface
   - GET `?provenance=&object_kind=&object_id=&subject_kind=&subject_id=&limit=` — list from
     `maludb_svpor_statement` (this is the "list suggested items" review query:
     `?provenance=suggested`). Joins not needed (labels available via episode_get); returns raw
     columns.
   - POST — create a statement (see body shape + resolution below).
5. `statements_id.php` (new)
   - GET — single statement row; 404.
   - PATCH — `{provenance?}` → `maludb_svpor_statement_set_provenance` (accept/reject transition);
     `{valid_to?}` (or `{close:true}`) → `maludb_svpor_statement_close`. 400 no-op, 404.
   - DELETE — `maludb_svpor_statement_delete`; 200/404.
6. `episodes_id_statements.php` (new) — episode-scoped convenience
   - GET — statements where `object_kind='episode_object' AND object_id={id}` (the event's
     attendees/documents/decisions); 404 if the episode doesn't exist.
   - POST — same as statements POST but `object_kind`/`object_id` default to this episode.

**Statement POST body (shared by #4 and #6):**
`{ verb (name) | verb_id, subject_kind (default 'subject'), subject_id | subject (name, only
when kind='subject'), object_kind, object_id, predicate? | predicate_id?, valid_from?,
valid_to?, confidence?, provenance? (default 'provided'), source_package_id?, metadata? }`
- `verb`: resolve via `maludb_core.resolve_svpor_verb` (422 if unknown) unless `verb_id` given.
- `subject` (name) with kind='subject': create-or-resolve via `register_svpor_subject` — this is
  what lets you add "Edward Honour attended" by name (see DECISION 1).
- Calls `maludb_svpor_statement_create(...)` with named args; idempotent. Returns the row.
- *_kind ∈ ('subject','verb','document','episode_object','memory','source_package','claim',
  'fact','memory_detail_object'); validated by the DB (bad kind → 422).

**Tests (one curl file per endpoint, dev token, self-cleaning):**
`episodes_curls.sh` (rewrite: GET list now 200 not 405; POST provided + suggested),
`episodes_id_curls.sh`, `episode-types_curls.sh`, `episode-types_id_curls.sh`,
`statements_curls.sh`, `statements_id_curls.sh`, `episodes_id_statements_curls.sh`.
Cover: create event (provided + suggested), link attendee/document/decision, idempotent
re-link returns same id, FK-violation on bad id → 422, `maludb_episode_get` shape,
episode-type CRUD + duplicate 409, suggested→accepted transition (PATCH provenance) on both a
statement and an episode. Self-clean via DELETE (episodes + statements + types are all deletable).

**Open decisions (check in before building):**
- DECISION 1 — name resolution in statement POST (verb-by-name + create-or-resolve
  subject-by-name) vs strictly id-based.
- DECISION 2 — statement routing surface (general `/statements` + `/statements/{id}` AND
  episode-scoped `/episodes/{id}/statements`) vs a single style.
- DECISION 3 — boilerplate: small shared helper in `config/response.php` for the
  `SET LOCAL search_path TO public, maludb_core` + txn, or keep each endpoint self-contained.

### Review (Phase 11)

**Decisions (confirmed by user):** statement input = verb **+ subject by name** (create-or-resolve);
routing = **both** general `/statements` + `/statements/{id}` and episode-scoped
`/episodes/{id}/statements`; boilerplate = **shared helper** in `config/response.php`.

**Files added**
- `config/response.php` — `db_tx_core(callable)` (txn + `SET LOCAL search_path TO public, maludb_core`);
  `svpor_statement_cols()`, `shape_statement()`, `svpor_create_statement()` (verb/subject/predicate
  name resolution, shape-validate before any write, idempotent create).
- `html/v1/episodes.php` — **rewritten**: GET list (`q`/`kind`/`provenance`/`limit`, by occurred_at)
  + POST via `maludb_register_episode(... p_provenance =>)`.
- `html/v1/episodes_id.php` — GET (`maludb_episode_get` envelope), PATCH (UPDATE the view incl.
  provenance/lifecycle_state — the accept/reject transition), DELETE.
- `html/v1/episode-types.php` / `episode-types_id.php` — picker CRUD (case-insensitive dup → 409).
- `html/v1/statements.php` — GET filter (review queue = `?provenance=suggested`) + POST general create.
- `html/v1/statements_id.php` — GET, PATCH (set-provenance and/or close via `valid_to`/`close:true`), DELETE.
- `html/v1/episodes_id_statements.php` — event-scoped GET (links where object = this episode) + POST
  (object defaults to the episode).
- 7 curl test files (one per endpoint).

**Verified live** (dev token, real host; DB left clean — 0 episodes / 0 statements):
- Create episode provided + suggested; list + `?provenance=suggested` review queue.
- `maludb_episode_get` shape `{episode, statements[], details[]}` with `*_label`s resolved.
- Full meeting model linked: attendee (subject by name), document (`generated_by`), decision
  (`memory`/`made_during`) — all three surface in episode_get with labels.
- Idempotent re-link returns the same statement id; FK violation on bad id → 422; unknown verb → 422.
- Episode-type CRUD incl. case-insensitive duplicate → 409 (POST and PATCH).
- suggested → accepted provenance transition on both an episode (PATCH) and a statement (PATCH);
  statement close sets `valid_to`.
- 400/404/405/401 paths across all endpoints.

**Notes**
- Everything episode/statement-related runs inside `db_tx_core()` — the facade views/functions and
  the `maludb_core.*` resolvers reference `malu$*` base tables + RLS grants unqualified, so they only
  resolve with `maludb_core` on the search_path (current_schema stays `public` for tenant ownership).
  The `*_type` picker views resolve on the default path, so those endpoints stay plain (like document-types).
- `payload_jsonb`/`metadata` are decoded as objects (not assoc) so an empty value stays `{}`, not `[]`.
- Linking "by name" upserts a `malu$svpor_subject`; there is no public API to delete those, so the
  test files reuse one fixed name ("Regression Attendee") to bound residue to a single row.
- FK violations (23503) and bad kinds (22023) map to 422; case-insensitive label dup (23505) → 409 —
  all via the existing global handler, no new mapping code.
