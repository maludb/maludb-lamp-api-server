# Regression curl commands — /v1/projects/{id}/verbs/{vid}
# Deferred (501) — see docs/db-requirements.md.

# --- DELETE unlink verb -> 501 not_implemented
curl -s -X DELETE 'https://fastapi.maludb.org/v1/projects/11/verbs/5' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET (unsupported) -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/projects/11/verbs/5' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
