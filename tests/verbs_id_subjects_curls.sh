# Regression curl commands — /v1/verbs/{id}/subjects   (read-only; safe to paste)

# --- GET linked subjects -> 200 {"subjects":[ ... {id,label,type} ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs/5/subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET for a missing verb -> 404 {"error":{"code":"not_found", ...}}
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs/999999/subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST (unsupported) -> 405 {"error":{"code":"method_not_allowed", ...}}
curl -s -X POST 'https://fastapi.maludb.org/v1/verbs/5/subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET no token -> 401 {"error":{"code":"auth_missing", ...}}
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs/5/subjects' \
    -H 'Accept: application/json'
