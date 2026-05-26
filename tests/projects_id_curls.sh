# Regression curl commands — /v1/projects/{id}
# Read-only blocks safe; the mutating lifecycle self-cleans. Project 11 = Zozocal.

# --- GET detail -> 200 {"project":{ ..., "subjects":[...], "verbs":[...] }}
curl -s -X GET 'https://fastapi.maludb.org/v1/projects/11' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET a non-project subject id -> 404 (scoped to subject_type='project'; 9 is a person)
curl -s -X GET 'https://fastapi.maludb.org/v1/projects/9' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- Mutating lifecycle (create throwaway -> PATCH -> DELETE) ----------------
PID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/projects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"project-detail-test","description":"temp"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created project id=$PID"

# PATCH -> 200, returns updated detail
curl -s -X PATCH "https://fastapi.maludb.org/v1/projects/$PID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"project-detail-renamed","description":"patched"}'

# PATCH no fields -> 400 ; POST on detail -> 405
curl -s -X PATCH "https://fastapi.maludb.org/v1/projects/$PID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'
curl -s -X POST "https://fastapi.maludb.org/v1/projects/$PID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# DELETE -> 200 ; GET again -> 404
curl -s -X DELETE "https://fastapi.maludb.org/v1/projects/$PID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X GET "https://fastapi.maludb.org/v1/projects/$PID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
