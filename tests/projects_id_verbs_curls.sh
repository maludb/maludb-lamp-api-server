# Regression curl commands — /v1/projects/{id}/verbs
# POST links a verb; PUT replaces the set (SVPOR 'has_member' edges).
# Self-cleaning: creates a throwaway project, exercises it, deletes it.
A='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'

PID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/projects' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"proj-verbs-test"}' | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "project id=$PID"

# --- POST missing field -> 400 ; nonexistent verb -> 422
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/verbs" -H "$A" -H 'Content-Type: application/json' -d '{}'
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/verbs" -H "$A" -H 'Content-Type: application/json' --data-raw '{"verb_id":999999}'

# --- POST link verb 5 -> 201 ; duplicate -> 409
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/verbs" -H "$A" -H 'Content-Type: application/json' --data-raw '{"verb_id":5}'
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/verbs" -H "$A" -H 'Content-Type: application/json' --data-raw '{"verb_id":5}'

# --- PUT replace set to [5] -> 200 {"verbs":[{"id":5,...}]}
curl -s -X PUT "https://fastapi.maludb.org/v1/projects/$PID/verbs" -H "$A" -H 'Content-Type: application/json' --data-raw '{"verb_ids":[5]}'

# --- GET (unsupported) -> 405
curl -s -X GET "https://fastapi.maludb.org/v1/projects/$PID/verbs" -H "$A" -H 'Accept: application/json'

# clean up
curl -s -o /dev/null -X DELETE "https://fastapi.maludb.org/v1/projects/$PID/verbs/5" -H "$A"
curl -s -o /dev/null -X DELETE "https://fastapi.maludb.org/v1/projects/$PID" -H "$A"
echo "cleaned up project $PID"
