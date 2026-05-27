# Regression curl commands — /v1/notes
# Notes are maludb_memory rows: title, body (summary), type (memory_kind), project_id (payload).
# Read-only/validation blocks are safe; the create block self-cleans via DELETE.
A='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'

# --- GET list -> 200 {"notes":[ {id,title,body,type,project_id,issue_closed_at,created_at} ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/notes' -H "$A" -H 'Accept: application/json'

# --- GET with filters -> 200
curl -s -X GET 'https://fastapi.maludb.org/v1/notes?type=issue&q=bug&limit=10' -H "$A" -H 'Accept: application/json'

# --- POST missing title -> 400 ; bad project_id -> 422
curl -s -X POST 'https://fastapi.maludb.org/v1/notes' -H "$A" -H 'Content-Type: application/json' -d '{}'
curl -s -X POST 'https://fastapi.maludb.org/v1/notes' -H "$A" -H 'Content-Type: application/json' --data-raw '{"title":"x","project_id":999999}'

# --- GET no token -> 401 ; PATCH collection -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/notes' -H 'Accept: application/json'
curl -s -X PATCH 'https://fastapi.maludb.org/v1/notes' -H "$A" -H 'Accept: application/json'

# --- POST create (self-cleaning) -> 201 {"note":{...}}
NID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/notes' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"title":"My note","body":"some text","project_id":11}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created note id=$NID"
curl -s -X DELETE "https://fastapi.maludb.org/v1/notes/$NID" -H "$A" -H 'Accept: application/json'
