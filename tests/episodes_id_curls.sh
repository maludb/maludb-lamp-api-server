# Regression curl commands — /v1/episodes/{id}   (self-cleaning lifecycle)
# GET assembles the full event via maludb_episode_get: {episode, statements[], details[]}.

# --- GET missing id -> 404 not_found
curl -s -X GET 'https://fastapi.maludb.org/v1/episodes/999999' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- Lifecycle: create (suggested) -> GET detail -> PATCH accept -> DELETE -> 404 ----
EID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"Detail event","kind":"Review","provenance":"suggested"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created episode id=$EID"

# GET detail -> 200 {"episode":{...},"statements":[...],"details":[...]}
curl -s -X GET "https://fastapi.maludb.org/v1/episodes/$EID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'

# PATCH no fields -> 400 bad_request
curl -s -X PATCH "https://fastapi.maludb.org/v1/episodes/$EID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# PATCH suggested -> accepted (the accept transition) + edit title -> 200
curl -s -X PATCH "https://fastapi.maludb.org/v1/episodes/$EID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"provenance":"accepted","title":"Detail event (confirmed)"}'

# DELETE -> 200 ; GET again -> 404
curl -s -X DELETE "https://fastapi.maludb.org/v1/episodes/$EID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
curl -s -X GET "https://fastapi.maludb.org/v1/episodes/$EID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
