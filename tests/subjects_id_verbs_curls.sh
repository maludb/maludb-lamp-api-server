# Regression curl commands — /v1/subjects/{id}/verbs
# GET is read-only and works; POST is deferred (501) until the DBMS project adds
# a granted subject-verb link function (see docs/db-requirements.md).

# --- GET linked verbs -> 200 {"verbs":[ ... {id,canonical_name,type} ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/9/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET for a missing subject -> 404 {"error":{"code":"not_found", ...}}
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/999999/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST link a verb -> 501 {"error":{"code":"not_implemented", ...}}  (deferred)
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/9/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"verb_id":5}'

# --- PATCH (unsupported) -> 405 {"error":{"code":"method_not_allowed", ...}}
curl -s -X PATCH 'https://fastapi.maludb.org/v1/subjects/9/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET no token -> 401
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/9/verbs' \
    -H 'Accept: application/json'
