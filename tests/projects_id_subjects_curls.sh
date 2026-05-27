# Regression curl commands — /v1/projects/{id}/subjects
# POST links a subject; PUT replaces the set (SVPOR 'has_member' edges).
# Self-cleaning: creates a throwaway project, exercises it, deletes it.
A='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'

PID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/projects' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"proj-subjects-test"}' | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "project id=$PID"

# --- POST missing field -> 400 ; self-link -> 422 ; nonexistent -> 422
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/subjects" -H "$A" -H 'Content-Type: application/json' -d '{}'
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/subjects" -H "$A" -H 'Content-Type: application/json' --data-raw "{\"subject_id\":$PID}"
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/subjects" -H "$A" -H 'Content-Type: application/json' --data-raw '{"subject_id":999999}'

# --- POST link subject 9 -> 201 {"subject":{...},"edge_id":...} ; duplicate -> 409
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/subjects" -H "$A" -H 'Content-Type: application/json' --data-raw '{"subject_id":9}'
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/subjects" -H "$A" -H 'Content-Type: application/json' --data-raw '{"subject_id":9}'

# --- PUT replace set to [11] -> 200 {"subjects":[{"id":11,...}]}
curl -s -X PUT "https://fastapi.maludb.org/v1/projects/$PID/subjects" -H "$A" -H 'Content-Type: application/json' --data-raw '{"subject_ids":[11]}'

# --- GET (unsupported) -> 405
curl -s -X GET "https://fastapi.maludb.org/v1/projects/$PID/subjects" -H "$A" -H 'Accept: application/json'

# clean up
curl -s -o /dev/null -X DELETE "https://fastapi.maludb.org/v1/projects/$PID/subjects/11" -H "$A"
curl -s -o /dev/null -X DELETE "https://fastapi.maludb.org/v1/projects/$PID" -H "$A"
echo "cleaned up project $PID"
