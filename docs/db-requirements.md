# DBMS-project requirements (requests from the API project)

The API project does **not** create or alter database objects (no DDL, functions, views,
triggers, or grants). When an endpoint needs something the schema doesn't yet expose, it is
recorded here for the DBMS project to implement. The API performs only data DML through
objects the API user (`zozocal`) is already permitted to write.

_Last updated: 2026-05-26._

---

## 1. Subject ↔ verb linking (blocks `POST`/`DELETE` on `/v1/subjects/{id}/verbs`)

**Status:** API endpoints return `501 not_implemented` until this lands.

**Problem.** A subject↔verb pair is a vector *compartment*, not a simple join row:
- `maludb_subject_verb` is a multi-table view → **not insertable/deletable**.
- The underlying `maludb_core."malu$vector_compartment"` is **not granted** to `zozocal`.
- The only creation path, `public.maludb_subject_verb_create(p_namespace, p_subject_name,
  p_verb_name, p_embedding_dim, p_embedding_model, p_distance_metric)`, requires embedding
  configuration (namespace + dim + model) that the client contract (`POST {verb_id}`) does
  not carry and that the API should not invent.
- There is **no delete/unlink function** at all.

**Requested (so the API can keep the `{verb_id}` / `DELETE` contract):**

1. `public.maludb_subject_verb_link(p_subject_id bigint, p_verb_id bigint) RETURNS bigint`
   - Resolves the subject/verb canonical names, applies the project's own default
     `namespace` + `embedding_dim` + `embedding_model` + `distance_metric` internally,
     creates (or returns the existing) compartment, and returns its `compartment_id`.
   - `SECURITY DEFINER`; `GRANT EXECUTE ... TO zozocal`.
2. `public.maludb_subject_verb_unlink(p_subject_id bigint, p_verb_id bigint) RETURNS integer`
   - Removes the compartment for that subject/verb pair; returns rows removed (0 if none).
   - `SECURITY DEFINER`; `GRANT EXECUTE ... TO zozocal`.

Once granted, the API will call these from `subjects_id_verbs.php` (POST) and
`subjects_id_verbs_id.php` (DELETE) and drop the `501`s. No embedding decisions live in the API.

---

## 2. Project ↔ subject / verb linking (blocks `POST`/`PUT`/`DELETE` on `/v1/projects/{id}/subjects` & `/verbs`)

**Status:** API endpoints return `501 not_implemented`. **Reads work** (linked subjects/verbs
are exposed under `GET /v1/projects/{id}`), only writes are blocked.

**Context.** `maludb_project` is a view of `maludb_subject WHERE subject_type='project'`, so a
project *is* a subject. Project→identifier links live in the SVPOR graph
(`maludb_svpor_relationship` → `maludb_core."malu$relationship_edge"`), a **multi-table view
that is not insertable/deletable**, and the underlying edge table isn't granted to `zozocal`.

**Requested:**

1. `public.maludb_project_link_subject(p_project_id bigint, p_subject_id bigint) RETURNS bigint`
   and `public.maludb_project_link_verb(p_project_id bigint, p_verb_id bigint) RETURNS bigint`
   — create the SVPOR edge (project as source), return the edge id. `SECURITY DEFINER`,
   `GRANT EXECUTE ... TO zozocal`.
2. `public.maludb_project_unlink_subject(p_project_id, p_subject_id) RETURNS integer`
   and `public.maludb_project_unlink_verb(p_project_id, p_verb_id) RETURNS integer`
   — remove the edge; return rows removed. Granted to `zozocal`.

(If the project↔subject/verb relationship is meant to use the same machinery as a future
generic SVPOR edge writer, a single pair of `..._link_edge(source_kind, source_id, target_kind,
target_id, relationship_type)` / `..._unlink_edge(...)` functions would cover this and more.)

## 3. Project archive state (blocks `POST /v1/projects/{id}/archive` & `/unarchive`)

**Status:** API endpoints return `501 not_implemented`.

**Problem.** `maludb_subject` / `maludb_project` has **no archive column** (no `archived_at`,
`status`, `state`, or `active`), so archived state can't be recorded.

**Requested (either is fine):**

- Add a nullable `archived_at timestamptz` column to the subject/project base table (exposed
  through `maludb_project`), **or**
- Provide `public.maludb_project_archive(p_project_id bigint)` /
  `public.maludb_project_unarchive(p_project_id bigint)` functions (granted to `zozocal`) that
  own the state. The API will then enforce `409 already_archived` / `not_archived` and surface
  the state on `GET /v1/projects/{id}`.

---

## 4. (none other outstanding)
