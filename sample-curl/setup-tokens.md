# Sample cURL — API Tokens

Copy-paste cURL commands to **issue, list, and revoke** API tokens against your MaluDB API
server installation (`/v1/tokens`). A token is what your application puts in its
`Authorization: Bearer malu_…` header; every other endpoint (`setup-subjects.md`,
`setup-verbs.md`, …) needs one first.

**This is the bootstrap endpoint** — it is the one place that does **not** require an existing
token. Instead you authorize by proving a **working PostgreSQL login**: you send
`pg_dbname` / `pg_user` / `pg_password` in the body, the server connects to Postgres with them,
and only mints the token if that connection succeeds. The token it returns will, from then on,
connect as exactly that Postgres login.

> **Prerequisite:** the PostgreSQL role/database must already exist. This server issues API
> tokens that map onto Postgres logins you (or MaluDB) created — it does **not** create the
> Postgres roles themselves. The fixed Postgres host/port come from `config/database.php`; only
> the db/user/password come from the request body.

---

## Setup

These scripts use the public demo host and a sample tenant connection. Edit them for your
install — or export them once and the commands below will pick them up:

```bash
export MALU_URL='https://fastapi.maludb.org'
export PG_DBNAME='tenant_db'
export PG_USER='tenant_user'
export PG_PASSWORD='tenant_password'
```

> Every command below also shows the fully-literal form (host + values inline) so you can paste a
> single command without exporting anything.

---

## Create a token — `POST /v1/tokens`

Body fields:

| field         | required | notes                                                              |
|---------------|----------|--------------------------------------------------------------------|
| `pg_dbname`   | **yes**  | the PostgreSQL database this token connects to                     |
| `pg_user`     | **yes**  | the PostgreSQL role this token connects as                         |
| `pg_password` | **yes**  | that role's password — verified by a real connection before minting |
| `role`        | no       | application role label; defaults to `executor`                     |
| `device_name` | no       | free-text label for listing/diagnostics (e.g. the app or machine)  |
| `expires_in_days` | no   | positive integer; omit for a non-expiring token                    |
| `user_id`     | no       | app-level user id; auto-assigned if omitted                        |

Returns `201`. The **plaintext token is shown once** — store it now, it is not recoverable later
(only its `sha256` is kept).

```bash
curl -X POST "$MALU_URL/v1/tokens" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "{
    \"pg_dbname\": \"$PG_DBNAME\",
    \"pg_user\": \"$PG_USER\",
    \"pg_password\": \"$PG_PASSWORD\",
    \"role\": \"executor\",
    \"device_name\": \"my-app-prod\",
    \"expires_in_days\": 365
  }"
```

Literal form:

```bash
curl -X POST 'https://fastapi.maludb.org/v1/tokens' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"pg_dbname":"tenant_db","pg_user":"tenant_user","pg_password":"tenant_password","role":"executor","device_name":"my-app-prod","expires_in_days":365}'
```

Sample response:

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

Capture the token straight into a variable for the rest of your session (requires `jq`):

```bash
export MALU_TOKEN=$(curl -s -X POST "$MALU_URL/v1/tokens" \
  -H 'Content-Type: application/json' \
  -d "{\"pg_dbname\":\"$PG_DBNAME\",\"pg_user\":\"$PG_USER\",\"pg_password\":\"$PG_PASSWORD\",\"device_name\":\"cli\"}" \
  | jq -r '.token')
echo "$MALU_TOKEN"
```

A **non-expiring** token (omit `expires_in_days`):

```bash
curl -X POST "$MALU_URL/v1/tokens" \
  -H 'Content-Type: application/json' \
  -d "{\"pg_dbname\":\"$PG_DBNAME\",\"pg_user\":\"$PG_USER\",\"pg_password\":\"$PG_PASSWORD\",\"device_name\":\"backfill-job\"}"
```

---

## List tokens — `GET /v1/tokens`

Returns metadata only for the given connection — **never** the token value or the Postgres
password. Authorized the same way, by proving the Postgres login:

```bash
curl -X GET "$MALU_URL/v1/tokens" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "{\"pg_dbname\":\"$PG_DBNAME\",\"pg_user\":\"$PG_USER\",\"pg_password\":\"$PG_PASSWORD\"}"
```

Literal form:

```bash
curl -X GET 'https://fastapi.maludb.org/v1/tokens' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"pg_dbname":"tenant_db","pg_user":"tenant_user","pg_password":"tenant_password"}'
```

Sample response (note the `token_prefix` — the first chars of the token, for diagnostics — and
that no full token or password is returned):

```json
{
  "tokens": [
    {
      "id": 1,
      "token_prefix": "abc12345",
      "user_id": 1,
      "role": "executor",
      "pg_dbname": "tenant_db",
      "pg_user": "tenant_user",
      "expires_at": "2027-06-09 00:00:00",
      "device_name": "my-app-prod",
      "created_at": "2026-06-09 00:00:00"
    }
  ]
}
```

---

## Revoke a token — `DELETE /v1/tokens/{id}`

Deletes the token row (the `id` comes from the create/list response). Authorized by the same
Postgres login, so you can only revoke tokens that belong to a connection you can prove you own:

```bash
curl -X DELETE "$MALU_URL/v1/tokens/1" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "{\"pg_dbname\":\"$PG_DBNAME\",\"pg_user\":\"$PG_USER\",\"pg_password\":\"$PG_PASSWORD\"}"
```

Returns `{"deleted":true,"id":1}`. The next request that presents the revoked token gets `401`.

---

## Verify

Use the new token against a normal endpoint — a `200` proves the whole chain (token → routing →
tenant Postgres) works:

```bash
curl -X GET "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json'
```

## Notes & troubleshooting

- **`400 missing_field`** — one of `pg_dbname`, `pg_user`, `pg_password` was empty. All three are
  required for create, list, and revoke.
- **`403 pg_auth_failed`** — the supplied Postgres credentials could not connect. Fix the
  db/user/password (or the Postgres role) and retry; no token is minted on failure.
- **`422 validation_failed`** — `expires_in_days` was present but not a positive integer.
- **Lost the token?** It is unrecoverable by design (only the `sha256` is stored). Revoke the old
  `id` and mint a new one.
- **`404`** on `DELETE /v1/tokens/{id}` — no token row with that `id` exists.
- **`403 forbidden`** on `DELETE` — the token exists but belongs to a different Postgres
  connection; you can only revoke tokens for a connection whose password you supplied.
