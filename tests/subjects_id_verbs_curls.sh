# Regression curl commands — /v1/subjects/{id}/verbs
# Link a verb to a subject (creates a vector compartment via maludb_subject_verb_link).
# Read-only/validation blocks are safe; the link block self-cleans via DELETE.

# --- GET linked verbs -> 200 {"verbs":[ ... {id,canonical_name,type} ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/17/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET for a missing subject -> 404 not_found
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/999999/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST missing verb_id -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/17/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- POST nonexistent verb_id -> 422 validation_failed
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/17/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"verb_id":999999}'

# --- POST to a missing subject -> 404
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/999999/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"verb_id":5}'

# --- PATCH (unsupported) -> 405 ; GET no token -> 401
curl -s -X PATCH 'https://fastapi.maludb.org/v1/subjects/17/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/17/verbs' -H 'Accept: application/json'

# --- POST link (self-cleaning: link verb 5 to subject 17, dup 409, then unlink) ----
# 1) link -> 201 {"verb":{"id":5,...},"compartment_id":...}
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/17/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"verb_id":5}'
# 2) duplicate -> 409 conflict
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/17/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"verb_id":5}'
# 3) clean up -> 200
curl -s -X DELETE 'https://fastapi.maludb.org/v1/subjects/17/verbs/5' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
