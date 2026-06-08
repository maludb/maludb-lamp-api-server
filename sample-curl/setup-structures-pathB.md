# Sample cURL — Life-Coach Structures, Path B (first-class custom types)

Stand up the AI Life Coach vocabulary with **real, domain-specific types** — subjects typed
`goal`, `value`, `milestone`, … and verbs typed `aspiration`, `commitment`, … This is the more
faithful "define your own structures" demo.

> **Prerequisite:** the owner must run **`setup-types-owner.sql`** once (it registers these subject
> types + verb types). Without it, `type:"goal"` is rejected with `422 unknown subject_type`. If you
> can't run owner SQL, use **`setup-structures-pathA.md`** instead.

---

## Setup

```bash
export MALU_URL='https://fastapi.maludb.org'
export MALU_TOKEN='malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
```

`jq` is used to capture ids for linking; substitute by hand if you don't have it.

## 0. Confirm the custom types are registered

```bash
curl -s "$MALU_URL/v1/subject-types" -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
curl -s "$MALU_URL/v1/verb-types"    -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
```

You should see `goal, outcome, goal_category, milestone, motivation, value, identity_statement,
desired_identity, fear, reward, meaning, vision` among the subject types, and `aspiration,
commitment, obstacle, motivation, emotion, preference, progress, reflection` among the verb types.
If they're absent, the owner hasn't run `setup-types-owner.sql` yet.

---

## 1. Verbs with custom types

```bash
curl -s -X POST "$MALU_URL/v1/verbs" \
  -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"canonical_name":"wants_to_achieve","type":"aspiration"}'
echo
```

### Seed the coaching verbs, each with its type

```bash
# "verb|verb_type" pairs
VERBS=(
  "wants_to_achieve|aspiration"  "intends_to|aspiration"  "has_desired_outcome|aspiration"
  "committed_to|commitment"      "scheduled_for|commitment"
  "struggles_with|obstacle"      "blocked_by|obstacle"    "is_constrained_by|obstacle"
  "is_motivated_by|motivation"   "values|motivation"      "aligns_with|motivation"
  "currently_feels|emotion"      "felt|emotion"
  "prefers|preference"           "dislikes|preference"    "responds_to|preference"
  "opted_into|preference"        "opted_out_of|preference"
  "achieved|progress"            "improved|progress"      "regressed|progress"
  "completed|progress"           "missed|progress"
  "realized|reflection"          "learned|reflection"     "decided|reflection"  "believes|reflection"
)

for pair in "${VERBS[@]}"; do
  name="${pair%%|*}"; vtype="${pair##*|}"
  printf '%s (%s) -> ' "$name" "$vtype"
  curl -s -X POST "$MALU_URL/v1/verbs" \
    -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d "{\"canonical_name\":\"$name\",\"type\":\"$vtype\"}"
  echo
done
```

## 2. Subjects with first-class types

```bash
GID=$(curl -s -X POST "$MALU_URL/v1/subjects" \
  -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"label":"Lose 10 pounds","type":"goal","description":"Weight-loss goal"}' | jq -r '.subject.id')
echo "goal subject id=$GID"
```

### Seed the coaching nodes with their real types

```bash
# "label|subject_type" pairs
NODES=(
  "Lose 10 pounds|goal"                 "Write a book|goal"
  "More energy for family|outcome"      "Better discipline|outcome"
  "Health|goal_category"                "Career|goal_category"   "Learning|goal_category"
  "Complete first 5 workouts|milestone"
  "Energy for family|motivation"
  "Family|value"                        "Independence|value"     "Mastery|value"
  "I am a disciplined person|identity_statement"
  "I want to be consistent|desired_identity"
  "Fear of regret|fear"
  "Confidence|reward"                   "Freedom|reward"
  "A healthier version of me|vision"
)

for pair in "${NODES[@]}"; do
  label="${pair%%|*}"; stype="${pair##*|}"
  printf '%s (%s) -> ' "$label" "$stype"
  curl -s -X POST "$MALU_URL/v1/subjects" \
    -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d "{\"label\":\"$label\",\"type\":\"$stype\"}"
  echo
done
```

### Goal qualifiers stay attributes

Even with first-class types, `target`/`deadline`/`priority`/`goal_status`/`metric`/`success_criteria`
are **attributes on the goal**, not nodes:

```bash
curl -s -X POST "$MALU_URL/v1/attributes" -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"target_kind\":\"subject\",\"target_id\":$GID,\"attr_name\":\"target\",\"value_numeric\":10,\"unit\":\"lb\"}"; echo
curl -s -X POST "$MALU_URL/v1/attributes" -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"target_kind\":\"subject\",\"target_id\":$GID,\"attr_name\":\"priority\",\"value_text\":\"high\"}"; echo
```

## 3. Link nodes with statements

*Edward → wants_to_achieve → Lose 10 pounds*, *Edward → is_motivated_by → Energy for family*:

```bash
EDWARD=$(curl -s -X POST "$MALU_URL/v1/subjects" -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"label":"Edward","type":"person"}' | jq -r '.subject.id')
MOTIV=$(curl -s "$MALU_URL/v1/subjects?q=Energy%20for%20family" -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' | jq -r '.subjects[0].id')

curl -s -X POST "$MALU_URL/v1/statements" -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"subject_id\":$EDWARD,\"verb\":\"wants_to_achieve\",\"object_kind\":\"subject\",\"object_id\":$GID}"; echo
curl -s -X POST "$MALU_URL/v1/statements" -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"subject_id\":$EDWARD,\"verb\":\"is_motivated_by\",\"object_kind\":\"subject\",\"object_id\":$MOTIV}"; echo
```

---

## Verify

```bash
curl -s "$MALU_URL/v1/subjects?limit=200"  -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
curl -s "$MALU_URL/v1/verbs?limit=200"     -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
curl -s "$MALU_URL/v1/statements?limit=50" -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
```

## Notes

- **`422 unknown subject_type "goal"` / `unknown verb_type "aspiration"`** — `setup-types-owner.sql`
  hasn't been run by the owner. That's the prerequisite for this path.
- **`409 conflict`** — labels/verbs are unique; re-running the seeds is idempotent.
