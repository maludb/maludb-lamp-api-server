# Regression curl commands — /v1/subjects/{id}/verbs/{verbId}
# Unlink a verb from a subject (removes the vector compartment via maludb_subject_verb_unlink).
# Self-cleaning: links verb 5 to subject 17, deletes it, confirms it's gone.

# 1. Set up a link (so there's something to delete) -> 201
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/17/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"verb_id":5}'

# 2. DELETE the link -> 200 {"deleted":true,"id":17,"verb_id":5}
curl -s -X DELETE 'https://fastapi.maludb.org/v1/subjects/17/verbs/5' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# 3. DELETE again -> 404 not_found (no such link)
curl -s -X DELETE 'https://fastapi.maludb.org/v1/subjects/17/verbs/5' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET (unsupported) -> 405 ; DELETE no token -> 401
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/17/verbs/5' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X DELETE 'https://fastapi.maludb.org/v1/subjects/17/verbs/5' -H 'Accept: application/json'
