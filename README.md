# maludb-lamp-api-server

A deliberately small PHP JSON API server for **[MaluDB](https://maludb.com)** that does double duty:

1. **A production API server** that runs comfortably on low-cost LAMP hosting.
2. **An educational reference** — the code is written so you can read it and see exactly how every call reaches the MaluDB SQL. No framework, no router, no ORM, nothing to step through. If you know the URL, you know the file; if you know the file, you can read every query it runs.

> **The #1 design goal is SQL traceability.** A router obscures *which file ran*; an ORM obscures *which SQL ran*. This project trades the usual DRY conveniences for a direct line of sight: **URL → file → SQL.**

> **Supported MaluDB version: `maludb_core` 0.97.0.** The extraction prompt is rendered from the subject-type catalog (`maludb_subject_type`, with its 0.96.0 `category` column), and agent-skill distribution (`POST /v1/skills/ingest`, `GET /v1/skills/{id}/bundle`, tag-aware `GET /v1/skills?subject=&verb=`) uses the 0.97.0 `maludb_skill_register`/`maludb_skill_search` facades. After upgrading the extension, each tenant schema must re-run `SELECT maludb_core.enable_memory_schema('<tenant>');` once so the facades are current.

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

## Installation

A full step-by-step Ubuntu 24.04 setup (Apache, PHP, drivers, Composer, Node.js, Claude Code) lives in [`Maludb-Dev-Setup.md`](Maludb-Dev-Setup.md). One server-provisioning step worth calling out first:

### Extend the root filesystem

When you provision **50 GB or more** for an Ubuntu VM on ProxMox, the installer's default LVM layout does **not** claim all of the disk. The `lvextend` command grows the **logical volume**, but the **filesystem on top of it must also be resized** — otherwise the extra space stays invisible.

Check what you have, grow the volume to 100% of free space, then resize the filesystem in one step:

```bash
df -h /                          # before
sudo lvextend -r -l +100%FREE /dev/mapper/ubuntu--vg-ubuntu--lv
df -h /                          # after — should show full capacity
```

> If your `lvextend` does not support `-r`, run the two steps manually:
> ```bash
> sudo lvextend -l +100%FREE /dev/mapper/ubuntu--vg-ubuntu--lv
> sudo resize2fs /dev/mapper/ubuntu--vg-ubuntu--lv
> ```

---

## Getting started

### 1. Requirements

- Ubuntu 24.04 (or similar), Apache 2.4+, PHP 8.2+, PostgreSQL 14+, MySQL/MariaDB server.
- PHP extensions: `pdo`, `pdo_pgsql`, `pdo_mysql`, `mbstring`, `json`, `fileinfo`.
- Apache modules: `mod_rewrite`, `mod_headers`, `mod_deflate`.

##1a. Update and upgrade the Ubuntu installation.
```
sudo apt update
sudo apt upgrade
```
##1b. Install Apache, MariaDB, PHP, and the database drivers

This is a **LAMP** stack, so you install all four layers here. The local auth/routing store runs on **MariaDB** (a drop-in, MySQL-compatible engine — the PHP `pdo_mysql` driver and `mysql:` DSN work unchanged). The server needs **both** database drivers: `php8.3-pgsql` for the MaluDB **data store** (PostgreSQL) and `php8.3-mysql` for the **auth store** (MariaDB).
```
# Apache web server
sudo apt install apache2 -y
sudo systemctl enable apache2
sudo systemctl start apache2

# MariaDB — the local auth/routing store (drop-in MySQL replacement)
sudo apt install mariadb-server -y
sudo systemctl enable mariadb
sudo systemctl start mariadb

# PHP 8.3, the Apache module, and BOTH database drivers
# php8.3-mysql -> pdo_mysql/mysqli (works with MariaDB); php8.3-pgsql -> pdo_pgsql
sudo apt install -y php8.3 libapache2-mod-php8.3 php8.3-cli php8.3-pgsql php8.3-mysql

# Common PHP extensions used by the API
sudo apt install php-mbstring php-zip php-gd php-json php-curl -y
sudo phpenmod mbstring
sudo systemctl restart apache2
```
Verify the drivers loaded (both lines should print):
```
php -m | grep -E 'pdo_pgsql|pdo_mysql'
```
##1c. Secure the MariaDB installation

MariaDB installs with insecure defaults. Run the interactive hardening script and answer the prompts:
```
sudo mysql_secure_installation
```
You will be asked a series of questions. Recommended answers for a development server:

| Prompt | Recommended answer |
|---|---|
| **Enter current password for root** | Press **Enter** (none is set on a fresh install) |
| **Switch to unix_socket authentication?** | `Y` |
| **Change the root password?** | `n` (optional; with unix_socket auth, local root logs in via `sudo`) |
| **Remove anonymous users?** | `Y` |
| **Disallow root login remotely?** | `Y` |
| **Remove test database and access to it?** | `Y` |
| **Reload privilege tables now?** | `Y` |

> On Ubuntu 24.04 the MariaDB `root` account uses `unix_socket` auth by default, so locally you connect with `sudo mariadb` (or `sudo mysql`) — no password. Do **not** put `root` in `config/local-database.php`; the bootstrap script in step 4 creates a **dedicated, non-root** application user for the auth store.

##1d. Enable PHP, URL rewriting, and restart Apache

This project maps every `/v1/...` URL onto a single PHP file using **`.htaccess` rewrite rules**, so the Apache `rewrite` module must be enabled.
```
sudo a2enmod php8.3
sudo a2enmod rewrite
sudo systemctl restart apache2
```
##1e. Enable `.htaccess` overrides (`AllowOverride All`)

Enabling `mod_rewrite` is not enough — Apache ignores `.htaccess` files unless **`AllowOverride`** is turned on for the web root. By default on Ubuntu, Apache's DocumentRoot is **`/var/www/html`**, which is exactly where this repo's `html/` directory lands once you clone it (see step 2). The repo ships its rewrite rules in [`html/.htaccess`](html/.htaccess).

Edit the main Apache config:
```
sudo nano /etc/apache2/apache2.conf
```
Find the `<Directory /var/www/>` block and change `AllowOverride None` to `AllowOverride All`. If your DocumentRoot is `/var/www/html`, add (or edit) a block for it specifically:
```apache
<Directory /var/www/html>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```
Save and exit (`Ctrl+O`, `Enter`, `Ctrl+X`), then test the config and reload:
```
sudo apache2ctl configtest        # should print: Syntax OK
sudo systemctl reload apache2
```
> **Verify it works** after you've cloned the repo (step 2): a request to a `/v1/...` URL that returns JSON (rather than a `404` or the raw file) confirms `.htaccess` rewriting is active. If you get a `404` for a path you know exists, `AllowOverride` is almost certainly still `None`.

##1f. Install Composer 
```
# If composer isn't installed, install it system-wide:
curl -sS https://getcomposer.org/installer -o composer-setup.php
php composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php
composer --version
```
##1g. Make sure the folder is in the path
```
echo 'export PATH="$HOME/.local/bin:$PATH"' >> ~/.profile
. ~/.profile
```
##1h. Install MaluDB PHP Client using Composer.
```
composer require maludb/client
```
## 2. Clone the and Change It to Your Own GitHub Repo

### 2a. Make the parent directory of apache home writeable

```bash
cd /
sudo chmod 777 var
cd /var
sudo mv www www-original
```

### 2b. Clone the template repo into a new folder on the server

```bash
git clone https://github.com/maludb/maludb-lamp-api-server.git /var/www
cd /var/www
```
### 2c. Remove write privileges on the parent of apache home

```bash
cd /
sudo chmod 755 /var
cd www
sudo chmod 777 www
cd /var/www
```

### 2d. Create your new repo on GitHub

Create a new empty repository in your personal GitHub account.

Do **not** initialize it with a README, `.gitignore`, or license if you already have files locally.

Example new repo:

```text
https://github.com/your-github-username/my-new-repo
```

### 2e. Change the remote from the template repo to your own repo

Check the current remote:

```bash
git remote -v
```

It will probably show the template repo as `origin`.

Change `origin` to your new GitHub repo.

Using HTTPS:

```bash
git remote set-url origin https://github.com/your-github-username/my-new-repo.git
```

Using SSH:

```bash
git remote set-url origin git@github.com:your-github-username/my-new-repo.git
```

Verify the change:

```bash
git remote -v
```

### 2f. Push the code to your new GitHub repo

Make sure the branch is named `main`:

```bash
git branch -M main
```

Push to your repo:

```bash
git push -u origin main
```

Your local project is now connected to your own GitHub repo.

## Verify Everything Worked

Run:

```bash
git remote -v
git status
```

## 3. Configure the connection to the database

Apache's DocumentRoot should be `html/` with `AllowOverride All` set so `.htaccess` is honored (configured in step **1e**).

Copy the example config and fill in your PostgreSQL host/port:

```bash
cp config/database-example.php config/database.php
```

`config/database.php` holds the **fixed** Postgres host/port; the per-request database name/user/password come from the MySQL auth store. The real `config/database.php` is gitignored so deployment credentials never reach the repo.

## 4. Configure the auth store

First create the auth/routing store. The bootstrap script [`config/local-database.sql`](config/local-database.sql) creates the **database** (`maludb_auth`), a **dedicated non-root user** (`maludb`), the grants, and the **tables** in one shot. Before running it, open the file and change the placeholder password (`CHANGE_ME_AUTH_DB_PASSWORD`) to a strong value, then run it as the MariaDB root user:

```bash
# Edit the password in the script first, then:
sudo mariadb < config/local-database.sql
```

Now copy the example PHP config and fill in **the same** database name, user, and password you just set in the SQL script:

```bash
cp config/local-database-example.php config/local-database.php
# edit config/local-database.php — set DB_NAME=maludb_auth, DB_USER=maludb, DB_PASS=<your password>
```

The `users` table maps a token's `sha256` hash to a role and the PostgreSQL `(dbname, user, password)` that requests with that token connect as. Tokens are stored only as hashes (of the token body after the `malu_` prefix). You do not seed this table by hand — mint tokens through the API once the server is running (see [**Issuing API tokens**](#issuing-api-tokens)).

## 5. Make a request

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

## Issuing API tokens

Tokens are minted by the **`/v1/tokens`** endpoint — you do **not** edit the database by hand. There is a deliberate chicken-and-egg answer here: creating a token does **not** require an existing token. Instead, the caller proves authorization by supplying a **working PostgreSQL login** (`pg_dbname`, `pg_user`, `pg_password`); the endpoint connects to Postgres with those credentials and only mints the token if the connection succeeds. The token it returns will, from then on, connect as exactly that Postgres login.

> **You must already have a PostgreSQL role/database for the tenant.** This server does not create Postgres logins — it issues API tokens that map onto logins you (or MaluDB) created. The fixed Postgres host/port come from `config/database.php`; only the db/user/password come from the request body.

### Create a token — `POST /v1/tokens`

```bash
curl -X POST https://your-host/v1/tokens \
  -H "Content-Type: application/json" \
  -d '{
    "pg_dbname": "tenant_db",
    "pg_user": "tenant_user",
    "pg_password": "tenant_password",
    "role": "executor",
    "device_name": "my-app-prod",
    "expires_in_days": 365
  }'
```

| Field | Required | Notes |
|---|---|---|
| `pg_dbname`, `pg_user`, `pg_password` | **yes** | The PostgreSQL login this token will connect as. Verified by an actual connection before the token is minted. |
| `role` | no | Defaults to `executor`. |
| `device_name` | no | Free-text label for listing/diagnostics (e.g. the app or machine name). |
| `expires_in_days` | no | Positive integer; omit for a non-expiring token. |
| `user_id` | no | App-level user id; auto-assigned if omitted. |

The response returns the **plaintext token once** — store it now, it is not recoverable later (only its `sha256` is kept):

```json
{
  "token": "malu_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "id": 1,
  "user_id": 1,
  "role": "executor",
  "pg_dbname": "tenant_db",
  "pg_user": "tenant_user",
  "expires_at": "2027-06-09 00:00:00",
  "device_name": "my-app-prod"
}
```

Put that `token` value in your application's `Authorization: Bearer malu_…` header.

### List tokens — `GET /v1/tokens`

Returns metadata only (never the token value or the Postgres password) for a given connection. Authorized the same way — by proving the Postgres login:

```bash
curl https://your-host/v1/tokens \
  -H "Content-Type: application/json" \
  -d '{"pg_dbname":"tenant_db","pg_user":"tenant_user","pg_password":"tenant_password"}'
```

### Revoke a token — `DELETE /v1/tokens/{id}`

Deletes the token row (the `id` comes from the create/list response), authorized by the same Postgres login:

```bash
curl -X DELETE https://your-host/v1/tokens/1 \
  -H "Content-Type: application/json" \
  -d '{"pg_dbname":"tenant_db","pg_user":"tenant_user","pg_password":"tenant_password"}'
```

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

Released under the [MIT License](LICENSE) — © 2026 Edward Honour. Use it, learn from it, and ship it; just keep the copyright notice.
