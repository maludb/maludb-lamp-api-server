# Regression curl commands — /v1/pools/{id}/archive
# Set POOL_ID to an existing, NOT-yet-archived pool id first.
#   export POOL_ID=8
POOL_ID="${POOL_ID:-1}"

# --- POST archive -> 200, pool with lifecycle_state='archived' and archived_at set
curl -s -X POST "https://fastapi.maludb.org/v1/pools/$POOL_ID/archive" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST archive again -> 409 already_archived
curl -s -X POST "https://fastapi.maludb.org/v1/pools/$POOL_ID/archive" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- archive a missing pool -> 404 not_found
curl -s -X POST 'https://fastapi.maludb.org/v1/pools/999999/archive' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET (unsupported) -> 405 method_not_allowed
curl -s -X GET "https://fastapi.maludb.org/v1/pools/$POOL_ID/archive" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
