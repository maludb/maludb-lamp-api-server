# Technology Stack — MaluDB API Server

## Overview

A PHP 8.2+ JSON API server for MaluDB running on Apache 2.4 / Ubuntu 24.04 against PostgreSQL. The stack is deliberately minimal: every endpoint is a single PHP file, every file connects to the database through one shared singleton, and Apache rewrites URLs directly to file names — no routing framework, no application controller, nothing to step through to find the SQL behind a failing request.

The #1 goal is **simplicity and SQL traceability.** If you know the URL, you know the file; if you know the file, you can see every query it runs.

Endpoint contracts, URL→file rewriting rules, error formats, and shared helpers are specified in [`requirements.md`](requirements.md). This document covers the technology choices and patterns that underlie them.

## Core Technologies

### Backend

#### PHP 8.2+
**Purpose:** Endpoint logic, request/response handling.

**Features used:**
- Type declarations
- Null coalescing (`??`)
- Match expressions (for method dispatch)
- Constructor property promotion (where helpful)
- `JSON_THROW_ON_ERROR`

**Recommended `php.ini`:**
```ini
memory_limit          = 128M
max_execution_time    = 30
upload_max_filesize   = 25M
post_max_size         = 26M
display_errors        = 0
log_errors            = 1
error_log             = /var/log/maludb/php.log
```

**Required extensions:**
- `pdo`, `pdo_pgsql`
- `mbstring`
- `json`
- `fileinfo` (multipart uploads on `/v1/documents`)

#### Apache 2.4+
**Purpose:** Web server and URL rewriting.

**Required modules:**
- `mod_rewrite` (URL → file mapping)
- `mod_headers` (auth + content headers)
- `mod_deflate` (response compression)

**Document root:** `/var/www/html`
**API root:** `/var/www/html/v1`

A single `.htaccess` with **four** rewrite rules maps URL shapes (1-, 2-, 3-, and 4-segment paths) onto endpoint file names by structural pattern. The exact rules live in `requirements.md` §1.3. There is no routing framework, dispatcher, or application controller.

#### PostgreSQL 14+
**Purpose:** Sole data store.

Connection lives in `/var/www/config/database.php` as a PDO singleton (`Database::getInstance()->getConnection()`). Every endpoint file pulls the same handle.

**Driver options used:**
- `PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION`
- `PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC`
- `PDO::ATTR_EMULATE_PREPARES     => false`

Real (non-emulated) prepared statements — PDO sends placeholders to PostgreSQL on the wire, and the server caches the plan. Endpoints write `?` placeholders in PHP.

## Architecture Patterns

### One file per endpoint (URL path)
Every URL path in `/v1/...` maps deterministically to exactly one PHP file. HTTP methods (GET/POST/PATCH/PUT/DELETE) for a given URL are switched at the top of the file with a `match ($_SERVER['REQUEST_METHOD'])`. No router, no controller, no dispatcher.

**Debug flow:** URL → file (mechanical transformation) → read the file → every query is in front of you.

Total v1 surface: **32 endpoint files** (see `requirements.md` §4).

### Shared database singleton
The DB connection is one file (`config/database.php`). Endpoints `require_once` it and call `Database::getInstance()->getConnection()`. The class enforces single-instance and exception-mode errors. No connection pooling beyond that.

### Shared response helper (single file)
A small `config/response.php` provides the boilerplate that would otherwise be duplicated 32 times:

- `require_auth(): int` — validate bearer token, return `user_id` or emit 401
- `body_json(): array` — decode `php://input` as JSON or 400
- `json_response($data, int $status = 200): never` — emit JSON + status + exit
- `json_error(string $code, string $message, int $status): never` — standard error body
- `db_query`, `db_exec`, `db_one` — PDO wrappers that **log every query** to `sql.log`
- `path_id()`, `path_sub_id()` — read `$_GET['id']` / `$_GET['sub_id']` as ints

This is the only shared code. No autoloader, no namespace, no class hierarchy. Endpoints `require_once __DIR__ . '/../../config/response.php';` at the top.

### SQL traceability — first-class
- **Every prepared statement** executed through `db_query`/`db_exec`/`db_one` is logged to `/var/log/maludb/sql.log` with file, method, URI, user, raw SQL, bound params, row count, and duration.
- A `?debug=1` query param (gated by the server-side `DEBUG_ENABLED` flag) attaches the executed SQL + params under `meta.debug` in the response body.
- No query builder, no ORM — every endpoint contains the literal SQL it runs.

## Security

### Authentication — Bearer tokens
Every request must carry `Authorization: Bearer malu_<43-char base64url>`. The endpoint helper looks up `sha256(token_body)` in `api_tokens` and resolves to a `user_id`. Missing/invalid/revoked → `401`. Full lookup query and table schema in `requirements.md` §1.4.

### Input validation
- All JSON bodies decoded with `json_decode($raw, true, 512, JSON_THROW_ON_ERROR)`. Failures → `400`.
- Per-field validation lives in the endpoint file itself (type checks, length limits, enum whitelists). No central validator.
- **All queries use prepared statements with placeholders. Never string concatenation.**

### Transport
HTTPS only at the edge (Apache or upstream TLS terminator). Bearer tokens never logged in clear text — only the 6-char prefix after `malu_` for diagnostics.

### What we do NOT use
- ❌ CSRF tokens (stateless bearer-token API, not browser-form-driven)
- ❌ PHP sessions (stateless)
- ❌ `password_hash()` in the API layer (tokens stored as `sha256`, issued out-of-band)
- ❌ HTML rendering, template engine, asset pipeline, CORS (Electron client, not browsers)

