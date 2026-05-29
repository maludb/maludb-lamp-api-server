# Regression curl commands — /v1/episode-types/{id}   (self-cleaning lifecycle)

# --- PATCH missing id -> 404 not_found
curl -s -X PATCH 'https://fastapi.maludb.org/v1/episode-types/999999' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"description":"x"}'

# --- DELETE missing id -> 404 not_found
curl -s -X DELETE 'https://fastapi.maludb.org/v1/episode-types/999999' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- Lifecycle: create -> PATCH -> 400 no-fields -> 409 dupe -> DELETE -> 404 ---
ETID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/episode-types' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"episode_type":"Lifecycle Event","description":"d","display_order":50}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created episode_type id=$ETID"

# PATCH label + description + display_order -> 200, returns updated row
curl -s -X PATCH "https://fastapi.maludb.org/v1/episode-types/$ETID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"episode_type":"Lifecycle Event Renamed","description":"updated","display_order":51}'

# PATCH no fields -> 400 bad_request
curl -s -X PATCH "https://fastapi.maludb.org/v1/episode-types/$ETID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# PATCH renaming to a SEEDED label (case-insensitive) -> 409 conflict
curl -s -X PATCH "https://fastapi.maludb.org/v1/episode-types/$ETID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"episode_type":"REVIEW"}'

# DELETE -> 200 ; PATCH again -> 404
curl -s -X DELETE "https://fastapi.maludb.org/v1/episode-types/$ETID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
curl -s -X PATCH "https://fastapi.maludb.org/v1/episode-types/$ETID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"description":"x"}'
