# Regression curl commands — /v1/pools
# NOTE: v1 has no pool DELETE, and the API user can't hard-delete pools. The create
# command below leaves a real pool in the DB (purge via SQL/DBA if needed).

# --- GET list -> 200 {"pools":[ {id,name,description,lifecycle_state,archived_at,created_at} ]}
#     (excludes tombstoned pools)
curl -s -X GET 'https://fastapi.maludb.org/v1/pools' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET search (q) -> 200, only matching pools
curl -s -X GET 'https://fastapi.maludb.org/v1/pools?q=test' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST missing name -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/pools' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- GET no token -> 401 ; PATCH collection -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/pools' -H 'Accept: application/json'
curl -s -X PATCH 'https://fastapi.maludb.org/v1/pools' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST create -> 201 {"pool":{"id":...}}   *** leaves a permanent pool (no API delete) ***
curl -s -X POST 'https://fastapi.maludb.org/v1/pools' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"my-pool","description":"what this pool is for"}'
