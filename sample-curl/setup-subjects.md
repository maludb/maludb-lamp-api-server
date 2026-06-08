# Sample cURL — Subjects

Copy-paste cURL commands to test the **Subjects** endpoint (`/v1/subjects`) against your
MaluDB API server installation: list what's already there, then seed a full range of subject
types.

A **subject** is any entity in the knowledge graph — a person, a system, a project, a place,
etc. (Events are subjects too, carrying a time — see `setup-episodes.md`.)

---

## Setup

These scripts use the public demo host and a local dev token. Edit them for your install — or,
to avoid editing every line, export them once and the commands below will pick them up:

```bash
export MALU_URL='https://fastapi.maludb.org'
export MALU_TOKEN='malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
```

> Every command below also shows the fully-literal form (host + token inline) so you can paste a
> single command without exporting anything.

---

## List existing subjects

**All subjects** (this is the smoke test — a `200` with a `{"subjects":[...]}` body means your
token, routing, and database connection all work):

```bash
curl -X GET "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json'
```

Literal form:

```bash
curl -X GET 'https://fastapi.maludb.org/v1/subjects' \
  -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
  -H 'Accept: application/json'
```

**Search** by name/description (`q`) and cap the result count (`limit`, default 50, max 200):

```bash
curl -X GET "$MALU_URL/v1/subjects?q=oracle&limit=10" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json'
```

**With attributes** — include each subject's typed node attributes inline:

```bash
curl -X GET "$MALU_URL/v1/subjects?with=attributes" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json'
```

**A single subject by id** (`{id}` from the list above):

```bash
curl -X GET "$MALU_URL/v1/subjects/1" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json'
```

---

## Add subjects

`POST /v1/subjects` — body fields:

| field           | required | notes                                                              |
|-----------------|----------|--------------------------------------------------------------------|
| `label`         | **yes**  | canonical name (must be unique in the graph)                       |
| `type`          | no       | `person`, `software`, `project`, `organization`, `equipment`, `network`, `process`, `workflow`, `time_period`, `other` (free text — others accepted) |
| `description`   | no       | short free-text description                                        |
| `classifier_md` | no       | longer markdown notes used to classify/disambiguate the subject    |

Returns `201` with `{"subject":{"id":...,"label":...,"type":...}}`.

### A full range, one per type

**person**

```bash
curl -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"label":"Ed Honour","type":"person","description":"Database administrator"}'
```

**software**

```bash
curl -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"label":"Oracle Database 21c","type":"software","description":"Relational database engine","classifier_md":"Production RDBMS. Aliases: Oracle 21c, ORA21."}'
```

**project**

```bash
curl -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"label":"Drajeo","type":"project","description":"Data-platform modernization program"}'
```

**organization**

```bash
curl -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"label":"Kinetic Seas","type":"organization","description":"Operating company"}'
```

**equipment**

```bash
curl -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"label":"DC-East datacenter","type":"equipment","description":"Primary east-coast facility"}'
```

**network**

```bash
curl -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"label":"Core VPC","type":"network","description":"Primary virtual private cloud"}'
```

**process**

```bash
curl -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"label":"Nightly Backup","type":"process","description":"Scheduled full backup job"}'
```

**workflow**

```bash
curl -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"label":"Release Pipeline","type":"workflow","description":"CI/CD build-test-deploy flow"}'
```

**time_period**

```bash
curl -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"label":"Q2 2026","type":"time_period","description":"Second fiscal quarter of 2026"}'
```

**other**

```bash
curl -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"label":"Change Ticket CHG-1042","type":"other","description":"Approved change request"}'
```

### Seed them all at once

```bash
for s in \
  '{"label":"Ed Honour","type":"person","description":"Database administrator"}' \
  '{"label":"Oracle Database 21c","type":"software","description":"Relational database engine"}' \
  '{"label":"Drajeo","type":"project","description":"Data-platform modernization program"}' \
  '{"label":"Kinetic Seas","type":"organization","description":"Operating company"}' \
  '{"label":"DC-East datacenter","type":"equipment","description":"Primary east-coast facility"}' \
  '{"label":"Core VPC","type":"network","description":"Primary virtual private cloud"}' \
  '{"label":"Nightly Backup","type":"process","description":"Scheduled full backup job"}' \
  '{"label":"Release Pipeline","type":"workflow","description":"CI/CD build-test-deploy flow"}' \
  '{"label":"Q2 2026","type":"time_period","description":"Second fiscal quarter of 2026"}' \
  '{"label":"Change Ticket CHG-1042","type":"other","description":"Approved change request"}' ; do
  curl -s -X POST "$MALU_URL/v1/subjects" \
    -H "Authorization: Bearer $MALU_TOKEN" \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    -d "$s"
  echo
done
```

---

## Verify

Re-list and confirm the new subjects are present:

```bash
curl -X GET "$MALU_URL/v1/subjects?limit=200" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json'
```

## Notes & troubleshooting

- **`401 unauthorized`** — the `Authorization` header is missing/wrong, or the token is expired or
  revoked. Mint a new one via `/v1/tokens`.
- **`400 missing_field`** — `label` was empty; it is the only required field.
- **`409` on insert** — a subject with that `label` already exists (`canonical_name` is unique).
  Re-running the seed block after the first time is expected to return `409`s — that's idempotent,
  not a failure.
- **`502`/`503`** — the API reached but the tenant Postgres connection failed (bad stored
  credentials or DB unreachable).
