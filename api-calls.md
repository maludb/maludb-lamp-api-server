# API calls

Every remote HTTP call this desktop app makes, the client function behind it, the IPC channel it is reached through, and where it is used in the UI.

_Last updated: 2026-05-26._

## How requests work

- **All HTTP happens in the main process.** The renderer never calls the network directly — views call `window.api.<domain>.<method>(...)` (preload bridge) → an `ipcMain.handle('<channel>')` handler → a client function in `src/main/api/`.
- **Base URL** comes from settings (`apiHost`, default `https://api.maludb.com`); the path column below is appended to it.
- **Auth** — every request sends `Authorization: Bearer <authToken>` and `Accept: application/json`. With no token configured, the call throws `ApiNotConfiguredError` and is skipped.
- **Two transports / log prefixes:**
  - Most domains go through `apiFetch` (`src/main/api/client.ts`), which logs `[api] → …`, a full copy-pasteable `curl`, and `[api] ← …` with the response body.
  - Documents use raw `fetch` (multipart uploads), logging `[api:documents] → …` with **no** curl line.
- Identifiers (subjects/verbs/pools/episodes) are **remote-only** — no local SQLite read/write; the `id` carried through the renderer is the server id.

---

## Identifiers — Subjects

Client: `src/main/api/subjects.ts` · IPC: `src/main/ipc/identifiers.ts` · Views: `Identifiers.tsx` (Subjects tab), `IdentifierForms.tsx` (Add / Detail).

| Method | Endpoint | Client fn | IPC channel | Where used |
|---|---|---|---|---|
| GET | `/v1/subjects?q=&limit=` | `listRemoteSubjects` | `subjects:list`, `subjects:search` | Subjects tab list + search; subject pickers in Add pair, related-subject picker, verb's subject picker |
| GET | `/v1/subjects/{id}` | `getRemoteSubject` | `subjects:get` | Subject detail screen; also used by `subjects:linkRelated` to resolve the other subject's label |
| POST | `/v1/subjects` | `createRemoteSubject` | `subjects:create` | Add subject form |
| PATCH | `/v1/subjects/{id}` | `updateRemoteSubject` | `subjects:update` | Subject detail — save label / type / description / classifier_md |
| DELETE | `/v1/subjects/{id}` | `deleteRemoteSubject` | `subjects:delete` | Subject detail — Delete |
| GET | `/v1/subjects/{id}/verbs` | `fetchRemoteSubjectVerbs` | `subjects:get` | Subject detail — "Linked verbs" |
| POST | `/v1/subjects/{id}/verbs` | `addRemoteSubjectVerb` | `subjects:linkVerb` | Add pair; "Link verb" picker on subject detail; "Link a subject" picker on verb detail |
| DELETE | `/v1/subjects/{id}/verbs/{verbId}` | `removeRemoteSubjectVerb` | `subjects:unlinkVerb` | Unlink a verb from a subject (subject & verb detail) |
| GET | `/v1/subjects/{id}/related-subjects` | `fetchRemoteRelatedSubjects` | `subjects:get`, `subjects:listRelated` | Subject detail — "Related subjects" |
| POST | `/v1/subjects/{id}/related-subjects` | `addRemoteRelatedSubject` | `subjects:linkRelated` | Subject detail — related-subject picker |
| DELETE | `/v1/subjects/{id}/related-subjects/{otherId}` | `removeRemoteRelatedSubject` | `subjects:unlinkRelated` | Subject detail — remove a related subject |

> The Subjects list reads each row's linked-verb count from `linked_verbs` in the `GET /v1/subjects` payload, so it no longer fetches `/v1/subjects/{id}` per row. It only falls back to `subjects:get` (which calls `/{id}`, `/{id}/verbs`, `/{id}/related-subjects`) if the list omits that field.

## Identifiers — Verbs

Client: `src/main/api/verbs.ts` · IPC: `src/main/ipc/identifiers.ts` · Views: `Identifiers.tsx` (Verbs tab), `IdentifierForms.tsx`.

| Method | Endpoint | Client fn | IPC channel | Where used |
|---|---|---|---|---|
| GET | `/v1/verbs?q=&limit=` | `listRemoteVerbs` | `verbs:list`, `verbs:search` | Verbs tab list + search; verb pickers in Add pair and "Link verb" |
| GET | `/v1/verbs/{id}` | `getRemoteVerb` | `verbs:get` | Verb detail screen |
| POST | `/v1/verbs` | `createRemoteVerb` | `verbs:create` | Add verb form; "Create new verb" from the link-verb picker |
| PATCH | `/v1/verbs/{id}` | `updateRemoteVerb` | `verbs:update` | Verb detail — save canonical name / type / description / classifier_md |
| DELETE | `/v1/verbs/{id}` | `deleteRemoteVerb` | `verbs:delete` | Verb detail — Delete |
| GET | `/v1/verbs/{id}/subjects` | `fetchRemoteVerbSubjects` | `verbs:get` | Verb detail — "Linked subjects" |

