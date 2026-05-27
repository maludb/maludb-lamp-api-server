# Regression curl commands — /v1/projects/{id}/subjects/{sid}
# DELETE unlinks a subject (SVPOR delete). Self-cleaning throwaway project.
A='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'

PID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/projects' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"proj-subj-del-test"}' | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "project id=$PID"

# set up a link to delete
curl -s -o /dev/null -X POST "https://fastapi.maludb.org/v1/projects/$PID/subjects" -H "$A" -H 'Content-Type: application/json' --data-raw '{"subject_id":9}'

# --- DELETE link -> 200 {"deleted":true,...}
curl -s -X DELETE "https://fastapi.maludb.org/v1/projects/$PID/subjects/9" -H "$A" -H 'Accept: application/json'

# --- DELETE again -> 404 not_found
curl -s -X DELETE "https://fastapi.maludb.org/v1/projects/$PID/subjects/9" -H "$A" -H 'Accept: application/json'

# --- GET (unsupported) -> 405
curl -s -X GET "https://fastapi.maludb.org/v1/projects/$PID/subjects/9" -H "$A" -H 'Accept: application/json'

# clean up
curl -s -o /dev/null -X DELETE "https://fastapi.maludb.org/v1/projects/$PID" -H "$A"
echo "cleaned up project $PID"
