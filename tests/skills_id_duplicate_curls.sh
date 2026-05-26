# Regression curl commands — /v1/skills/{id}/duplicate
# Forking is DB-gated: only published/forkable skills can be duplicated. An ordinary
# private skill returns 422. Self-cleaning: creates a skill, tries duplicate, deletes it.

# --- duplicate a missing skill -> 404 not_found
curl -s -X POST 'https://fastapi.maludb.org/v1/skills/999999/duplicate' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- create a (non-forkable) private skill, then exercise duplicate ----------
SID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"skill-dup-test","description":"d","visibility":"private"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created skill id=$SID"

# duplicate a non-forkable private skill -> 422 validation_failed
curl -s -X POST "https://fastapi.maludb.org/v1/skills/$SID/duplicate" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"skill-dup-copy"}'

# GET on the duplicate URL (unsupported) -> 405
curl -s -X GET "https://fastapi.maludb.org/v1/skills/$SID/duplicate" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# clean up the test skill
curl -s -X DELETE "https://fastapi.maludb.org/v1/skills/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# NOTE: a successful 201 duplicate requires a forkable (published) source skill.