> The Verbs list reads each row's linked-subject count from `linked_subjects` in the `GET /v1/verbs` payload when present, falling back to `verbs:get` otherwise.

## Identifiers — Type lists

Client: `src/main/api/identifier-types.ts` · IPC: `src/main/ipc/identifiers.ts` · View: `IdentifierForms.tsx` (type dropdowns).

| Method | Endpoint | Client fn | IPC channel | Where used |
|---|---|---|---|---|
| GET | `/v1/subject-types` | `fetchSubjectTypes` | `identifierTypes:subjects` | "Type" dropdown on Add subject / subject detail |
| GET | `/v1/verb-types` | `fetchVerbTypes` | `identifierTypes:verbs` | "Type" dropdown on Add verb / verb detail |

> No client-side fallback list: if these return nothing the dropdown is empty (the DB enforces referential integrity, so invented values would be rejected).

---

## Documents (file archive)

Client: `src/main/api/documents.ts` (raw `fetch`, `[api:documents]` logs) · IPC: `src/main/ipc/documents.ts` · Views: `Documents.tsx`, `DocumentForms.tsx`.

| Method | Endpoint | Client fn | IPC channel | Where used |
|---|---|---|---|---|
| GET | `/v1/files` | `listRemoteDocuments` | `documents:list`, `documents:syncFromRemote` | Documents list (load + on-mount reconcile) |
| GET | `/v1/files/{id}` | `getRemoteDocument` | (reconcile / detail) | Single file record |
| POST | `/v1/files` (multipart) | `createRemoteDocument` | `documents:upload` | Upload from the document form (`file` + `filename` + `mime_type` + `description` parts) |
| DELETE | `/v1/files/{id}` | `deleteRemoteDocument` | `documents:delete` | Delete a document |

> ⚠️ **Known mismatch:** the client calls `/v1/files`, but `requirements.md` specs `GET /v1/documents → { documents: [...] }`. The live server currently 404s on `/v1/files` ("Route not found"). `GET /v1/files/{id}/download` is referenced in a code comment but is **not** actually called by the client today.

---

## Notes / Memories

Client: `src/main/api/notes.ts` · IPC: `src/main/ipc/notes.ts` · View: `Notes.tsx`.

| Method | Endpoint | Client fn | IPC channel | Where used |
|---|---|---|---|---|
| GET | `/v1/notes` | `fetchRemoteNotes` | `notes:list` | Notes feed |
| GET | `/v1/notes/{id}` | `fetchRemoteNote` | `notes:get` | Single note |
| POST | `/v1/notes` | `createRemoteNote` | `notes:create` | Composer — capture a memory/note |
| PATCH | `/v1/notes/{id}` | `updateRemoteNote` | `notes:update` | Inline edit of a note |
| DELETE | `/v1/notes/{id}` | `deleteRemoteNote` | `notes:delete` | Delete a note |
| POST | `/v1/notes/{id}/close-issue` | `closeRemoteIssue` | `notes:closeIssue` | Close an issue-type note |
| POST | `/v1/notes/{id}/reopen-issue` | `reopenRemoteIssue` | `notes:reopenIssue` | Reopen an issue-type note |



---

## Projects

Client: `src/main/api/projects.ts` · IPC: `src/main/ipc/projects.ts` · Views: `Projects.tsx`, `ProjectForms.tsx`.

| Method | Endpoint | Client fn | IPC channel | Where used |
|---|---|---|---|---|
| GET | `/v1/projects` | `fetchRemoteProjects` | `projects:list` | Projects list; project pickers across Notes / Documents / Pools |
| GET | `/v1/projects/{id}` | `fetchRemoteProject` | `projects:get` | Project detail |
| POST | `/v1/projects` | `createRemoteProject` | `projects:create` | Create project |
| PATCH | `/v1/projects/{id}` | `updateRemoteProject` | `projects:update` | Edit project name / description |
| POST | `/v1/projects/{id}/archive` | `archiveRemoteProject` | `projects:archive` | Archive project |
| POST | `/v1/projects/{id}/unarchive` | `unarchiveRemoteProject` | `projects:unarchive` | Unarchive project |
| DELETE | `/v1/projects/{id}` | `deleteRemoteProject` | `projects:delete` | Delete project |
| POST | `/v1/projects/{id}/{subjects\|verbs}` | `addRemoteProjectIdentifier` | `projects:linkSubject` / `linkVerb` | Link a subject/verb to a project |
| DELETE | `/v1/projects/{id}/{subjects\|verbs}/{identifierId}` | `removeRemoteProjectIdentifier` | `projects:unlinkSubject` / `unlinkVerb` | Unlink a subject/verb from a project |
| PUT | `/v1/projects/{id}/{subjects\|verbs}` | `setRemoteProjectIdentifiers` | `projects:setSubjects` / `projects:setVerbs` | Replace a project's full subject/verb set |

