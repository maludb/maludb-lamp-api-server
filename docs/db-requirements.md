# DBMS-project requirements (requests from the API project)

The API project does **not** create or alter database objects (no DDL, functions, views,
triggers, or grants). It performs only data DML / helper calls through objects the API user
(`zozocal`) is already permitted to use (see `db-write-paths.md`). When an endpoint needs
something the schema doesn't expose, it's recorded here for the DBMS project to implement.

_Last updated: 2026-05-26 (reconciled against the facade write-path reference)._

---

## Resolved by the write-path reference (create side)

These are **no longer blockers for creation** — the granted helpers exist:
- Subject↔verb create → `maludb_subject_verb_create(...)` (but see #1: needs embedding config).
- Project↔subject/verb create → `maludb_svpor_relationship_create('subject', project_id,
  'subject'|'verb', target_id, <registered relationship_type>, …)`.
- Pool membership create → `maludb_pool_add_named_member(pool_name, kind, name, confidence)`.

What remains outstanding:

## 1. Unlink / delete helpers (blocks remaining link `DELETE`, and `PUT` "replace")

**Status:** ✅ **subject↔verb resolved** — `maludb_subject_verb_link`/`_unlink` were added and
`POST`/`DELETE /v1/subjects/{id}/verbs[/{verbId}]` are now implemented (2026-05-27).
Still outstanding for **projects** and **pools**:

The remaining helper views are **not deletable** (`DELETE FROM <view>` → "cannot delete from
view"). Requested, granted to `zozocal`:

1. ~~`maludb_subject_verb_unlink(...)`~~ ✅ done (and `maludb_subject_verb_link(...)`).
2. **`maludb_svpor_relationship_delete(p_source_kind, p_source_id, p_target_kind, p_target_id,
   p_relationship_type DEFAULT NULL) RETURNS integer`** — **STILL NEEDED.** The create side
   (`maludb_svpor_relationship_create`) exists and `POST /v1/projects/{id}/{subjects|verbs}` is
   now implemented, but **`DELETE /v1/projects/{id}/{subjects|verbs}/{id}` and `PUT` (replace)
   remain `501`** without this. Until it lands, project links created via POST are **permanent**
   (the API can't remove them). Also: `maludb_svpor_relationship_create` is **not idempotent**
   and does **not** FK-validate the target — the API dedupes + checks existence to compensate;
   a server-side unique guard / FK would be more robust.
3. `maludb_pool_remove_named_member(p_pool_name, p_member_kind, p_member_name) RETURNS integer`
   — for removing pool members (not in v1 scope, but noted).

## 2. Subject↔verb linking — ✅ RESOLVED (2026-05-27)

The DBMS project added `public.maludb_subject_verb_link(p_subject_id bigint, p_verb_id bigint)
RETURNS bigint` (idempotent; owns namespace/embedding internally) and
`public.maludb_subject_verb_unlink(p_subject_id bigint, p_verb_id bigint) RETURNS integer`, both
granted to `zozocal`. `POST /v1/subjects/{id}/verbs` and `DELETE /v1/subjects/{id}/verbs/{verbId}`
now use them (no embedding decisions in the API).

## 3. Project archive state (blocks `POST /v1/projects/{id}/archive` & `/unarchive`)

**Status:** `POST` returns `501`.

A project is a subject (`maludb_project` = `maludb_subject WHERE subject_type='project'`), and
`maludb_subject` has **no archive column**. (Note: `maludb_memory_pool` *does* have
`archived_at`/`lifecycle_state`, so pool archive works — this gap is projects-only.) Requested:

- Add `archived_at timestamptz` to the subject/project base (exposed via `maludb_project`),
  **or** provide `maludb_project_archive(p_project_id)` / `maludb_project_unarchive(p_project_id)`
  (granted to `zozocal`). The API will then enforce `409 already_archived` / `not_archived`.

## 5. Notes (§4.5) — server-side work needed before the API can build it

**Status:** all 4 Notes endpoints **not built** (deferred at the user's direction, 2026-05-26).
This section is the complete spec of what the server must provide; once any one coherent path
below is in place, the API will implement the endpoints.

### Endpoint contract the API will implement (once unblocked)
- `GET /v1/notes` — list notes (feed).
- `POST /v1/notes` — `{title, body, type?, project_id?}` → create, return the note **incl. body**.
- `GET /v1/notes/{id}` — return the note incl. body.
- `PATCH /v1/notes/{id}` — update title/body/type/project link.
- `DELETE /v1/notes/{id}` — delete.
- `POST /v1/notes/{id}/close-issue` — set the issue's closed state; `409` if `type != 'issue'`.
- `POST /v1/notes/{id}/reopen-issue` — clear it; `409` if not an issue or not currently closed.

### Blockers verified live (each must be cleared)
1. **A note store that round-trips a body.** Pick one:
   - **(a) Repair `maludb_memory` writes** — INSERT/UPDATE/DELETE currently raise
     `ERROR: function validate_payload(unknown, text, jsonb) does not exist`. Define/grant that
     function so `maludb_memory` is writable (the write-path reference lists it as Group A). Then
     a note = a memory (`memory_kind='note'`, `title`, body in `summary`/`payload_jsonb`), and a
     readable body is already exposed. **(preferred — cleanest fit.)**
   - **(b) Grant `maludb_quick_add_note`** — it currently raises `permission denied for function
     maludb_core._upload_document_for_schema`. `GRANT EXECUTE` on that inner function (or make
     the helper SECURITY DEFINER end-to-end). Note: this path stores the body in a document’s
     source package, so it **also needs #2** to read the body back.
2. **Expose the note/document body for reads.** `maludb_document` exposes metadata only (no
   content column), so a body written via `upload_document`/`quick_add_note` can't be read back.
   If notes are documents, add a `content`/`body_text` column to the `maludb_document` facade
   (this also unblocks Documents body round-trip — see §6).
3. **An issue/closed state.** Nothing records issue state today (no `issue_closed_at`, no `issue`
   type/status). Add, on whatever backs notes, a way to mark `type='issue'` and a nullable
   `issue_closed_at timestamptz` (or an open/closed status), so close-issue sets it and
   reopen-issue clears it with the §4.5 `409` rules.

**Minimal recommended path:** do **1(a) + 3** (repair `maludb_memory`, add an issue/closed field
on it). That gives notes a native home with readable bodies and issue state, and the API builds
all 4 endpoints with no document-facade changes.

## 6. Episodes — nice-to-have (non-blocking)

`/v1/episodes` POST works today via `maludb_core.register_episode(...)`, but that helper is
SECURITY INVOKER and not search-path-safe, so the endpoint must run it under
`SET LOCAL search_path TO public, maludb_core`. A `public` SECURITY DEFINER wrapper
(e.g. `maludb_register_episode(...)` with `SET search_path`, like `maludb_subject_verb_create`)
would let the API drop the search_path manipulation. Not required — current behavior is correct
(episodes are tenant-owned). Also: episode read/list/get endpoints are out of v1; if added later,
a `maludb_episode` facade view (RLS-scoped) would be needed.

## 4. Skill body / markdown not exposed (limits `/v1/skills` to metadata)

**Status:** not blocking — skill CRUD works for metadata; just a coverage gap.

The `maludb_skill` facade view exposes `skill_name, description, version, visibility,
packaging_kind, applicability_jsonb, precondition_jsonb, enabled, …` but **not the skill's
markdown/body content** (the base table has a NOT NULL `markdown` the view fills with a
default). So the API can't read or write a skill's actual content. If skill-content management
is wanted via the API, expose a `markdown`/`body` column on `maludb_skill` (or add a
`maludb_skill_set_body(skill_id, markdown)` helper).
