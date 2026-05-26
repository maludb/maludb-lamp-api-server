# Regression curl commands — /v1/projects/{id}/unarchive
# Deferred (501): no archive column exists on the subject/project schema
# (see docs/db-requirements.md).

# --- POST unarchive -> 501 not_implemented
curl -s -X POST 'https://fastapi.maludb.org/v1/projects/11/unarchive' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET (unsupported) -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/projects/11/unarchive' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