---

## Memory pools

Client: `src/main/api/pools.ts` · IPC: `src/main/ipc/memory-pools.ts` · Views: `MemoryPools.tsx`, `MemoryPoolForms.tsx`.

| Method | Endpoint | Client fn | IPC channel | Where used |
|---|---|---|---|---|
| GET | `/v1/pools` | `listRemotePools` | `pools:list` | Memory pools list |
| GET | `/v1/pools/{id}` | `getRemotePool` | `pools:get` | Pool detail |
| POST | `/v1/pools` | `createRemotePool` | `pools:create` | Create pool |
| PATCH | `/v1/pools/{id}` | `updateRemotePool` | `pools:update` | Edit pool name / description |
| POST | `/v1/pools/{id}/archive` | `archiveRemotePool` | `pools:archive` | Archive pool |
| POST | `/v1/pools/{id}/join` | `joinRemotePool` | `pools:join` | Join pool — ⚠️ unverified endpoint |
| POST | `/v1/pools/{id}/leave` | `leaveRemotePool` | `pools:leave` | Leave pool — ⚠️ unverified endpoint |
| PUT | `/v1/pools/{id}/tags` | `setRemotePoolTags` | `pools:setTags` | Set pool tags — ⚠️ unverified endpoint |
| DELETE | `/v1/pools/{id}` | `deleteRemotePool` | `pools:delete` | Delete pool — ⚠️ unverified endpoint |

---

## Skills

Client: `src/main/api/skills.ts` · IPC: `src/main/ipc/skills.ts` · Views: `Skills.tsx`, `SkillForms.tsx`.

| Method | Endpoint | Client fn | IPC channel | Where used |
|---|---|---|---|---|
| GET | `/v1/skills?visibility=` | `fetchRemoteSkills` | `skills:list` | Skills list (optional visibility filter) |
| GET | `/v1/skills/{id}` | `fetchRemoteSkill` | `skills:get` | Skill detail |
| POST | `/v1/skills` | `createRemoteSkill` | `skills:create` | Create skill |
| PATCH | `/v1/skills/{id}` | `updateRemoteSkill` | `skills:update` | Edit skill |
| POST | `/v1/skills/{id}/duplicate` | `duplicateRemoteSkill` | `skills:duplicate` | Duplicate skill |
| DELETE | `/v1/skills/{id}` | `deleteRemoteSkill` | `skills:delete` | Delete skill |

---

## Activity (episodes)

Client: `src/main/api/episodes.ts` · IPC: `src/main/ipc/episodes.ts` (exposed to the renderer as the **`activities`** namespace) · View: `Activity.tsx`.

| Method | Endpoint | Client fn | IPC channel | Where used |
|---|---|---|---|---|
| GET | `/v1/episodes?state=open` | `listRemoteEpisodes` | `activities:listOpen` | Activity — open activities |
| GET | `/v1/episodes?state=closed` | `listRemoteEpisodes` | `activities:listClosed` | Activity — closed activities |
| GET | `/v1/episodes/{id}` | `getRemoteEpisode` | `activities:get` | Activity detail |
| POST | `/v1/episodes` | `createRemoteEpisode` | `activities:start` | Start an activity |
| PATCH | `/v1/episodes/{id}` | `updateRemoteEpisode` | `activities:update` | Edit activity title / notes |
| POST | `/v1/episodes/{id}/close` | `closeRemoteEpisode` | `activities:close` | Close an activity |
| POST | `/v1/episodes/{id}/reopen` | `reopenRemoteEpisode` | `activities:reopen` | Reopen a closed activity |
| DELETE | `/v1/episodes/{id}` | `deleteRemoteEpisode` | `activities:delete` | Delete an activity |

> ⚠️ Per `src/main/api/episodes.ts`, only `POST /v1/episodes` (+ `/replay`) is in the published contract; list / get / patch / close / reopen / delete are **unverified** conventional shapes and callers flag failures.

---

## Not remote calls

These `window.api.*` namespaces are local only (SQLite / Electron / local MCP) and make **no** HTTP requests: `settings`, `activity` (the sidebar log — distinct from `activities`/episodes), `uiState`, `connectors`, `plugins`, `filePaths`, `mcp`, `mock`, `pairs` (no global pairs endpoint exists server-side — returns empty), `chats`, `pending`, `pushdata`.
