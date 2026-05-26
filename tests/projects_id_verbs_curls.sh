# Regression curl commands — /v1/projects/{id}/verbs
# Deferred (501) until the DBMS project adds a granted SVPOR-edge link function
# (see docs/db-requirements.md). Linked verbs are readable via GET /v1/projects/{id}.

# --- POST link a verb -> 501 not_implemented
curl -s -X POST 'https://fastapi.maludb.org/v1/projects/11/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"verb_id":5}'

# --- PUT replace verb set -> 501 not_implemented
curl -s -X PUT 'https://fastapi.maludb.org/v1/projects/11/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"verb_ids":[5]}'

# --- GET (unsupported) -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/projects/11/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
