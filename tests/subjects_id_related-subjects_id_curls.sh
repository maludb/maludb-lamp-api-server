# Regression curl commands — /v1/subjects/{id}/related-subjects/{otherId}
# Self-cleaning: sets up a link, deletes it, confirms it's gone. Uses subjects 9 & 11.

# 1. Set up a link 9->11 (so there's something to delete) -> 201
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/9/related-subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"related_subject_id":11}'

# 2. DELETE the relationship (either direction) -> 200 {"deleted":true,"removed":1}
curl -s -X DELETE 'https://fastapi.maludb.org/v1/subjects/9/related-subjects/11' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# 3. DELETE again -> 404 not_found (nothing left)
curl -s -X DELETE 'https://fastapi.maludb.org/v1/subjects/9/related-subjects/11' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET (unsupported) -> 405 method_not_allowed
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/9/related-subjects/11' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- DELETE no token -> 401
curl -s -X DELETE 'https://fastapi.maludb.org/v1/subjects/9/related-subjects/11' \
    -H 'Accept: application/json'
