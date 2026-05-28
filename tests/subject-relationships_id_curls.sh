# Regression curl commands — /v1/subject-relationships/{relationship_id}
# Row-level GET/PATCH/DELETE on a subject<->subject relationship. Self-cleaning: creates
# a throwaway relationship via /v1/subjects/9/related-subjects, exercises it, deletes it.
A='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'

RID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/9/related-subjects' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"related_subject_id":11,"relationship_type":"depends_on","valid_from":"2026-01-01T00:00:00Z"}' \
    | grep -o '"relationship_id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "relationship_id=$RID"

# --- GET -> 200 {"relationship":{ id, from_subject_id/label, to_subject_id/label,
#                  relationship_type, label, valid_from, valid_to, created_at }}
curl -s -X GET "https://fastapi.maludb.org/v1/subject-relationships/$RID" -H "$A" -H 'Accept: application/json'

# --- GET missing -> 404
curl -s -X GET 'https://fastapi.maludb.org/v1/subject-relationships/999999' -H "$A" -H 'Accept: application/json'

# --- PATCH update type + label + valid_to -> 200
curl -s -X PATCH "https://fastapi.maludb.org/v1/subject-relationships/$RID" -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"relationship_type":"related_to","label":"experimental","valid_to":"2026-12-31T00:00:00Z"}'

# --- PATCH no fields -> 400 bad_request
curl -s -X PATCH "https://fastapi.maludb.org/v1/subject-relationships/$RID" -H "$A" -H 'Content-Type: application/json' -d '{}'

# --- PATCH valid_from > valid_to -> 422 (DB time-order check)
curl -s -X PATCH "https://fastapi.maludb.org/v1/subject-relationships/$RID" -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"valid_from":"2030-01-01T00:00:00Z","valid_to":"2026-01-01T00:00:00Z"}'

# --- PATCH clear valid_from / valid_to with null -> 200
curl -s -X PATCH "https://fastapi.maludb.org/v1/subject-relationships/$RID" -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"valid_from":null,"valid_to":null}'

# --- POST (unsupported) -> 405
curl -s -X POST "https://fastapi.maludb.org/v1/subject-relationships/$RID" -H "$A" -H 'Accept: application/json'

# --- DELETE -> 200 ; DELETE again -> 404
curl -s -X DELETE "https://fastapi.maludb.org/v1/subject-relationships/$RID" -H "$A" -H 'Accept: application/json'
curl -s -X DELETE "https://fastapi.maludb.org/v1/subject-relationships/$RID" -H "$A" -H 'Accept: application/json'
