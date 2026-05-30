# Regression curl commands — /v1/attributes/{id}   (0.83.0 typed-attribute layer)
#   GET    one attribute row
#   PATCH  {provenance} -> the review transition (suggested -> accepted | rejected)
#   DELETE remove the attribute
# Self-cleaning: creates a throwaway episode + attribute and deletes both.

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
H='https://fastapi.maludb.org'

EID=$(curl -s -X POST "$H/v1/episodes" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"title":"Attribute id test event","kind":"Meeting"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
AID=$(curl -s -X POST "$H/v1/attributes" -H "$TOK" -H 'Content-Type: application/json' \
    -d "{\"target_kind\":\"episode_object\",\"target_id\":$EID,\"attr_name\":\"sentiment\",\"value_text\":\"positive\",\"provenance\":\"suggested\"}" \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "episode=$EID attribute=$AID"

# --- GET the attribute -> 200 {"attribute":{...}}
curl -s "$H/v1/attributes/$AID" -H "$TOK"; echo

# --- PATCH provenance: suggested -> accepted -> 200 (the accept/reject review transition)
curl -s -X PATCH "$H/v1/attributes/$AID" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"provenance":"accepted"}'; echo

# --- PATCH with no provenance -> 400 bad_request (use POST to re-upsert values)
curl -s -X PATCH "$H/v1/attributes/$AID" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"value_text":"changed"}'; echo

# --- PATCH invalid provenance value -> 422 validation_failed
curl -s -X PATCH "$H/v1/attributes/$AID" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"provenance":"banana"}'; echo

# --- GET missing -> 404
curl -s -o /dev/null -w "GET missing -> %{http_code}\n" "$H/v1/attributes/888888888" -H "$TOK"

# --- DELETE -> 200, again -> 404
curl -s -X DELETE "$H/v1/attributes/$AID" -H "$TOK"; echo
curl -s -o /dev/null -w "DELETE again -> %{http_code}\n" -X DELETE "$H/v1/attributes/$AID" -H "$TOK"

# --- cleanup episode ---
curl -s -X DELETE "$H/v1/episodes/$EID" -H "$TOK"; echo
