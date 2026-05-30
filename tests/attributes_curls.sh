# Regression curl commands — /v1/attributes   (0.83.0 typed-attribute layer)
# A typed attribute is a property of (target_kind, target_id); target_kind is any node
# kind OR 'svpor_statement' (edge attributes). Create is an upsert on target+attr_name.
# Self-cleaning: creates a throwaway episode (+ statement for the edge case) and deletes all.

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
H='https://fastapi.maludb.org'

# Throwaway episode to attach attributes to (target_kind=episode_object):
EID=$(curl -s -X POST "$H/v1/episodes" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"title":"Attributes test event","kind":"Meeting"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created episode id=$EID"

# --- POST a node attribute (numeric, suggested) -> 201 {"attribute":{...}}
curl -s -X POST "$H/v1/attributes" -H "$TOK" -H 'Content-Type: application/json' \
    -d "{\"target_kind\":\"episode_object\",\"target_id\":$EID,\"attr_name\":\"headcount\",\"value_numeric\":12,\"unit\":\"people\",\"confidence\":0.8,\"provenance\":\"suggested\"}"
echo

# --- POST again with the same target+attr_name -> 201, SAME attribute id (idempotent upsert)
AID=$(curl -s -X POST "$H/v1/attributes" -H "$TOK" -H 'Content-Type: application/json' \
    -d "{\"target_kind\":\"episode_object\",\"target_id\":$EID,\"attr_name\":\"headcount\",\"value_numeric\":15}" \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "attribute id=$AID"

# --- GET filter by target -> 200 {"attributes":[...]}
curl -s "$H/v1/attributes?target_kind=episode_object&target_id=$EID" -H "$TOK"
echo

# --- GET review queue: ?provenance=suggested -> 200
curl -s "$H/v1/attributes?provenance=suggested&limit=20" -H "$TOK"
echo

# --- POST missing attr_name -> 400 missing_field
curl -s -X POST "$H/v1/attributes" -H "$TOK" -H 'Content-Type: application/json' \
    -d "{\"target_kind\":\"episode_object\",\"target_id\":$EID}"
echo

# --- POST non-numeric value_numeric -> 422 validation_failed
curl -s -X POST "$H/v1/attributes" -H "$TOK" -H 'Content-Type: application/json' \
    -d "{\"target_kind\":\"episode_object\",\"target_id\":$EID,\"attr_name\":\"z\",\"value_numeric\":\"abc\"}"
echo

# --- POST FK violation (target does not exist) -> 422 validation_failed
curl -s -X POST "$H/v1/attributes" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"target_kind":"episode_object","target_id":888888888,"attr_name":"x","value_text":"y"}'
echo

# --- EDGE attribute: attach a typed property to a statement (target_kind=svpor_statement) -> 201
STMT=$(curl -s -X POST "$H/v1/statements" -H "$TOK" -H 'Content-Type: application/json' \
    -d "{\"verb\":\"attended\",\"subject\":\"Regression Attendee\",\"object_kind\":\"episode_object\",\"object_id\":$EID}" \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
EATTR=$(curl -s -X POST "$H/v1/attributes" -H "$TOK" -H 'Content-Type: application/json' \
    -d "{\"target_kind\":\"svpor_statement\",\"target_id\":$STMT,\"attr_name\":\"role\",\"value_text\":\"chair\"}" \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "edge attribute id=$EATTR on statement id=$STMT"

# --- cleanup: delete attributes, statement, episode ---
curl -s -X DELETE "$H/v1/attributes/$AID" -H "$TOK"; echo
curl -s -X DELETE "$H/v1/attributes/$EATTR" -H "$TOK"; echo
curl -s -X DELETE "$H/v1/statements/$STMT" -H "$TOK"; echo
curl -s -X DELETE "$H/v1/episodes/$EID" -H "$TOK"; echo
