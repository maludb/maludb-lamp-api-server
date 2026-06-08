# Sample cURL — Life-Coach Structures, Path A (self-service, no owner step)

Stand up the AI Life Coach vocabulary **using only the API** — no DB-owner action required.
The trick: subject *types* are a closed vocabulary your token can't extend, so abstract nodes
(`goal`, `value`, `milestone`, …) are created as the existing **`concept`** type and distinguished
by a **`category` attribute**. Verbs are created **untyped** (the verb-type vocabulary is owner-only too).

> Prefer first-class custom types (`type:"goal"`)? Use **`setup-structures-pathB.md`** instead — it
> needs `setup-types-owner.sql` run once by the owner. Pick one path per install.

---

## Setup

```bash
export MALU_URL='https://fastapi.maludb.org'
export MALU_TOKEN='malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
```

These examples capture created ids with [`jq`](https://jqlang.github.io/jq/) (for linking). If you
don't have `jq`, read the `id` from the printed JSON and substitute it by hand.

---

## 1. Verbs (untyped)

The full verb vocabulary lives in **`setup-verbs.md`** — run that seed loop. Verbs are created
without a `type` (the verb-type list is owner-managed; an unknown type is rejected). Quick example:

```bash
curl -s -X POST "$MALU_URL/v1/verbs" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"canonical_name":"wants_to_achieve"}'
```

## 2. Nodes as `concept` + a `category` attribute

Each abstract node is a subject of type **`concept`**, tagged with a `category` attribute that
records what it really is (`goal`, `value`, `milestone`, …). Create the subject, capture its id,
then attach the attribute:

```bash
# create the node
GID=$(curl -s -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"label":"Lose 10 pounds","type":"concept","description":"Weight-loss goal"}' | jq -r '.subject.id')
echo "created subject id=$GID"

# tag it as a goal
curl -s -X POST "$MALU_URL/v1/attributes" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"target_kind\":\"subject\",\"target_id\":$GID,\"attr_name\":\"category\",\"value_text\":\"goal\"}"
echo
```

### Goal qualifiers are attributes too

`target`, `deadline`, `priority`, `goal_status`, `metric`, `success_criteria` are **attributes on
the goal**, not separate nodes — attach them the same way (`value_text`, `value_numeric`,
`value_timestamp` as appropriate):

```bash
curl -s -X POST "$MALU_URL/v1/attributes" -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"target_kind\":\"subject\",\"target_id\":$GID,\"attr_name\":\"target\",\"value_numeric\":10,\"unit\":\"lb\"}"; echo
curl -s -X POST "$MALU_URL/v1/attributes" -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"target_kind\":\"subject\",\"target_id\":$GID,\"attr_name\":\"deadline\",\"value_timestamp\":\"2026-09-01T00:00:00Z\"}"; echo
curl -s -X POST "$MALU_URL/v1/attributes" -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"target_kind\":\"subject\",\"target_id\":$GID,\"attr_name\":\"goal_status\",\"value_text\":\"active\"}"; echo
```

### Seed a set of nodes (label → category)

```bash
# "label|category" pairs covering the coaching node kinds
NODES=(
  "Lose 10 pounds|goal"
  "Write a book|goal"
  "More energy for family|outcome"
  "Better discipline|outcome"
  "Health|goal_category"
  "Career|goal_category"
  "Complete first 5 workouts|milestone"
  "Energy for family|motivation"
  "Family|value"
  "Independence|value"
  "I am a disciplined person|identity_statement"
  "I want to be consistent|desired_identity"
  "Fear of regret|fear"
  "Confidence|reward"
  "A healthier version of me|vision"
)

for pair in "${NODES[@]}"; do
  label="${pair%%|*}"; category="${pair##*|}"
  id=$(curl -s -X POST "$MALU_URL/v1/subjects" \
        -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -d "{\"label\":\"$label\",\"type\":\"concept\"}" | jq -r '.subject.id // empty')
  if [ -n "$id" ]; then
    curl -s -X POST "$MALU_URL/v1/attributes" \
      -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
      -d "{\"target_kind\":\"subject\",\"target_id\":$id,\"attr_name\":\"category\",\"value_text\":\"$category\"}" >/dev/null
    echo "$label -> concept (category=$category), id=$id"
  else
    echo "$label -> already exists (or error); skipping attribute"
  fi
done
```

## 3. Link nodes with a statement (subject → verb → object)

Statements connect two subjects by an existing verb. The object is given by `object_kind` +
`object_id`, so capture the object's id first. Example — *Edward → wants_to_achieve → Lose 10 pounds*:

```bash
EDWARD=$(curl -s -X POST "$MALU_URL/v1/subjects" -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"label":"Edward","type":"person"}' | jq -r '.subject.id')

curl -s -X POST "$MALU_URL/v1/statements" \
  -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"subject_id\":$EDWARD,\"verb\":\"wants_to_achieve\",\"object_kind\":\"subject\",\"object_id\":$GID}"
echo
```

> Note: the `verb` must already exist (seed `setup-verbs.md` first), or you'll get
> `422 Unknown verb`.

---

## Verify

```bash
curl -s "$MALU_URL/v1/subjects?limit=200"  -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
curl -s "$MALU_URL/v1/attributes?attr_name=category" -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
curl -s "$MALU_URL/v1/statements?limit=50" -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
```

## Notes

- **Why `concept`?** It's a registered catch-all subject type, so it's always accepted. The
  `category` attribute carries the real domain meaning and is fully queryable
  (`/v1/attributes?attr_name=category`).
- **`409` on re-run** — labels are unique; re-running the seed is idempotent (existing nodes 409).
- **`422 unknown subject_type`** — you tried a custom type on Path A; use `concept` here, or switch
  to Path B.
