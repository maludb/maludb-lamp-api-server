# Regression curl commands — /v1/statements   (0.82.0 SVO statement layer)
# A statement is (subject_kind,subject_id) --verb--> (object_kind,object_id).
# Self-cleaning: creates a throwaway episode + statement and deletes both.
# NOTE: linking "by name" upserts a svpor subject ("Regression Attendee"); the same
# name is reused across the statement test files, so at most one such row persists
# (there is no public API to delete svpor subjects).

# Throwaway episode to attach statements to:
EID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"Statements test event","kind":"Meeting"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created episode id=$EID"

# --- POST general statement (verb + subject by name; object = the episode) -> 201
STMT=$(curl -s -X POST 'https://fastapi.maludb.org/v1/statements' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"verb\":\"attended\",\"subject\":\"Regression Attendee\",\"object_kind\":\"episode_object\",\"object_id\":$EID,\"confidence\":0.9}" \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created statement id=$STMT"

# --- GET filter by object -> 200 {"statements":[...]}
curl -s -X GET "https://fastapi.maludb.org/v1/statements?object_kind=episode_object&object_id=$EID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'

# --- GET review queue: ?provenance=suggested -> 200
curl -s -X GET 'https://fastapi.maludb.org/v1/statements?provenance=suggested&limit=20' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'

# --- POST missing verb -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/statements' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"subject\":\"Regression Attendee\",\"object_kind\":\"episode_object\",\"object_id\":$EID}"

# --- POST unknown verb -> 422 validation_failed
curl -s -X POST 'https://fastapi.maludb.org/v1/statements' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"verb\":\"not_a_real_verb\",\"subject\":\"Regression Attendee\",\"object_kind\":\"episode_object\",\"object_id\":$EID}"

# --- POST missing object_kind (general route requires it) -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/statements' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"verb":"attended","subject":"Regression Attendee"}'

# --- POST FK violation (object_id does not exist) -> 422 validation_failed
curl -s -X POST 'https://fastapi.maludb.org/v1/statements' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"verb":"attended","subject":"Regression Attendee","object_kind":"episode_object","object_id":888888888}'

# --- cleanup: delete the statement and the episode ---
curl -s -X DELETE "https://fastapi.maludb.org/v1/statements/$STMT" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
curl -s -X DELETE "https://fastapi.maludb.org/v1/episodes/$EID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
