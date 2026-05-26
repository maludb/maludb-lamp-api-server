# Regression curl commands — /v1/skills/{id}   (self-cleaning lifecycle)

# --- GET missing id -> 404 not_found
curl -s -X GET 'https://fastapi.maludb.org/v1/skills/999999' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- Lifecycle: create -> GET -> PATCH -> DELETE -> 404 ----------------------
SID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"skill-detail-test","description":"d","visibility":"private"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created skill id=$SID"

# GET detail -> 200
curl -s -X GET "https://fastapi.maludb.org/v1/skills/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# PATCH -> 200, returns updated skill
curl -s -X PATCH "https://fastapi.maludb.org/v1/skills/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"description":"patched","version":"1.1.0","enabled":false}'

# PATCH no fields -> 400 ; POST on detail -> 405
curl -s -X PATCH "https://fastapi.maludb.org/v1/skills/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'
curl -s -X POST "https://fastapi.maludb.org/v1/skills/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# DELETE -> 200 ; GET again -> 404
curl -s -X DELETE "https://fastapi.maludb.org/v1/skills/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X GET "https://fastapi.maludb.org/v1/skills/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
