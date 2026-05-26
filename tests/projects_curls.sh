# Regression curl commands — /v1/projects   (a project is a subject of type 'project')
# Read-only blocks safe; the create block self-cleans via DELETE /v1/projects/{id}.

# --- GET list -> 200 {"projects":[ {id,name,description,classifier_md} ... ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/projects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET search (q) -> 200, only matching projects
curl -s -X GET 'https://fastapi.maludb.org/v1/projects?q=zoz' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST missing name -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/projects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- GET no token -> 401 ; PATCH collection -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/projects' -H 'Accept: application/json'
curl -s -X PATCH 'https://fastapi.maludb.org/v1/projects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST create (self-cleaning: create then delete the throwaway project) ---
PID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/projects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"project-create-test","description":"temp"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created project id=$PID"
curl -s -X DELETE "https://fastapi.maludb.org/v1/projects/$PID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
