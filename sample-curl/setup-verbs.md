# Sample cURL — Verbs

Copy-paste cURL commands to test the **Verbs** endpoint (`/v1/verbs`) and seed the canonical
verb set for an **AI Life Coach** application: list existing verbs, then add the full vocabulary.

A **verb** is the predicate that links a subject to an object in an SVPO statement
(`subject --verb--> object`). Keep verbs small and canonical (`wants_to_achieve`, not
`is_wanting_to_achieve`); tense/status/outcome belong on the statement as attributes.

> **Naming note:** these are *canonical verbs*, so this script is `setup-verbs.md`. The fixed
> **verb-type** enumeration is a separate, read-only list (see "Verb types" below) — don't confuse
> the two.

---

## Setup

```bash
export MALU_URL='https://fastapi.maludb.org'
export MALU_TOKEN='malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
```

> Every command also shows the fully-literal form (host + token inline) so you can paste a single
> command without exporting anything.

---

## List existing verbs

**All verbs** — each row carries `linked_subjects` (how many subjects use it):

```bash
curl -X GET "$MALU_URL/v1/verbs" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json'
```

Literal form:

```bash
curl -X GET 'https://fastapi.maludb.org/v1/verbs' \
  -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
  -H 'Accept: application/json'
```

**Search** (`q`) and cap the count (`limit`, default 50, max 200):

```bash
curl -X GET "$MALU_URL/v1/verbs?q=achieve&limit=10" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json'
```

---

## Verb types (read-only)

`type` on a verb is **optional**, but if you set it, it must be one of the DB-registered verb
types — an unknown value is rejected with `400 invalid_parameter_value`. List the valid set:

```bash
curl -X GET "$MALU_URL/v1/verb-types" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json'
```

The registered types are: `installed, configured, attended, created, updated, removed, migrated,
deployed, tested, verified, approved, rejected, decided, discovered, observed, reported, requested,
assigned, scheduled, completed, failed, blocked, resolved, documented, learned, connected,
disconnected, started, stopped, other`.

This list is **read-only via the API** — adding a new verb type is a database-side operation, not
an endpoint. The life-coaching verbs below are seeded **untyped** (valid and fully usable); assign a
type later only where one of the registered values genuinely fits.

---

## Add verbs

`POST /v1/verbs` — body fields:

| field            | required | notes                                                     |
|------------------|----------|-----------------------------------------------------------|
| `canonical_name` | **yes**  | the verb slug (must be unique in the graph)               |
| `type`           | no       | one of the registered verb types above (omit if none fit) |
| `description`    | no       | short free-text description                               |
| `classifier_md`  | no       | longer markdown notes for disambiguation                  |

Returns `201` with `{"verb":{"id":...,"canonical_name":...,"type":...}}`.

### A single verb (minimal)

```bash
curl -X POST "$MALU_URL/v1/verbs" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"canonical_name":"wants_to_achieve"}'
```

### A single verb with an optional type + description

```bash
curl -X POST "$MALU_URL/v1/verbs" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"canonical_name":"achieved","type":"completed","description":"Reached a goal or target"}'
```

### Typical edge patterns (identity, profile & consent)

How these verbs are used as `subject --verb--> object` edges:

| Verb                 | Typical pattern                               |
|----------------------|-----------------------------------------------|
| `has_profile`        | User --has_profile--> UserProfile             |
| `has_phone_number`   | User --has_phone_number--> PhoneNumber        |
| `owns_account`       | User --owns_account--> SubscriptionAccount    |
| `participated_in`    | User --participated_in--> Call                |
| `used_agent`         | User --used_agent--> CoachAgent               |
| `has_support_person` | User --has_support_person--> SupportPerson    |
| `authorized`         | User --authorized--> ConsentEvent             |
| `opted_into`         | User --opted_into--> Feature                  |
| `opted_out_of`       | User --opted_out_of--> Topic / Feature        |

### Seed the full Life Coach verb vocabulary

This walks the entire vocabulary (grouped by purpose) and creates each verb untyped. Re-running it
is safe — already-present verbs return `409` (see Notes).

```bash
VERBS=(
  # Identity, profile & consent
  has_profile has_phone_number owns_account participated_in used_agent
  has_support_person authorized opted_into opted_out_of

  # Goals & objectives
  wants_to_achieve has_desired_outcome belongs_to_category measured_by has_target
  has_deadline broken_into prioritized paused resumed achieved abandoned

  # Values & motivation
  values is_motivated_by aligns_with

  # Current state
  currently_has currently_feels reported has_baseline

  # Obstacles & constraints
  struggles_with is_constrained_by blocked_by triggered_by leads_to undermines needs

  # Commitments & plans
  committed_to intends_to scheduled_for supports addresses

  # Actions & performance
  performed completed missed rescheduled fulfilled advanced updated_progress_for
  improved regressed

  # Insights & decisions
  realized learned believes decided

  # Preferences
  prefers dislikes responds_to requested

  # Conversation & provenance
  discussed mentioned produced contains summarized_by extracted_from supported_by
  confirmed_by contradicted_by superseded_by

  # Safety & compliance
  mentioned_sensitive_topic triggered_safety_flag provided_disclaimer referred_to
)

for v in "${VERBS[@]}"; do
  printf '%s -> ' "$v"
  curl -s -X POST "$MALU_URL/v1/verbs" \
    -H "Authorization: Bearer $MALU_TOKEN" \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    -d "{\"canonical_name\":\"$v\"}"
  echo
done
```

---

## Verify

Re-list and confirm the vocabulary is present (bump the limit so nothing is truncated):

```bash
curl -X GET "$MALU_URL/v1/verbs?limit=200" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json'
```

## Notes & troubleshooting

- **`400 missing_field`** — `canonical_name` was empty; it is the only required field.
- **`400 invalid_parameter_value` ("unknown verb_type ...")** — the `type` you passed isn't a
  registered verb type. Omit `type`, or pick one from `/v1/verb-types`.
- **`409 conflict`** — a verb with that `canonical_name` already exists (it's unique). Re-running
  the seed block is idempotent; the `409`s on the second run are expected, not failures.
- **`401 unauthorized`** — missing/expired/revoked token; mint a new one via `/v1/tokens`.
- **`502`/`503`** — the API was reached but the tenant Postgres connection failed.
