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
2. ~~`maludb_svpor_relationship_delete(...)`~~ ✅ **done (2026-05-27).** `DELETE` and `PUT`
   (replace) on `/v1/projects/{id}/{subjects|verbs}` are now implemented. NB:
   `maludb_svpor_relationship_create` is still **not idempotent** and does **not** FK-validate
   the target — the API dedupes + checks existence to compensate; a server-side unique guard /
   FK would be more robust (non-blocking).
3. `maludb_pool_remove_named_member(p_pool_name, p_member_kind, p_member_name) RETURNS integer`
   — for removing pool members (not in v1 scope, but noted).

## 2. Subject↔verb linking — ✅ RESOLVED (2026-05-27)

The DBMS project added `public.maludb_subject_verb_link(p_subject_id bigint, p_verb_id bigint)
RETURNS bigint` (idempotent; owns namespace/embedding internally) and
`public.maludb_subject_verb_unlink(p_subject_id bigint, p_verb_id bigint) RETURNS integer`, both
granted to `zozocal`. `POST /v1/subjects/{id}/verbs` and `DELETE /v1/subjects/{id}/verbs/{verbId}`
now use them (no embedding decisions in the API).

## 3. Project archive state — ✅ RESOLVED (2026-05-27)

`maludb_subject.archived_at` (exposed via `maludb_project`) plus
`maludb_project_archive(p_project_id)` / `maludb_project_unarchive(p_project_id)` were added and
granted. `POST /v1/projects/{id}/archive` & `/unarchive` are implemented (409 already_archived /
not_archived), and `archived_at` is surfaced on the project list + detail.

## 5. Notes (§4.5) — ✅ RESOLVED (2026-05-27)

The server applied the recommended path: `validate_payload(...)` was defined (so `maludb_memory`
INSERT/UPDATE/DELETE work) and `maludb_memory.issue_closed_at` was added. All 4 Notes endpoints
are now implemented on `maludb_memory`:
- `id`→`memory_id` (sequence), `title`→`title`, `body`→`summary`, `type`→`memory_kind`
  (default `note`; `issue` enables close/reopen), `project_id`→`payload_jsonb.project_id`.
- `GET/POST /v1/notes`, `GET/PATCH/DELETE /v1/notes/{id}`,
  `POST /v1/notes/{id}/close-issue` (409 if not an issue / already closed),
  `POST /v1/notes/{id}/reopen-issue` (409 if not an issue / not closed).
- `memory_kind` is free-text (no constraint), so `type` is unconstrained.

## 6. Episodes — nice-to-have (non-blocking)

`/v1/episodes` POST works today via `maludb_core.register_episode(...)`, but that helper is
SECURITY INVOKER and not search-path-safe, so the endpoint must run it under
`SET LOCAL search_path TO public, maludb_core`. A `public` SECURITY DEFINER wrapper
(e.g. `maludb_register_episode(...)` with `SET search_path`, like `maludb_subject_verb_create`)
would let the API drop the search_path manipulation. Not required — current behavior is correct
(episodes are tenant-owned). Also: episode read/list/get endpoints are out of v1; if added later,
a `maludb_episode` facade view (RLS-scoped) would be needed.

## 4. Skill body / markdown — ✅ RESOLVED (2026-05-27)

`maludb_skill` now exposes a `markdown` column. `/v1/skills` POST and `/v1/skills/{id}`
GET/PATCH read & write the skill body via `markdown` (the list stays metadata-only).
