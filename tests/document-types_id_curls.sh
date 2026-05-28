# Regression curl commands — /v1/document-types/{id}   (self-cleaning lifecycle)

# --- PATCH missing id -> 404 not_found
curl -s -X PATCH 'https://fastapi.maludb.org/v1/document-types/999999' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"description":"x"}'

# --- DELETE missing id -> 404 not_found
curl -s -X DELETE 'https://fastapi.maludb.org/v1/document-types/999999' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- Lifecycle: create -> PATCH -> 422 dupe -> DELETE -> 404 -----------------
DTID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/document-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"document_type":"Lifecycle Type","description":"d","display_order":50}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created document_type id=$DTID"

# PATCH label + description + display_order -> 200, returns updated row
curl -s -X PATCH "https://fastapi.maludb.org/v1/document-types/$DTID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"document_type":"Lifecycle Type Renamed","description":"updated","display_order":51}'

# PATCH no fields -> 400 bad_request
curl -s -X PATCH "https://fastapi.maludb.org/v1/document-types/$DTID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# PATCH renaming to a SEEDED label (case-insensitive) -> 409 conflict
curl -s -X PATCH "https://fastapi.maludb.org/v1/document-types/$DTID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"document_type":"EMAIL"}'

# DELETE -> 200 ; PATCH again -> 404
curl -s -X DELETE "https://fastapi.maludb.org/v1/document-types/$DTID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X PATCH "https://fastapi.maludb.org/v1/document-types/$DTID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"description":"x"}'
