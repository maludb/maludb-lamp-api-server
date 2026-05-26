# Regression curl commands — /v1/skills
# Read-only/validation blocks are safe; the create block self-cleans via DELETE.

# --- GET list -> 200 {"skills":[ {id,name,description,version,visibility,packaging_kind,enabled,...} ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET with visibility filter -> 200
curl -s -X GET 'https://fastapi.maludb.org/v1/skills?visibility=private' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST missing name -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- POST invalid visibility -> 422 (DB check: private|shared|public)
curl -s -X POST 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"bad-skill","visibility":"bogus"}'

# --- GET no token -> 401 ; PATCH collection -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/skills' -H 'Accept: application/json'
curl -s -X PATCH 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST create (self-cleaning: create then DELETE) -------------------------
SID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"skill-create-test","description":"d","visibility":"private","packaging_kind":"markdown"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created skill id=$SID"
curl -s -X DELETE "https://fastapi.maludb.org/v1/skills/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
