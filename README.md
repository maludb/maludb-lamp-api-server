# maludb-lamp-api-server

A deliberately small PHP JSON API server for **[MaluDB](https://maludb.com)** that does double duty:

1. **A production API server** that runs comfortably on low-cost LAMP hosting.
2. **An educational reference** — the code is written so you can read it and see exactly how every call reaches the MaluDB SQL. No framework, no router, no ORM, nothing to step through. If you know the URL, you know the file; if you know the file, you can read every query it runs.

> **The #1 design goal is SQL traceability.** A router obscures *which file ran*; an ORM obscures *which SQL ran*. This project trades the usual DRY conveniences for a direct line of sight: **URL → file → SQL.**

---

## Why this exists

Most API servers bury their database access under layers of abstraction. That's fine for large teams, but it makes the system hard to *learn from* and overkill to *host cheaply*.

This server takes the opposite stance:

- **One file per endpoint.** Every URL path under `/v1/...` maps deterministically to exactly one PHP file. The HTTP methods for that URL are a single `match`/`switch` at the top of the file.
- **Literal SQL in every file.** The query a request runs is right there in the endpoint, as a prepared statement with `?` placeholders. No query builder.
- **One shared helper file.** Auth, JSON decoding, responses, and DB access live in `config/response.php`. That's the only shared application code.
- **Every query is logged.** Each statement is traced to a log file (file, method, URI, user, SQL, params, row count, duration), and `?debug=1` can attach the executed SQL to the response body.

The result is a codebase a developer can read end-to-end in an afternoon, and a server that runs on a $5 VPS.

---

## Architecture at a glance

```
Request  ──►  Apache (.htaccess rewrite)  ──►  one PHP endpoint file  ──►  PostgreSQL
                     │                                  │
                     │                                  └─ require_once config/response.php
                     │                                       (auth, JSON, DB helpers)
                     │
              Bearer token  ──►  MySQL auth store  ──►  per-tenant PostgreSQL credentials
```

- **Linux + Apache + MySQL + PHP (LAMP)** for the server, web, auth store, and endpoint logic.
- **PostgreSQL** is the MaluDB data store. Apache rewrites every `/v1/...` URL onto a file; the file talks to Postgres through a shared PDO singleton.
- **Multi-tenant by token.** Each request carries `Authorization: Bearer malu_…`. The token's `sha256` is looked up in a local MySQL `users` table, which resolves the **PostgreSQL database, user, and password** that request connects with. Host/port are fixed in config; the database name/user/password are resolved per request.

### URL → file mapping

A single `.htaccess` with a handful of rewrite rules maps URL shapes onto file names by structure:

| URL | File |
|---|---|
| `/v1/subjects` | `v1/subjects.php` |
| `/v1/subjects/42` | `v1/subjects_id.php?id=42` |
| `/v1/subjects/42/verbs` | `v1/subjects_id_verbs.php?id=42` |
| `/v1/subjects/42/verbs/7` | `v1/subjects_id_verbs_id.php?id=42&sub_id=7` |
| `/v1/objects/<kind>/9` | `v1/objects_id.php?kind=<kind>&id=9` |
| `/v1/graph/neighbors` | `v1/graph_neighbors.php` |
| `/v1/memory/search` | `v1/memory_search.php` |

There are **59 endpoint files** under `html/v1/`, covering subjects, verbs, objects, attributes, projects, pools, episodes, statements, documents, the graph surface, and an LLM-backed memory pipeline.

---

## Tech stack

| Layer | Choice | Notes |
|---|---|---|
| OS | Ubuntu 24.04 LTS | Runs anywhere LAMP runs |
| Web server | Apache 2.4+ | `mod_rewrite`, `mod_headers`, `mod_deflate` |
| Language | PHP 8.2+ | `pdo`, `pdo_pgsql`, `pdo_mysql`, `mbstring`, `json`, `fileinfo` |
| Data store | PostgreSQL 14+ | The MaluDB data, one connection per request via PDO |
| Auth store | MySQL / MariaDB | Maps API tokens → per-tenant Postgres credentials |
| Dependencies | **none** | No Composer packages in v1 |

Full rationale is in [`tech-stack.md`](tech-stack.md); the endpoint contracts, rewrite rules, error formats, and shared helpers are specified in [`requirements.md`](requirements.md).

---

## Getting started

### 1. Requirements

- Ubuntu 24.04 (or similar), Apache 2.4+, PHP 8.2+, PostgreSQL 14+, MySQL/MariaDB.
- PHP extensions: `pdo`, `pdo_pgsql`, `pdo_mysql`, `mbstring`, `json`, `fileinfo`.
- Apache modules: `mod_rewrite`, `mod_headers`, `mod_deflate`.

### 2. Clone

```bash
git clone https://github.com/maludb/maludb-lamp-api-server.git
cd maludb-lamp-api-server
```

### 3. Configure the data store

Point Apache's DocumentRoot at `html/` and make sure `AllowOverride All` is set so `.htaccess` is honored.

`config/database.php` holds the **fixed** Postgres host/port; the per-request database name/user/password come from the MySQL auth store. Edit these for your deployment. The production `config/database.php` is meant to be edited in place and is environment-local.

### 4. Configure the auth store

Create the local MySQL auth/routing store and seed at least one token:

```bash
mysql your_auth_db < config/local-database.sql
```

The `users` table maps a token's `sha256` hash to a role and the PostgreSQL `(dbname, user, password)` that requests with that token connect as. Tokens are stored only as hashes (of the token body after the `malu_` prefix) and are issued out-of-band.

### 5. Make a request

```bash
curl https://your-host/v1/subjects \
  -H "Authorization: Bearer malu_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

See the [`sample-curl/`](sample-curl/) directory for worked end-to-end examples (setting up subjects, verbs, types, and the extraction pipeline).

---

## Authentication

Every request must send:

```
Authorization: Bearer malu_<token>
```

The server hashes the token body with `sha256`, looks it up in the MySQL `users` table, and resolves the PostgreSQL credentials for that tenant. Missing, invalid, or revoked tokens return `401`. Tokens are never logged in clear text — only a short prefix for diagnostics.

---

## Reading the code (the educational part)

Want to learn how an endpoint works? Pick a URL and follow it:

1. **URL → file.** `/v1/subjects/42/verbs` → `html/v1/subjects_id_verbs.php`. (The transformation is mechanical: path segments become the file name; numeric segments become `id` / `sub_id`.)
2. **Open the file.** It's typically 20–100 lines: require the shared helper, authenticate, branch on HTTP method, run the SQL, return JSON.
3. **Read the SQL.** Every query is literal text with `?` placeholders right in front of you.

For live tracing:

- `tail -f /var/log/maludb/sql.log` — every executed statement, with params, row count, and duration.
- Append `?debug=1` (when `MALUDB_DEBUG=1` is set) to attach the executed SQL + params under `meta.debug` in the response.

`html/v1/subjects.php` is a good first read.

---

## Security model

- **Prepared statements everywhere.** All queries use `?` placeholders — never string concatenation.
- **Per-field validation** lives in the endpoint file (type checks, length limits, enum whitelists).
- **Stateless.** No PHP sessions, no CSRF tokens (it's a bearer-token API, not a browser-form app).
- **Transport.** HTTPS only at the edge.

---

## Project layout

```
.
├── config/
│   ├── database.php          PostgreSQL PDO singleton (fixed host/port; creds resolved per request)
│   ├── local-database.php    MySQL auth-store access
│   ├── local-database.sql    Auth-store schema (users + model_prompts)
│   ├── llm.php               LLM connection helpers for the memory pipeline
│   ├── response.php          The one shared helper: auth, JSON, DB wrappers, SQL logging
│   └── prompts/              Per-model extraction system prompts
├── html/
│   ├── .htaccess             URL → file rewrite rules
│   └── v1/                   59 endpoint files (one per URL path)
├── sample-curl/              Worked end-to-end curl walkthroughs
├── tests/                    Test scripts
├── requirements.md           Endpoint contracts, rewrite rules, error formats
├── tech-stack.md             Technology choices and rationale
└── api-calls.md              Every call the desktop client makes (client-side map)
```

---

## Status

This is the first public release, migrated from a private repository after the initial development phase. The v1 surface is stable; the test strategy is intentionally lightweight (the small surface and SQL-on-the-page design *is* much of the strategy). Contributions and issues welcome.

## License

To be determined.
