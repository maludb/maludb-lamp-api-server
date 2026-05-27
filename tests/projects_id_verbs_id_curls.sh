# Regression curl commands — /v1/projects/{id}/verbs/{vid}
# DELETE unlinks a verb (SVPOR delete). Self-cleaning throwaway project.
A='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'

PID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/projects' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"proj-verb-del-test"}' | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "project id=$PID"

# set up a link to delete
curl -s -o /dev/null -X POST "https://fastapi.maludb.org/v1/projects/$PID/verbs" -H "$A" -H 'Content-Type: application/json' --data-raw '{"verb_id":5}'

# --- DELETE link -> 200 ; DELETE again -> 404 ; GET -> 405
curl -s -X DELETE "https://fastapi.maludb.org/v1/projects/$PID/verbs/5" -H "$A" -H 'Accept: application/json'
curl -s -X DELETE "https://fastapi.maludb.org/v1/projects/$PID/verbs/5" -H "$A" -H 'Accept: application/json'
curl -s -X GET "https://fastapi.maludb.org/v1/projects/$PID/verbs/5" -H "$A" -H 'Accept: application/json'

# clean up
curl -s -o /dev/null -X DELETE "https://fastapi.maludb.org/v1/projects/$PID" -H "$A"
echo "cleaned up project $PID"
