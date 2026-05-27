# Regression curl commands — /v1/projects/{id}/archive
# Self-cleaning throwaway project.
A='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'

PID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/projects' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"proj-archive-test"}' | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "project id=$PID"

# --- POST archive -> 200 {"project":{...,"archived_at":"..."}}
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/archive" -H "$A" -H 'Accept: application/json'

# --- POST archive again -> 409 already_archived
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID/archive" -H "$A" -H 'Accept: application/json'

# --- archive a missing project -> 404 ; GET (unsupported) -> 405
curl -s -X POST 'https://fastapi.maludb.org/v1/projects/999999/archive' -H "$A" -H 'Accept: application/json'
curl -s -X GET "https://fastapi.maludb.org/v1/projects/$PID/archive" -H "$A" -H 'Accept: application/json'

# clean up (unarchive then delete)
curl -s -o /dev/null -X POST "https://fastapi.maludb.org/v1/projects/$PID/unarchive" -H "$A"
curl -s -o /dev/null -X DELETE "https://fastapi.maludb.org/v1/projects/$PID" -H "$A"
echo "cleaned up project $PID"
