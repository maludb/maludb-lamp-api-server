# Regression curl commands — /v1/episodes   (POST only, §4.9)
# NOTE: episodes are an append-only log — there is no v1 DELETE, and the API user can't
# remove them via the API. The create command below leaves a real episode (purge via SQL
# with `SET search_path TO public, maludb_core` if needed).

# --- POST no token -> 401
curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' -H 'Accept: application/json'

# --- GET (unsupported) -> 405 method_not_allowed
curl -s -X GET 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST missing title -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- POST invalid sensitivity -> 422 (DB check: public|internal|restricted|prohibited)
curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"x","sensitivity":"bogus"}'

# --- POST create (default kind 'activity') -> 201 {"episode":{...}}  *** persists ***
curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"Investigate the API","summary":"started work","payload":{"ticket":"ABC-1"}}'

# --- POST with explicit kind + occurred_at -> 201  *** persists ***
curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"Observed event","kind":"observation","occurred_at":"2026-05-26T10:00:00Z"}'
