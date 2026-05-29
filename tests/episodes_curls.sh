# Regression curl commands — /v1/episodes   (maludb_core 0.82.0 — first-class events)
# GET list + POST create are supported; the create block self-cleans via DELETE.

# --- GET list -> 200 {"episodes":[ {id,kind,title,summary,payload,occurred_at,...,provenance} ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET review queue: ?provenance=suggested -> 200 (machine-derived, awaiting accept/reject)
curl -s -X GET 'https://fastapi.maludb.org/v1/episodes?provenance=suggested&limit=20' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST no token -> 401
curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' -H 'Accept: application/json'

# --- POST missing title -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- POST invalid sensitivity -> 422 (DB check: public|internal|restricted|prohibited)
curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"x","sensitivity":"bogus"}'

# --- POST invalid provenance -> 422 (DB check: provided|suggested|accepted|rejected)
curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"x","provenance":"bogus"}'

# --- POST create (provenance=provided) + POST create (provenance=suggested), then self-clean ---
EID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"Sprint 7 standup","kind":"Daily Standup","summary":"daily sync","payload":{"sprint":7},"occurred_at":"2026-05-29T09:00:00Z","provenance":"provided"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created episode id=$EID (provided)"

SID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"Possible incident","kind":"Incident","provenance":"suggested"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created episode id=$SID (suggested)"

curl -s -X DELETE "https://fastapi.maludb.org/v1/episodes/$EID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
curl -s -X DELETE "https://fastapi.maludb.org/v1/episodes/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
