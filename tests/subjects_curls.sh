# Regression curl commands — /v1/subjects   (run from your desktop)
# Each block is standalone; copy/paste individually. Comment above = expected result.
# Token below is the dev token (device_name 'claude-dev').

# --- GET list -> 200, JSON {"subjects":[ ... linked_verbs, related_subjects ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET search (q) -> 200, only matching subjects (e.g. Zozocal)
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects?q=zoz' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET search no match -> 200, {"subjects":[]}
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects?q=zzz_no_match_zzz' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET with limit -> 200, at most 1 subject
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects?limit=1' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET with debug -> 200 (meta.debug block only if server has MALUDB_DEBUG=1)
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects?limit=1&debug=1' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET no token -> 401 {"error":{"code":"auth_missing", ...}}
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects' \
    -H 'Accept: application/json'

# --- GET bad token -> 401 {"error":{"code":"auth_invalid", ...}}
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects' \
    -H 'Authorization: Bearer malu_wrongwrongwrongwrongwrongwrongwrongwrong00' \
    -H 'Accept: application/json'

# --- POST create -> 201 {"subject":{ "id": ..., "label":"Curl Test Subject", ... }}
#     NOTE: inserts a real row. No DELETE endpoint yet — remove later with:
#       psql ...  DELETE FROM maludb_subject WHERE canonical_name = 'Curl Test Subject';
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{"label":"Curl Test Subject","type":"project","description":"created via curl"}'

# --- POST missing label -> 400 {"error":{"code":"missing_field", ...}}
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{"type":"project"}'

# --- POST malformed JSON -> 400 {"error":{"code":"body_invalid_json", ...}}
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{bad json'

# --- PATCH collection -> 405 {"error":{"code":"method_not_allowed", ...}}
curl -s -X PATCH 'https://fastapi.maludb.org/v1/subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'


# ===========================================================================
#  /v1/subjects/{id}   (detail / update / delete)
# ===========================================================================

# --- GET detail -> 200 {"subject":{ ..., "verbs":[...], "related_subjects":[...] }}
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/9' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET missing id -> 404 {"error":{"code":"not_found", ...}}
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/999999' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- PATCH update -> 200, returns updated detail
#     MUTATES the row. Replace 18 with a throwaway subject id (see POST create above).
curl -s -X PATCH 'https://fastapi.maludb.org/v1/subjects/18' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{"label":"Renamed Subject","description":"updated via curl"}'

# --- PATCH no fields -> 400 {"error":{"code":"bad_request", ...}}
curl -s -X PATCH 'https://fastapi.maludb.org/v1/subjects/18' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{}'

# --- PATCH empty label -> 422 {"error":{"code":"validation_failed", ...}}
curl -s -X PATCH 'https://fastapi.maludb.org/v1/subjects/18' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{"label":"  "}'

# --- POST on detail URL -> 405 {"error":{"code":"method_not_allowed", ...}}
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/18' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- DELETE -> 200 {"deleted":true,"id":18}
#     DESTRUCTIVE. Replace 18 with a throwaway subject id.
curl -s -X DELETE 'https://fastapi.maludb.org/v1/subjects/18' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
