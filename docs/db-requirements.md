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

## 2. (none other outstanding)
