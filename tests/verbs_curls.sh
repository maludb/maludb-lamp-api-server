# Regression curl commands — /v1/verbs   (run from your desktop)
# Each block is standalone; copy/paste individually. Comment above = expected result.
# Token below is the dev token (device_name 'claude-dev').
#
# Verbs expose canonical_name (not "label"); verb_type must be a registered value
# (see /v1/verb-types), otherwise the DB trigger rejects it -> 422.

# --- GET list -> 200, JSON {"verbs":[ ... canonical_name, type, linked_subjects ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET search (q) -> 200, only matching verbs
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs?q=inst' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET search no match -> 200, {"verbs":[]}
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs?q=zzz_no_match_zzz' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET with limit -> 200, at most 1 verb
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs?limit=1' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET with debug -> 200 (meta.debug block only if server has MALUDB_DEBUG=1)
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs?limit=1&debug=1' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET no token -> 401 {"error":{"code":"auth_missing", ...}}
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs' \
    -H 'Accept: application/json'

# --- GET bad token -> 401 {"error":{"code":"auth_invalid", ...}}
curl -s -X GET 'https://fastapi.maludb.org/v1/verbs' \
    -H 'Authorization: Bearer malu_wrongwrongwrongwrongwrongwrongwrongwrong00' \
    -H 'Accept: application/json'

# --- POST missing canonical_name -> 400 {"error":{"code":"missing_field", ...}}
curl -s -X POST 'https://fastapi.maludb.org/v1/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{"type":"created"}'

# --- POST unknown verb_type -> 422 {"error":{"code":"validation_failed", ...}}
#     (the DB trigger only accepts types registered in /v1/verb-types)
curl -s -X POST 'https://fastapi.maludb.org/v1/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{"canonical_name":"bad-type-verb","type":"not_a_real_type"}'

# --- POST malformed JSON -> 400 {"error":{"code":"body_invalid_json", ...}}
curl -s -X POST 'https://fastapi.maludb.org/v1/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{bad json'

# --- PATCH collection -> 405 {"error":{"code":"method_not_allowed", ...}}
curl -s -X PATCH 'https://fastapi.maludb.org/v1/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST create (self-cleaning: creates then deletes the throwaway verb) ---
#     1) create -> 201 {"verb":{ "id":..., "canonical_name":"verb-create-test", ... }}
VID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"canonical_name":"verb-create-test","type":"created","description":"temp"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created verb id=$VID"
#     2) delete it again -> 200 (uses /v1/verbs/{id})
curl -s -X DELETE "https://fastapi.maludb.org/v1/verbs/$VID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
