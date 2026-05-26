# Activity Log

Chronological record of every prompt given on this project and every action taken in response, per `CLAUDE.md` #8. New entries appended each turn.

---

## 2026-05-26 — Bootstrap & spec docs

### Prompt 1 (initial)
> We are starting a new project in this folder. It is a PHP based API server for MaluDB. The #1 goal is simplicity and traceable code do debug the SQL that is being called. Each endpoint will be a separate PHP file with one shared file for the connection to the database. That file already exists in the config folder called database.php. The code will run on apache 2, on ubuntu 24.04. The directory path will be /var/www for the project and /var/www/html for the web host of and /var/www/html/v1 for the v1 endpoints. Each endpoint will stand alone and not go through any routing. .htaccess will change an endpoint like v1/subject.php to v1/subject.

**Actions:**
- Read `config/database.php` (PDO singleton, PostgreSQL, host `192.168.100.163`, db `zozocal`).
- Read `tech-stack.md` (existing content is an HTMX/Bootstrap frontend template — mismatched fit for an API server).
- Began to write `tasks/todo.md` per `CLAUDE.md` #1–#3. User interrupted before any file was created.

### Prompt 2 (interrupt + redirect)
> We are not ready to implement or even write a plan. Your job right now is to help update the tech-stack.md and the requirements.md, which does not exist.

**Actions:**
- Asked three clarifying questions:
  1. How to treat existing `tech-stack.md` → **strip frontend, keep server pieces**.
  2. What `requirements.md` should cover → **functional endpoints + non-functional (SQL tracing, logging, errors) + operational (paths, auth, deployment)**.
  3. How to drive the content → **I draft, user corrects**.

### Prompt 3 (sample call shape)
> This is the shape of an expected call: `curl -X GET 'https://api.maludb.com/v1/subjects/17' -H 'Authorization: Bearer malu_pQYTIRdzGeGaoX4u-1uw3u4Ozbq61gPu5aKPbi3_Nmw' -H 'Accept: application/json'`

**Actions:**
- Extracted: dedicated `api.maludb.com` host, path-based versioning (`/v1/...`), plural resource names (`subjects`), path-param IDs (`/17`), bearer-token auth with `malu_` prefix + 43 base64url chars, JSON-only content.
- Listed open design points to be pinned before drafting: path-param convention, methods-per-file, token storage, error body, SQL tracing approach.

### Prompt 4 (point to api-calls.md)
> Also look at api-calls.md, that is a markdown file created by a client application.

**Actions:**
- Read `api-calls.md` — full client-side API call inventory.
- Catalogued the v1 surface: subjects, verbs, subject-types, verb-types, documents (vs. `files` mismatch flagged in source), notes, projects, pools, skills, episodes.
- Surfaced four open questions: (1) `/v1/files` vs `/v1/documents`; (2) inclusion of "⚠️ unverified" endpoints; (3) `/v1/episodes/{id}/replay`; (4) list denormalization (`linked_verbs`, `linked_subjects`); (5) IDs-are-remote-only convention. Proposed Option A (path passthrough + dispatcher) vs Option B (one file per URL path).

### Prompt 5 (decisions)
> Option B, is what I need. When we are debugging the flow I need only location to go to where I can see all the queries related to that endpoint and be able to debug issues. 1) it is /v1/documents. 2) Leave them out. 3) defer until later. 4) yes they need to be denormalized otherwise we make too many calls. 5) defer,

**Decisions locked:**
- **Option B** — one file per URL path; methods branched inside the file.
- Documents surface: `/v1/documents` (not `/v1/files`).
- Unverified endpoints (pools join/leave/tags/delete, episodes list/get/patch/close/reopen/delete): **out of v1**.
- `/v1/episodes/{id}/replay`: **deferred**.
- List denormalization: **yes** — `linked_verbs` on subjects list, `linked_subjects` on verbs list.
- "IDs are remote-only" rule: **deferred**.

**Actions:**
- Proposed URL→file naming rule: `/` → `_`, numeric ID positions → `_id`, hyphens preserved, file names lowercase. Provided sample mapping table.
- Computed v1 surface as **32 endpoint files** (subjects 6, verbs 3, type lists 2, documents 2, notes 4, projects 8, pools 3, skills 3, episodes 1).

### Prompt 6 (naming approved, continue)
> The naming rule is fine. Please continue

**Actions:**
- Created `docs/activity.md` (this file).
- Rewrote [`tech-stack.md`](../tech-stack.md): strip-and-keep — removed all frontend content (HTMX, Bootstrap, Alpine, custom CSS, CSRF on forms, sessions, password hashing, MVC partials, browser support, asset caching); kept and updated PHP, Apache, PostgreSQL, PDO, version control, security (re-cast for bearer-token API), code organization, deployment, version info; added new sections on one-file-per-endpoint architecture, shared singleton + response helper, SQL traceability as a first-class concern, and technology-decision rationale (why no router/ORM/framework, why one file per URL path).
- Created [`requirements.md`](../requirements.md): goals; §1 Operational (server layout, hosting, four-rule `.htaccess` rewrites, bearer-token auth with `api_tokens` schema, JSON conventions); §2 Non-functional (SQL log format, `?debug=1` meta block, error response shape + status code table, log files + rotation, token redaction); §3 Shared helpers (single `config/response.php` with ~11 functions); §4 Functional — all 32 endpoints across 9 resource sections, with method lists and per-endpoint notes; §5 Out of scope; §6 Open questions.
- Will commit and push per `CLAUDE.md` #9.

---
