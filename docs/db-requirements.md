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

## 1. Unlink / delete helpers (blocks every link `DELETE`, and `PUT` "replace")

**Status:** `DELETE`/`PUT` on link endpoints return `501 not_implemented`.

The helper views are **not deletable** (`DELETE FROM <view>` → "cannot delete from view") and
there is **no `*_unlink` / `*_delete` helper**. So links created via helpers can't be removed,
and a `PUT` that replaces a set (delete-then-add) is impossible. Requested, all granted to
`zozocal`:

1. `maludb_subject_verb_unlink(p_subject_id bigint, p_verb_id bigint) RETURNS integer`
   — blocks `DELETE /v1/subjects/{id}/verbs/{verbId}`.
2. `maludb_svpor_relationship_delete(p_source_kind, p_source_id, p_target_kind, p_target_id,
   p_relationship_type DEFAULT NULL) RETURNS integer`
   — blocks `DELETE /v1/projects/{id}/{subjects|verbs}/{id}` and `PUT` (replace).
3. `maludb_pool_remove_named_member(p_pool_name, p_member_kind, p_member_name) RETURNS integer`
   — blocks removing pool members (Phase 5).

## 2. Subject↔verb linking needs embedding config (blocks `POST /v1/subjects/{id}/verbs`)

**Status:** `POST` returns `501`.

`maludb_subject_verb_create` requires `p_namespace`, `p_embedding_dim`, `p_embedding_model`
(distance defaults to `cosine`). The client contract is `POST {verb_id}` only, and the API has
no basis to choose embedding parameters. **Requested (pick one):**

- A simple `maludb_subject_verb_link(p_subject_id bigint, p_verb_id bigint) RETURNS bigint`
  that owns the namespace + embedding defaults internally (preferred), **or**
- Documented server-wide defaults for `namespace` / `embedding_dim` / `embedding_model` that
  the API may pass to `maludb_subject_verb_create`.

(Either way, #1's `maludb_subject_verb_unlink` is still needed for the DELETE.)

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

## 4. Skill body / markdown not exposed (limits `/v1/skills` to metadata)

**Status:** not blocking — skill CRUD works for metadata; just a coverage gap.

The `maludb_skill` facade view exposes `skill_name, description, version, visibility,
packaging_kind, applicability_jsonb, precondition_jsonb, enabled, …` but **not the skill's
markdown/body content** (the base table has a NOT NULL `markdown` the view fills with a
default). So the API can't read or write a skill's actual content. If skill-content management
is wanted via the API, expose a `markdown`/`body` column on `maludb_skill` (or add a
`maludb_skill_set_body(skill_id, markdown)` helper).
