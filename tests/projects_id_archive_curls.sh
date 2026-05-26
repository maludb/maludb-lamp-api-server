# Regression curl commands — /v1/projects/{id}/archive
# Deferred (501): no archive column exists on the subject/project schema
# (see docs/db-requirements.md).

# --- POST archive -> 501 not_implemented
curl -s -X POST 'https://fastapi.maludb.org/v1/projects/11/archive' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET (unsupported) -> 405 ; POST no token -> 401
curl -s -X GET 'https://fastapi.maludb.org/v1/projects/11/archive' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X POST 'https://fastapi.maludb.org/v1/projects/11/archive' -H 'Accept: application/json'
