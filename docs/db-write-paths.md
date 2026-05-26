# How to write to each facade view

Authoritative map (provided by the DBMS project, 2026-05-26) of how the API writes to the
`maludb_*` facade views, plus live-verified annotations. All writes run in the tenant schema
(search_path) as `maludb_memory_executor`/`maludb_memory_admin`; RLS confines them to that schema.

## A. Direct INSERT — 48 of 56 views

Plain `INSERT INTO <view> (…)` works:
`maludb_subject`, `maludb_verb`, `maludb_subject_type`, `maludb_verb_type`,
**`maludb_subject_relationship`** (the subject↔subject feature — direct INSERT), `maludb_claim`,
`maludb_fact`, `maludb_memory`, `maludb_memory_detail`, `maludb_source_package`,
`maludb_document`, `maludb_document_tag`, `maludb_raw_ingest`,
`maludb_memory_pool`(+`_member`/`_tag`/`_access`),
`maludb_skill`(+`_state`/`_transition`/`_keyword`/`_subject`/`_verb`/`_embedding`/`_access`/`_execution`),
`maludb_workflow_*`, `maludb_mcp_*`, `maludb_llm_*`, `maludb_prompt`(+`_render`),
`maludb_chat_session`, `maludb_chat_message`, `maludb_person`, `maludb_project`,
`maludb_stakeholder`, `maludb_document_svpor_hint`.

## B. Helper required — view is NOT insertable (8 views)

| View | Required helper |
|---|---|
| `maludb_subject_verb` | `maludb_subject_verb_create(p_namespace, p_subject_name, p_verb_name, p_embedding_dim, p_embedding_model, p_distance_metric DEFAULT 'cosine')` → bigint |
| `maludb_svpor_relationship` (object graph, **not** subject↔subject) | `maludb_svpor_relationship_create(p_source_kind, p_source_id, p_target_kind, p_target_id, p_relationship_type, p_label DEFAULT NULL, p_edge_jsonb DEFAULT '{}', p_confidence DEFAULT NULL)` → bigint |
| `maludb_pool_subject` | `maludb_pool_add_named_member(p_pool_name, 'subject', p_member_name, p_confidence)` → bigint |
| `maludb_pool_verb` | `maludb_pool_add_named_member(p_pool_name, 'verb', …)` |
| `maludb_pool_skill` | `maludb_pool_add_named_member(p_pool_name, 'skill', …)` |
| `maludb_pool_document` | `maludb_pool_add_named_member(p_pool_name, 'document', …)` |
| `maludb_pool_subject_verb` | none (compartment membership; not name-addressable) |
| `maludb_pool_presence` | read-only projection (presence subsystem UPSERTs it) |

`maludb_pool_add_named_member` `member_kind` accepts: `project, subject, verb, document, skill, memory`.

## C. Insertable, but a helper is recommended (orchestration)

| Helper | Writes / orchestrates |
|---|---|
| `maludb_upload_document(title, content_text, source_type, content_jsonb, media_type, projects[], subjects[], verbs[], events[], metadata)` | `maludb_document` + `maludb_document_tag` (from the arrays) |
| `maludb_quick_add_note(title, body_text, projects[], subjects[], verbs[], svpor_frames, metadata)` | `upload_document` (note + tags) + `maludb_document_svpor_hint` |
| `maludb_chat_start(title, account_name, projects[], subjects[], verbs[], svpor_frames, metadata)` | `maludb_chat_session` |
| `maludb_chat_append_message(chat_session_id, role, content_text, content_jsonb, metadata)` | `maludb_chat_message` (+ updates session message count) |
| `maludb_chat_finalize(chat_session_id)` | builds the chat's `maludb_source_package` doc projection (+ SVPOR hints), closes the session |
| `maludb_skill_fork(source_owner_schema, source_skill_id, new_skill_name, new_version)` | copies a skill (+ keyword/subject/verb/embedding rows) |

## Live-verified annotations (2026-05-26)

- All Group-B/C helpers exist with the signatures above and `zozocal` **has EXECUTE** on them.
- **No write helper has a delete/unlink counterpart, and DELETE is rejected on the Group-B
  views** ("cannot delete from view"). So helper-created links (`subject_verb`, `svpor`
  edges, pool members) **cannot be removed by the API yet** — see `db-requirements.md`.
- `maludb_svpor_relationship_create`'s `relationship_type` is **FK-constrained** to
  `maludb_core."malu$relationship_type"`. Valid values (16): `applies_to, assigned_to,
  caused_by, contradicts, depends_on, derived_from, has_asset, has_detail, has_member,
  part_of, performed_by, related_to, supersedes, supports, uses, verified_by`.
  (Contrast: `maludb_subject_relationship` takes **free-text** `relationship_type`.)
- `maludb_memory_pool` is a view but direct-INSERT (Group A) and has `archived_at` +
  `lifecycle_state` — so pool archive/lifecycle is doable without a schema change.
- `maludb_subject_verb_create` still needs `namespace` + `embedding_dim` + `embedding_model`
  (no defaults) — the API has no basis to choose these.
