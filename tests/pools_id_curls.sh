# Regression curl commands — /v1/pools/{id}
# Set POOL_ID to an existing pool id first (create one via POST /v1/pools).
#   export POOL_ID=8
POOL_ID="${POOL_ID:-1}"

# --- GET detail -> 200 {"pool":{ id,name,description,lifecycle_state,archived_at,... }}
curl -s -X GET "https://fastapi.maludb.org/v1/pools/$POOL_ID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET missing id -> 404 not_found
curl -s -X GET 'https://fastapi.maludb.org/v1/pools/999999' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- PATCH name/description -> 200, returns updated pool
curl -s -X PATCH "https://fastapi.maludb.org/v1/pools/$POOL_ID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"pool-renamed","description":"updated"}'

# --- PATCH no fields -> 400 bad_request
curl -s -X PATCH "https://fastapi.maludb.org/v1/pools/$POOL_ID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- DELETE (not supported in v1) -> 405 method_not_allowed
curl -s -X DELETE "https://fastapi.maludb.org/v1/pools/$POOL_ID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET no token -> 401
curl -s -X GET "https://fastapi.maludb.org/v1/pools/$POOL_ID" -H 'Accept: application/json'