## Development

### Version control
Git. Push every successful change (per `CLAUDE.md` #9).

`.gitignore` essentials:
```
/config/database.php       # production credentials are local
/var/log/*
*.log
.DS_Store
.vscode/
```

### Code organization
```
/var/www/                                 ← project root
├── config/
│   ├── database.php                      ← PDO singleton (already exists)
│   └── response.php                      ← shared helpers
├── html/                                 ← Apache DocumentRoot
│   ├── .htaccess                         ← four rewrite rules
│   └── v1/
│       ├── subjects.php
│       ├── subjects_id.php
│       ├── subjects_id_verbs.php
│       ├── …                             ← ~32 endpoint files total
└── docs/
    ├── activity.md                       ← chronological prompt + action log
    └── …
```

### Naming conventions
- **Files:** `lower_snake_case.php`, deterministic from URL path (`/v1/<a>/<id>/<b>` → `<a>_id_<b>.php`). Hyphens in URL segments are preserved in file names.
- **Functions:** `snake_case`.
- **Constants:** `UPPER_SNAKE_CASE`.
- **DB tables/columns:** `snake_case`.

### Debugging tools
- `php -l <file>` — syntax check.
- `tail -f /var/log/maludb/sql.log` — live query trace.
- `?debug=1` — in-response SQL trace (gated by `DEBUG_ENABLED`).
- Apache access/error logs as usual.
- `psql` against the production DB for `EXPLAIN ANALYZE`.

### Testing
- Manual + `curl` against a staging environment.
- A small fixtures script seeds a known `api_tokens` row for development.
- Heavy automated testing is **not** in v1 scope — the small surface and SQL-on-the-page architecture is the testing strategy.

## Performance

### Caching strategy
- **Apache:** `mod_deflate` for gzip on JSON responses.
- **PHP:** OpCache enabled in production (`opcache.enable=1`).
- **DB:** PDO singleton means one connection per request; `EMULATE_PREPARES=false` lets PostgreSQL cache plans across calls (within a connection).

### Database optimization
- Proper indexing (especially on `api_tokens.token_hash`, every `*_id` foreign key, search columns hit by `?q=`).
- List endpoints use a single denormalized query — see `requirements.md` §4.10 — so the client never has to N+1.
- Prepared statements via PDO with placeholders.

### What we DON'T optimize for in v1
- No Redis / memcached.
- No CDN (API responses are not cacheable cross-user).
- No HTTP/2 push, no streaming.

## Deployment

### Production requirements
- Ubuntu 24.04 LTS
- PHP 8.2+ with required extensions (above)
- Apache 2.4+ with required modules (above)
- PostgreSQL 14+ (reachable from the app server)
- TLS certificate (Let's Encrypt or org cert)
- Writeable `/var/log/maludb/` directory owned by `www-data`

### Filesystem layout (production)
```
/var/www/
├── config/                  ← not web-accessible
├── html/                    ← Apache DocumentRoot
└── …
/var/log/maludb/             ← SQL + app logs, www-data writeable
```

### Environment configuration
DB credentials are baked into `config/database.php` (singleton constants). For production, that file is edited in place outside the repo and gitignored. A `config/env.php` may be added for per-environment flags (`DEBUG_ENABLED`, log paths); deferred until needed.

## Version Information

### Minimum versions
- PHP: 8.2
- PostgreSQL: 14
- Apache: 2.4
- Ubuntu: 24.04

### Recommended versions
- PHP: 8.3
- PostgreSQL: 16
- Apache: 2.4 (latest stable)

## Technology Decisions

### Why PHP + Apache + PostgreSQL for an API server?
- Proven, boring, and runs anywhere on Ubuntu.
- PHP's request lifecycle is one process per request — perfectly matches "one file per endpoint."
- PDO + PostgreSQL gives us strict types, prepared statements, and `EXPLAIN ANALYZE` from any `psql` shell.
- Zero build step. Zero runtime dependency on a process manager (no FPM-vs-mod_php holy war required; either works).

### Why no router, framework, or ORM?
- The #1 project goal is SQL traceability. A router obscures *which file ran*; an ORM obscures *which SQL ran*. We deliberately give up DRY in the request-handling layer to gain a direct line of sight from URL → file → SQL.
- The cost is some repetition at the top of each endpoint (require config, auth, decode body). The benefit is that a developer landing on a broken endpoint reads ~20–60 lines and sees the whole story.

### Why one file per URL *path* (methods inside), not per URL+method?
- "All queries for `/v1/subjects/{id}`" lives in one place — exactly the unit of debugging.
- Method branching is one `match` at the top of the file, 4–10 lines.
- Splitting per-method (one file per URL+method) was considered and rejected: doubles or triples the file count and a fix "in GET, regresses in PATCH" stops being a one-file diff.

### Why no modern framework?
- Project requirement: simplicity and traceability above all.
- No Laravel/Symfony/Slim — they would add a routing layer and call stacks we explicitly want to avoid.
- No Composer dependencies in v1. If a single dependency is later justified (e.g., a JWT library), it gets added explicitly and listed here.

## References

- PHP: https://www.php.net/docs.php
- PDO: https://www.php.net/manual/en/book.pdo.php
- Apache: https://httpd.apache.org/docs/
- `mod_rewrite`: https://httpd.apache.org/docs/2.4/rewrite/
- PostgreSQL: https://www.postgresql.org/docs/
