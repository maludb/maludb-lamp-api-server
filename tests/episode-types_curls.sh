# Regression curl commands — /v1/episode-types  (0.82.0 event-kind picker list)
# Read-only/validation blocks are safe; the create block self-cleans via DELETE.

# --- GET list -> 200 {"episode_types":[ {id,episode_type,description,display_order,created_at} ]}
# Seeded: Meeting, Daily Standup, Review, Retrospective, 1:1, Incident, Planning.
curl -s -X GET 'https://fastapi.maludb.org/v1/episode-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST missing episode_type -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/episode-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- POST bad display_order (not an integer) -> 422 validation_failed
curl -s -X POST 'https://fastapi.maludb.org/v1/episode-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"episode_type":"X","display_order":"high"}'

# --- POST duplicate of a SEEDED label, different case -> 409 conflict ("meeting" vs "Meeting")
curl -s -X POST 'https://fastapi.maludb.org/v1/episode-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"episode_type":"meeting"}'

# --- GET no token -> 401 ; PATCH collection -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/episode-types' -H 'Accept: application/json'
curl -s -X PATCH 'https://fastapi.maludb.org/v1/episode-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST create (self-cleaning: create then DELETE) -------------------------
ETID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/episode-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"episode_type":"Test Event Type","description":"temp","display_order":99}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created episode_type id=$ETID"

# --- POST the SAME label again (different case) -> 409 conflict
curl -s -X POST 'https://fastapi.maludb.org/v1/episode-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"episode_type":"test event TYPE"}'

curl -s -X DELETE "https://fastapi.maludb.org/v1/episode-types/$ETID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
