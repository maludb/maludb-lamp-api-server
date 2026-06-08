# Sample cURL — Extract structures from text (`/v1/memory/ingest`)

The payoff of the demo: once your **structures** (subject types, verbs) are set up, feed the system
plain text and it extracts subjects, events, verbs, edges, and attributes automatically — no manual
SVPO wiring. This is the LLM extraction pipeline (`text → GPT-4o → memory graph`).

> Run `setup-verbs.md` first (and either `setup-structures-pathA.md` or `…-pathB.md`) so the
> extractor reuses your canonical verbs/subjects instead of minting near-duplicates.

---

## Setup

```bash
export MALU_URL='https://fastapi.maludb.org'
export MALU_TOKEN='malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
```

`POST /v1/memory/ingest` body:

| field       | required | notes                                                                  |
|-------------|----------|------------------------------------------------------------------------|
| `text`      | **yes**  | the raw text to extract from                                           |
| `model`     | no       | default `chatgpt-4o`                                                   |
| `hints`     | no       | array of `{"subject-type","subject-name"}` — context entities to seed |
| `namespace` | no       | default `default`                                                     |
| `preview`   | no       | `true` returns the assembled prompt **without** calling the model or writing |

---

## 1. Preview first (no model call, no writes)

See exactly what the extractor will be asked — the system prompt plus the USER message built from
your text, hints, and the current `KNOWN_SUBJECTS` / `KNOWN_VERBS`:

```bash
curl -s -X POST "$MALU_URL/v1/memory/ingest" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{
    "preview": true,
    "text": "On Monday Edward said he wants to lose 10 pounds by September. He committed to walking 20 minutes on Monday, Wednesday and Friday, but he struggles with late-night eating. He felt discouraged after missing a workout last week, though his sleep routine has improved.",
    "hints": [
      {"subject-type":"person","subject-name":"Edward"}
    ]
  }'
echo
```

## 2. Real ingest (calls the model, writes the graph)

Drop `preview`. This calls GPT-4o and writes the extracted structures in one transaction; it returns
the new `document_id` and a `result` summarizing what was created/resolved.

```bash
curl -s -X POST "$MALU_URL/v1/memory/ingest" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{
    "text": "On Monday Edward said he wants to lose 10 pounds by September. He committed to walking 20 minutes on Monday, Wednesday and Friday, but he struggles with late-night eating. He felt discouraged after missing a workout last week, though his sleep routine has improved.",
    "hints": [
      {"subject-type":"person","subject-name":"Edward"}
    ]
  }'
echo
```

> The model needs an API key configured for `chatgpt-4o` (set via `POST /v1/model-prompts`). If it
> isn't set you'll get `409 model_api_key_missing`; a bad key surfaces as `502`.

## 3. A longer document

Extraction works on multi-sentence text just as well — paste a coaching-call transcript or a journal
entry as `text`. The richer the text, the more goals / commitments / obstacles / emotions it pulls out.

```bash
curl -s -X POST "$MALU_URL/v1/memory/ingest" \
  -H "Authorization: Bearer $MALU_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{
    "text": "Coaching call, June 8. Edward is motivated by having more energy for his family. His goal this quarter is to write the first three chapters of his book; the first milestone is a 5,000-word outline by July 1. He values discipline and wants to become someone who is consistent. He is constrained by long work hours and tends to procrastinate. He completed two writing sessions last week and felt encouraged.",
    "hints": [
      {"subject-type":"person","subject-name":"Edward"},
      {"subject-type":"goal_category","subject-name":"Career"}
    ]
  }'
echo
```

---

## 4. See what the extractor created

```bash
# subjects (incl. event-subjects with occurred_at) and their attributes
curl -s "$MALU_URL/v1/subjects?limit=200&with=attributes" -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
# the canonical verbs it used / created
curl -s "$MALU_URL/v1/verbs?limit=200"      -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
# the SVPO edges
curl -s "$MALU_URL/v1/statements?limit=100" -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
# events (progress / emotional moments land here as subjects-with-a-time)
curl -s "$MALU_URL/v1/episodes?limit=100"   -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
```

## 5. Review queue (LLM-derived edges)

Extractor output is written with `provenance=suggested` — a review queue you can accept/reject:

```bash
curl -s "$MALU_URL/v1/statements?provenance=suggested&limit=100" \
  -H "Authorization: Bearer $MALU_TOKEN" -H 'Accept: application/json'
```

## Notes

- **Hints seed `KNOWN_SUBJECTS`.** Passing `{"subject-type":"person","subject-name":"Edward"}`
  tells the model Edward already exists, so it reuses him instead of creating a duplicate. The
  endpoint also injects every existing subject/verb into the prompt automatically.
- **Types still apply.** The extractor's subjects must use registered subject types. With Path B
  custom types in place, it can place goals/values directly; otherwise it falls back to generic
  types. (See `setup-structures-path*.md`.)
- **`preview:true` costs nothing** — use it to iterate on wording and hints before spending a model call.
