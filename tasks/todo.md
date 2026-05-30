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

---

# Phase 12 — Typed attributes + attribute templates (maludb_core 0.83.0)

Episodes/events + SVO statements (note §1–2) shipped in Phase 11. This phase builds the
**new 0.83.0** surface: typed attributes on nodes *and* edges (§3), the attribute-template
form catalog + completeness check (§4), and the attribute side of the review workflow (§5).

All new write/read paths go through the per-schema `maludb_*` facade (RLS-scoped to
`current_schema()`); never touch `maludb_core.malu$*` directly. The attribute facade
functions reference `malu$*` base tables unqualified, so attribute + check endpoints run
inside the existing `db_tx_core()` (txn + `SET LOCAL search_path TO public, maludb_core`),
exactly like episodes/statements. Template picker DML mirrors `episode-types`/`document-types`
(direct writable-view DML on the default path).

## Proposed endpoint surface (5 new files → 37 total)

| URL | File | Methods | Notes |
|---|---|---|---|
| `/v1/attributes` | `attributes.php` | GET, POST | GET filter `?target_kind=&target_id=&attr_name=&provenance=&limit=`. POST = create/**upsert** via `maludb_svpor_attribute_create(...)` (idempotent on target+attr_name). `target_kind` is any node kind **or** `svpor_statement` (edge attrs). |
| `/v1/attributes/{id}` | `attributes_id.php` | GET, PATCH, DELETE | GET row; PATCH `{provenance?}` → `..._set_provenance` (accept/reject); DELETE → `..._delete`. |
| `/v1/attribute-templates` | `attribute-templates.php` | GET, POST | GET catalog, filter `?applies_to=&type_value=` (drives forms). POST = create. |
| `/v1/attribute-templates/{id}` | `attribute-templates_id.php` | GET, DELETE | Read / remove one template row. (No PATCH — note exposes only create/_delete.) |
| `/v1/attribute-check` | `attribute-check.php` | GET | `?target_kind=&target_id=` → `maludb_attribute_check(...)` jsonb `{…, missing_required[], fields[]}`. Advisory completeness check for form submit. |

## Plan

- [x] **Shared helpers in `config/response.php`** — `svpor_attribute_cols()`, `shape_attribute()`
  (int/float casts, value_jsonb+metadata as objects, tstzrange left as text), `svpor_create_attribute()`
  (parse + shape-validate before any write, then upsert via `maludb_svpor_attribute_create(...)` — all
  17 named args).
- [x] `html/v1/attributes.php` — GET (filtered list, `?provenance=suggested` review queue) + POST upsert.
- [x] `html/v1/attributes_id.php` — GET / PATCH(set-provenance, only field patchable) / DELETE.
- [x] `html/v1/attribute-templates.php` — GET (catalog + `applies_to`/`type_value` filter) + POST create
  (via `maludb_attribute_template_create`, run in `db_tx_core()`).
- [x] `html/v1/attribute-templates_id.php` — GET + DELETE (no PATCH → 405).
- [x] `html/v1/attribute-check.php` — GET `?target_kind=&target_id=` → `maludb_attribute_check`.
- [x] **Tests** — 5 self-cleaning curl files (node + edge attr, idempotent upsert, FK→422, provenance
  transition, template CRUD, check before/after).
- [x] **Docs** — requirements.md §4.11 + 3 §4.0 mapping rows + endpoint count; `docs/activity.md`; this review.
- [x] **Verify live** — full suite green against `https://fastapi.maludb.org`; DB left clean.

## Open decisions — RESOLVED (confirmed by user 2026-05-29)

- D1 — **Attribute URL surface**: ✔ general `/v1/attributes` (+`/{id}`).
- D2 — **Template mutability**: ✔ create + delete only (no PATCH → 405).
- D3 — **`attribute-check` shape**: ✔ top-level `GET /v1/attribute-check`.

### Review (Phase 12)

**Files added:** `html/v1/attributes.php`, `attributes_id.php`, `attribute-templates.php`,
`attribute-templates_id.php`, `attribute-check.php` + 5 `tests/*_curls.sh`. **Changed:**
`config/response.php` (attribute helpers), `requirements.md` (§4.11), `docs/activity.md`.

**Verified live** (dev token, real host; DB left clean): node attribute create + idempotent upsert
(same id, value updated 12→15), GET filter, `?provenance=suggested` review queue, suggested→accepted
PATCH, missing attr_name→400, bad value_numeric→422, bad target→422, **edge attribute**
(target_kind=svpor_statement), attribute-check before/after (seeded `duration_minutes` template on
Meeting), template create/get/delete, bad value_type→422, PATCH-on-template→405, GET/DELETE missing→404.

**Notes:** attribute PATCH only mutates `provenance` (the review transition); re-POST to change a value
(upsert on target+attr_name). Template POST uses `maludb_attribute_template_create` (not direct view DML)
for the canonical create path; everything runs in `db_tx_core()`. FK/enum violations → 422 via the
existing global handler — no new mapping code.

---

# 0.86.1 — remaining surface (Phases 12–16). Live-verified 2026-05-29.

**Version reality check (DB introspection, read-only):** the live `zozocal` DB exposes the *full*
maludb_core 0.86.1 surface today (we're catching the API up from 0.82.0). Confirmed present in
`public`: views `maludb_svpor_attribute`, `maludb_attribute_template`, `maludb_object_embedding`,
`maludb_edge`, `maludb_subject_with_attributes`, `maludb_episode_with_attributes`,
`maludb_document_with_attributes`; functions `maludb_svpor_attribute_create/_set_provenance/_delete`,
`maludb_attribute_template_create/_delete`, `maludb_attribute_check`, `maludb_object_get`,
`maludb_attributes_apply`, `maludb_register_object_embedding`, `maludb_semantic_search`,
`maludb_graph_neighbors`, `maludb_graph_walk`, `maludb_reference_view_sql`,
`maludb_create_reference_view`, `maludb_document_get`, `maludb_upload_document`. **Verified
signatures** captured below per phase (named args, several with trailing defaults).

**Conventions reused (all phases):** bearer auth; `db_query/db_one/db_exec`; `json_response/json_error`;
`path_id`; global SQLSTATE→HTTP map (23505→409, 23503/22023/22P02/P0001→422); hyphenated slugs for
picker/aggregate resources; one PHP file per URL path; one self-cleaning curl test per endpoint;
`php -l` + live verify against `https://fastapi.maludb.org`, leaving the DB clean. Facade
functions/views reference `malu$*` unqualified → run inside the existing `db_tx_core()`
(txn + `SET LOCAL search_path TO public, maludb_core`); plain writable-view DML on `*_type`-style
pickers stays on the default path.

**Endpoint count:** 41 today → ~52 after Phases 12–16.

Build order is dependency-aware: **12 → 13 → 15 → 14 → 16** (object-with-attributes builds on
attributes; references build on attributes; graph/semantic are independent; document text-upload last).

## Phase 12 — Typed attributes + templates (§4)  *(already drafted above; signatures now confirmed)*
- `maludb_svpor_attribute_create(p_target_kind, p_target_id, p_attr_name, p_value_timestamp?,
  p_value_range?, p_value_numeric?, p_value_text?, p_value_jsonb?, p_unit?, p_provenance='provided',
  p_confidence?, p_valid_from?, p_valid_to?, p_metadata_jsonb='{}', p_ref_source?, p_ref_entity?,
  p_ref_key?) → bigint` (upsert on (target, attr_name)).
- `maludb_svpor_attribute_set_provenance(p_attribute_id, p_provenance) → boolean`;
  `maludb_svpor_attribute_delete(p_attribute_id) → integer`.
- `maludb_attribute_template_create(p_applies_to, p_type_value, p_attr_name, p_value_type,
  p_requirement='optional', p_label?, p_description?, p_unit?, p_allowed_values?, p_default_value?,
  p_display_order?) → bigint`; `maludb_attribute_template_delete(p_template_id) → integer`.
- `maludb_attribute_check(p_target_kind, p_target_id) → jsonb`.
- Files: `attributes.php`, `attributes_id.php`, `attribute-templates.php`,
  `attribute-templates_id.php`, `attribute-check.php` + 5 curl files. (See plan + D1–D3 above.)
- Note: the writable views (`maludb_svpor_attribute`, `maludb_attribute_template`) are INSERT/UPDATE-able,
  but we prefer the `_create` functions for the dedup/upsert semantics; template pickers may use direct
  view DML like `document-types` where simpler.

## Phase 13 — Object-with-attributes ergonomics (§5)
- **Read (detail):** `GET /v1/objects/{kind}/{id}` → `maludb_object_get(p_target_kind, p_target_id) → jsonb`
  (`{kind, id, object, attributes, [statements, details]}`). The canonical `(object_kind, object_id)`
  handle resource — feeds traversal/attribute calls.
- **Read (list):** `GET /v1/{subjects|episodes|documents}?with=attributes` (or a `with_attributes=1`
  flag on the existing list endpoints) → base columns + an `attributes` jsonb column from the
  `maludb_*_with_attributes` views. **D4 below.**
- **Write (atomic):** `POST /v1/objects/{kind}` → in one `db_tx_core()`: `maludb_register_*`/insert the
  object, then `maludb_attributes_apply(p_target_kind, p_target_id, p_attributes jsonb) → integer`
  (array of `{attr_name, value_*…, unit?, provenance?, confidence?, ref_source?, ref_entity?, ref_key?}`).
  Atomic object + attributes. **D5 below** (which object kinds the single-POST supports — start with
  episode + subject, the two with `register_*` helpers we already use).
- Files: `objects_id.php` *(routes `/v1/objects/{kind}/{id}` — note: kind is a string, not numeric, so
  this needs a routing tweak — **see D6**)*, plus `objects.php` for POST; possibly extend existing list
  endpoints for the `with=attributes` read. + curl files.
- **Routing caveat (D6):** the `.htaccess` rules assume `{id}` is numeric (`[0-9]+`). The
  `(object_kind, object_id)` handle has a *text* kind segment. Options: (a) add one rewrite rule for
  `/v1/objects/<kind>/<id>` → `object_get.php?kind=&id=`; (b) use query params `/v1/object?kind=&id=`.
  Pick before building (recommend (a): one new rule, clean URLs, keeps the handle canonical).

## Phase 15 — Graph traversal + embeddings + semantic search (§7)  *(built before 14; independent)*
- `GET /v1/graph/neighbors?kind=&id=&direction=both&rel=` → `maludb_graph_neighbors(p_kind, p_id,
  p_direction='both', p_rel_filter text[]) → TABLE(neighbor_kind, neighbor_id, rel, edge_store,
  confidence, provenance, label)`.
- `GET /v1/graph/walk?kind=&id=&max_depth=4&direction=both&rel=` → `maludb_graph_walk(p_kind, p_id,
  p_max_depth=4, p_direction='both', p_rel_filter text[]) → TABLE(object_kind, object_id, depth, rel,
  edge_store, label, path text[])`.
- `GET /v1/edges?source_kind=&source_id=&target_kind=&target_id=&rel=&limit=` → read `maludb_edge`.
- `POST /v1/embeddings` (upsert) → `maludb_register_object_embedding(p_object_kind, p_object_id,
  p_embedding bytea, p_embedding_dim, p_embedding_space, p_embedding_model?, p_source_field='default',
  p_sub_key='', p_provenance='provided') → bigint`. **D7: embeddings are bytea but transport is JSON →
  accept base64 in the body (recommended), decode to bytea via `decode(?, 'base64')`.** Optionally also
  GET/DELETE on `maludb_object_embedding`.
- `POST /v1/semantic-search` → `maludb_semantic_search(p_query_embedding bytea, p_object_kinds text[],
  p_k=10, p_embedding_space?, p_metric='cosine') → TABLE(object_kind, object_id, source_field, sub_key,
  score, label)`. Body `{embedding(base64), object_kinds?, k?, embedding_space?, metric?}`.
- **Convenience chain:** `POST /v1/semantic-search?then=walk` (or `/v1/search-related`) → semantic_search
  top hit(s) → feed `(object_kind, object_id)` into `maludb_graph_walk`. **D8: build the chain endpoint
  now or defer?** (recommend a thin `search-related` that does search→walk in one tx.)
- Routing: `/v1/graph/neighbors` & `/v1/graph/walk` are 2-segment non-numeric → need a rewrite rule
  (same tweak family as D6). `/v1/edges`, `/v1/embeddings`, `/v1/semantic-search` are plain 1-segment.
- Files: `graph_neighbors.php`/`graph_walk.php` (or one `graph.php`), `edges.php`, `embeddings.php`,
  `semantic-search.php`, optional `search-related.php` + curl files.

## Phase 14 — External references (§6)
- **Set/resolve ref on an object:** an external reference is just an attribute carrying
  `ref_source/ref_entity/ref_key` (value_type='reference'); use `value_type='suggested'+confidence` for
  proposed matches. So *setting* a reference = Phase-12 attribute POST with those fields — likely **no new
  write endpoint**, just document the pattern + a focused `GET /v1/references?ref_source=&ref_entity=&
  ref_key=` reverse-lookup (filter `maludb_svpor_attribute`) that returns the `(target_kind, target_id)`
  handles pointing at an external record. **D9: dedicated `/v1/references` reverse-lookup endpoint vs.
  reuse `GET /v1/attributes?ref_source=…`** (recommend a small dedicated reverse-lookup for clarity).
- **Reference-view scaffolder (admin, DDL):** `maludb_reference_view_sql(...) → text` (returns CREATE
  VIEW DDL, no execution) and `maludb_create_reference_view(..., p_replace=false) → text` (executes it,
  SECURITY INVOKER in the caller's schema). **D10 — policy call:** the API's standing rule is *DML only,
  no DDL* (db-requirements.md). `maludb_create_reference_view` runs DDL. Options: (a) expose only the
  *preview* `reference_view_sql` (returns DDL text, safe, no execution) and leave actual view creation to
  the DBMS project; (b) expose a guarded admin `POST /v1/admin/reference-views` that calls
  `maludb_create_reference_view`. **Recommend (a)** to honor the no-DDL rule unless you want the admin path.
- Files: `references.php` (reverse-lookup GET), `reference-view-sql.php` (preview POST/GET), and—only if
  D10=(b)—`admin_reference-views.php`. + curl files.

## Phase 16 — Document text-upload path + document_get + with-attributes (§1 leftovers)
- The current `documents.php` POST uploads *binary* via `maludb_source_package.content_bytes` (kept).
  0.86.1 adds a *text-content* path: `maludb_upload_document(p_title, p_content_text, p_source_type=
  'document', p_content_jsonb?, p_media_type?, p_projects text[], p_subjects text[], p_verbs text[],
  p_events text[], p_metadata_jsonb='{}', p_document_type?) → document_id` (auto-tags
  projects/subjects/verbs/events by name). **D11: add a JSON `POST /v1/documents` branch (when
  `Content-Type: application/json` with `content_text`) alongside the existing multipart binary branch.**
- `GET /v1/documents/{id}` → enrich via `maludb_document_get(p_document_id) → jsonb` (document + tags +
  svpor hints), replacing/augmenting the current hand-built detail.
- `documents` list `with=attributes` via `maludb_document_with_attributes` (folds into Phase 13's list flag).
- Download/reingest (`source_package`/`reingest_source_package`) — **defer/confirm**: not yet introspected;
  revisit after D11.

## Consolidated open decisions (check in before building)
- **D1–D3** (Phase 12): attribute URL surface / template mutability / attribute-check shape — *see above*.
- **D4** — with-attributes list: a `?with=attributes` flag on existing list endpoints (recommended) vs.
  new `/v1/.../with-attributes` routes.
- **D5** — single-POST object kinds: start with episode + subject (have `register_*`) vs. all kinds.
- **D6** — non-numeric handle routing for `/v1/objects/{kind}/{id}` (and `/v1/graph/*`): add a rewrite
  rule (recommended) vs. query-param style `/v1/object?kind=&id=`.
- **D7** — embedding transport: base64 in JSON body → `decode(?, 'base64')` to bytea (recommended).
- **D8** — build the `search-related` (semantic→walk) chain now vs. defer.
- **D9** — references reverse-lookup: dedicated `/v1/references` vs. reuse `GET /v1/attributes?ref_*`.
- **D10** — reference-view scaffolder: preview-DDL only, honoring the no-DDL rule (recommended) vs. a
  guarded admin endpoint that executes `maludb_create_reference_view`.
- **D11** — documents JSON text-upload branch via `maludb_upload_document` (recommended) + `document_get`
  detail; download/reingest deferred pending introspection.

## Suggested first slice
Phase 12 (attributes + templates + check) is fully spec'd, signatures confirmed, and unblocks 13/14.
Recommend building it first as one PR, then 13, 15, 14, 16 as follow-on slices — each verified live and
committed on its own, matching the established per-phase rhythm.
