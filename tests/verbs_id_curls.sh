# Regression curl commands — /v1/verbs/{id}   (run from your desktop)
# Read-only blocks are safe as-is; mutating ones are wrapped in a self-cleaning flow.

# --- GET detail -> 200 {"verb":{ ..., "subjects":[...] }}
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs/5' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET missing id -> 404 {"error":{"code":"not_found", ...}}
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs/999999' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- Mutating lifecycle (create a throwaway, PATCH it, DELETE it) -------------

# 1. Create a throwaway verb and capture its id
VID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"canonical_name":"verb-detail-test","type":"created","description":"temp"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created id=$VID"

# 2. PATCH update -> 200, returns updated detail
curl -s -X PATCH "https://fastapi.maludb.org/v1/verbs/$VID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"canonical_name":"verb-detail-renamed","type":"updated","description":"patched"}'

# 3. PATCH no fields -> 400 bad_request
curl -s -X PATCH "https://fastapi.maludb.org/v1/verbs/$VID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# 4. PATCH unknown type -> 422 validation_failed
curl -s -X PATCH "https://fastapi.maludb.org/v1/verbs/$VID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"type":"not_a_real_type"}'

# 5. POST on the detail URL -> 405 method_not_allowed
curl -s -X POST "https://fastapi.maludb.org/v1/verbs/$VID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# 6. DELETE -> 200 {"deleted":true,"id":...}
curl -s -X DELETE "https://fastapi.maludb.org/v1/verbs/$VID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# 7. GET it again -> 404 (confirms it's gone)
curl -s -X GET "https://fastapi.maludb.org/v1/verbs/$VID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
