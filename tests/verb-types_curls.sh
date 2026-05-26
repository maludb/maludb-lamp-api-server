# Regression curl commands — /v1/verb-types   (read-only; safe to paste)

# --- GET -> 200 {"verb_types":[ {type,display_name,semantic_class,description,sort_order} ... ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/verb-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET no token -> 401 {"error":{"code":"auth_missing", ...}}
curl -s -X GET 'https://fastapi.maludb.org/v1/verb-types' \
    -H 'Accept: application/json'

# --- POST (unsupported) -> 405 {"error":{"code":"method_not_allowed", ...}}
curl -s -X POST 'https://fastapi.maludb.org/v1/verb-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
