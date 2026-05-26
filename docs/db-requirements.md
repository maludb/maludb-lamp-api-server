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
