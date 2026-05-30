# Regression curl commands — /v1/attribute-check   (0.83.0 completeness check)
# Advisory: given (target_kind, target_id) returns {applies_to, type_value,
# missing_required[], fields[]} — drives form validation on submit. The DB never
# rejects on missing attributes; this is purely informational.
# Self-cleaning: creates a Meeting episode (seeded with a 'duration_minutes' template),
# checks it, sets the attribute, re-checks, then deletes.

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
H='https://fastapi.maludb.org'

EID=$(curl -s -X POST "$H/v1/episodes" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"title":"Attribute check event","kind":"Meeting"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "episode id=$EID"

# --- check before setting anything -> 200 (fields[].present=false for templated attrs)
curl -s "$H/v1/attribute-check?target_kind=episode_object&target_id=$EID" -H "$TOK"; echo

# --- set the templated attribute
curl -s -o /dev/null -X POST "$H/v1/attributes" -H "$TOK" -H 'Content-Type: application/json' \
    -d "{\"target_kind\":\"episode_object\",\"target_id\":$EID,\"attr_name\":\"duration_minutes\",\"value_numeric\":60}"

# --- check again -> 200 (duration_minutes now present=true)
curl -s "$H/v1/attribute-check?target_kind=episode_object&target_id=$EID" -H "$TOK"; echo

# --- missing target_kind -> 400 missing_field
curl -s -o /dev/null -w "missing target_kind -> %{http_code}\n" \
    "$H/v1/attribute-check?target_id=$EID" -H "$TOK"

# --- POST not allowed -> 405
curl -s -o /dev/null -w "POST -> %{http_code}\n" -X POST "$H/v1/attribute-check?target_kind=episode_object&target_id=$EID" -H "$TOK"

# --- cleanup ---
curl -s -X DELETE "$H/v1/episodes/$EID" -H "$TOK"; echo
