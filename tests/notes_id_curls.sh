# Regression curl commands — /v1/notes/{id}   (self-cleaning lifecycle)
A='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'

# --- GET missing id -> 404
curl -s -X GET 'https://fastapi.maludb.org/v1/notes/999999' -H "$A" -H 'Accept: application/json'

# --- Lifecycle: create -> GET -> PATCH -> DELETE -> 404 ---------------------
NID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/notes' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"title":"detail note","body":"v1","project_id":11}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created note id=$NID"

# GET detail -> 200
curl -s -X GET "https://fastapi.maludb.org/v1/notes/$NID" -H "$A" -H 'Accept: application/json'

# PATCH body + clear project_id -> 200
curl -s -X PATCH "https://fastapi.maludb.org/v1/notes/$NID" -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"body":"v2","project_id":null}'

# PATCH no fields -> 400
curl -s -X PATCH "https://fastapi.maludb.org/v1/notes/$NID" -H "$A" -H 'Content-Type: application/json' -d '{}'

# DELETE -> 200 ; GET again -> 404
curl -s -X DELETE "https://fastapi.maludb.org/v1/notes/$NID" -H "$A" -H 'Accept: application/json'
curl -s -X GET "https://fastapi.maludb.org/v1/notes/$NID" -H "$A" -H 'Accept: application/json'
