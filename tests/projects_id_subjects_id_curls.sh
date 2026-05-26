# Regression curl commands — /v1/projects/{id}/subjects/{sid}
# Deferred (501) — see docs/db-requirements.md.

# --- DELETE unlink subject -> 501 not_implemented
curl -s -X DELETE 'https://fastapi.maludb.org/v1/projects/11/subjects/9' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET (unsupported) -> 405 ; DELETE no token -> 401
curl -s -X GET 'https://fastapi.maludb.org/v1/projects/11/subjects/9' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X DELETE 'https://fastapi.maludb.org/v1/projects/11/subjects/9' -H 'Accept: application/json'
