# Regression curl commands — /v1/projects/{id}/subjects
# Deferred (501) until the DBMS project adds a granted SVPOR-edge link function
# (see docs/db-requirements.md). Linked subjects are readable via GET /v1/projects/{id}.

# --- POST link a subject -> 501 not_implemented
curl -s -X POST 'https://fastapi.maludb.org/v1/projects/11/subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"subject_id":9}'

# --- PUT replace subject set -> 501 not_implemented
curl -s -X PUT 'https://fastapi.maludb.org/v1/projects/11/subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"subject_ids":[9]}'

# --- GET (unsupported) -> 405 ; no token -> 401
curl -s -X GET 'https://fastapi.maludb.org/v1/projects/11/subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X POST 'https://fastapi.maludb.org/v1/projects/11/subjects' -H 'Accept: application/json'
