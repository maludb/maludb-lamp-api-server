# Regression curl commands — /v1/statements/{id}   (self-cleaning lifecycle)
# Exercises the suggested->accepted provenance transition and close (valid_to).

# --- GET missing id -> 404 not_found
curl -s -X GET 'https://fastapi.maludb.org/v1/statements/999999' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'

# Throwaway episode + statement (provenance=suggested, to test acceptance):
EID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"Statement-id test event","kind":"Meeting"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created episode id=$EID"

STMT=$(curl -s -X POST 'https://fastapi.maludb.org/v1/statements' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"verb\":\"attended\",\"subject\":\"Regression Attendee\",\"object_kind\":\"episode_object\",\"object_id\":$EID,\"provenance\":\"suggested\"}" \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created statement id=$STMT (suggested)"

# --- GET the statement -> 200 {"statement":{...,"provenance":"suggested"}}
curl -s -X GET "https://fastapi.maludb.org/v1/statements/$STMT" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'

# --- PATCH no fields -> 400 bad_request
curl -s -X PATCH "https://fastapi.maludb.org/v1/statements/$STMT" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- PATCH suggested -> accepted (the accept transition) -> 200
curl -s -X PATCH "https://fastapi.maludb.org/v1/statements/$STMT" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"provenance":"accepted"}'

# --- PATCH close (sets valid_to) -> 200 ; use close:true for now()
curl -s -X PATCH "https://fastapi.maludb.org/v1/statements/$STMT" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"close":true}'

# --- DELETE -> 200 ; GET again -> 404
curl -s -X DELETE "https://fastapi.maludb.org/v1/statements/$STMT" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
curl -s -X GET "https://fastapi.maludb.org/v1/statements/$STMT" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'

# --- cleanup episode ---
curl -s -X DELETE "https://fastapi.maludb.org/v1/episodes/$EID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
