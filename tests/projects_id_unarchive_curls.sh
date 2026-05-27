# Regression curl commands — /v1/projects/{id}/unarchive
# Self-cleaning throwaway project.
A='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'

PID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/projects' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"proj-unarchive-test"}' | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "project id=$PID"

# --- unarchive a not-archived project -> 409 not_archived
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/unarchive" -H "$A" -H 'Accept: application/json'

# set up: archive it first
curl -s -o /dev/null -X POST "https://fastapi.maludb.org/v1/projects/$PID/archive" -H "$A"

# --- POST unarchive -> 200 (archived_at cleared)
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/unarchive" -H "$A" -H 'Accept: application/json'

# --- unarchive again -> 409 ; GET (unsupported) -> 405
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/unarchive" -H "$A" -H 'Accept: application/json'
curl -s -X GET "https://fastapi.maludb.org/v1/projects/$PID/unarchive" -H "$A" -H 'Accept: application/json'

# clean up
curl -s -o /dev/null -X DELETE "https://fastapi.maludb.org/v1/projects/$PID" -H "$A"
echo "cleaned up project $PID"
