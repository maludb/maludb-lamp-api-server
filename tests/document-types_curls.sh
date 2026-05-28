# Regression curl commands — /v1/document-types  (maludb_core 0.81.0 picker list)
# Read-only/validation blocks are safe; the create block self-cleans via DELETE.

# --- GET list -> 200 {"document_types":[ {id,document_type,description,display_order,created_at} ]}
# Seeded on schema enable: Meeting Notes, Meeting Transcript, Email, Report, ...
curl -s -X GET 'https://fastapi.maludb.org/v1/document-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST missing document_type -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/document-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- POST bad display_order (not an integer) -> 422 validation_failed
curl -s -X POST 'https://fastapi.maludb.org/v1/document-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"document_type":"X","display_order":"high"}'

# --- POST duplicate of a SEEDED label, different case -> 409 conflict
# Uniqueness is case-insensitive on lower(document_type): "report" collides with seeded "Report".
curl -s -X POST 'https://fastapi.maludb.org/v1/document-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"document_type":"report"}'

# --- GET no token -> 401 ; PATCH collection -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/document-types' -H 'Accept: application/json'
curl -s -X PATCH 'https://fastapi.maludb.org/v1/document-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST create (self-cleaning: create then DELETE) -------------------------
DTID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/document-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"document_type":"Test Picker Type","description":"temp","display_order":99}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created document_type id=$DTID"

# --- POST the SAME label again (different case) -> 409 conflict
curl -s -X POST 'https://fastapi.maludb.org/v1/document-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"document_type":"test picker TYPE"}'

curl -s -X DELETE "https://fastapi.maludb.org/v1/document-types/$DTID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
