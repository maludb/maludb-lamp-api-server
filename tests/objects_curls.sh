# Regression curl commands — /v1/objects/{kind}   (object-with-attributes ergonomics)
# POST creates an object AND applies its typed attributes in ONE transaction, then returns
# maludb_object_get(kind,id) = {kind, id, object, attributes, [statements, details]}.
# Supported kinds: subject, episode_object.
# NOTE: deleting an episode/subject does NOT cascade its attributes, so cleanup deletes
# the attributes first (looked up by target), then the object.

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
H='https://fastapi.maludb.org'

# --- POST atomic episode + 2 attributes -> 201 {"object":{kind,id,object,attributes,...}}
EID=$(curl -s -X POST "$H/v1/objects/episode_object" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"title":"Objects test meeting","kind":"Meeting","summary":"s","attributes":[{"attr_name":"duration_minutes","value_numeric":45},{"attr_name":"room","value_text":"B12"}]}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created episode handle id=$EID"

# --- GET the handle back -> 200
curl -s "$H/v1/objects/episode_object/$EID" -H "$TOK"; echo

# --- the same object now shows up with its attributes on the list (?with=attributes) -> 200
curl -s "$H/v1/episodes?with=attributes&q=Objects%20test%20meeting&limit=1" -H "$TOK"; echo

# --- POST unsupported kind -> 422 validation_failed
curl -s -X POST "$H/v1/objects/verb" -H "$TOK" -H 'Content-Type: application/json' -d '{"x":1}'; echo

# --- POST episode with no title -> 400 missing_field
curl -s -X POST "$H/v1/objects/episode_object" -H "$TOK" -H 'Content-Type: application/json' -d '{}'; echo

# --- POST bad attributes shape (not an array) -> 422 validation_failed
curl -s -X POST "$H/v1/objects/episode_object" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"title":"x","attributes":{"a":1}}'; echo

# --- cleanup: delete the episode's attributes (no cascade), then the episode ---
for AID in $(curl -s "$H/v1/attributes?target_kind=episode_object&target_id=$EID" -H "$TOK" | grep -o '"id":[0-9]*' | grep -o '[0-9]*'); do
    curl -s -o /dev/null -X DELETE "$H/v1/attributes/$AID" -H "$TOK"
done
curl -s -X DELETE "$H/v1/episodes/$EID" -H "$TOK"; echo
