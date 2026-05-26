# Regression curl commands — /v1/subjects/{id}/verbs/{verbId}
# Unlink is deferred (501) until the DBMS project adds a granted compartment-delete
# function (see docs/db-requirements.md).

# --- DELETE unlink -> 501 {"error":{"code":"not_implemented", ...}}  (deferred)
curl -s -X DELETE 'https://fastapi.maludb.org/v1/subjects/9/verbs/5' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET (unsupported) -> 405 {"error":{"code":"method_not_allowed", ...}}
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/9/verbs/5' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- DELETE no token -> 401
curl -s -X DELETE 'https://fastapi.maludb.org/v1/subjects/9/verbs/5' \
    -H 'Accept: application/json'
