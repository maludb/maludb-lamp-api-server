# Regression curl commands — /v1/projects/{id}/verbs
# POST links a verb to a project (SVPOR 'has_member' edge via maludb_svpor_relationship_create).
# PUT (replace) and DELETE stay 501 until the SVPOR delete helper lands (db-requirements §1).
# *** A successful POST creates a permanent link — there is no API delete yet. ***
# Linked verbs are readable via GET /v1/projects/{id}.

# --- POST to a missing project -> 404
curl -s -X POST 'https://fastapi.maludb.org/v1/projects/999999/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' --data-raw '{"verb_id":5}'

# --- POST missing field -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/projects/11/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- POST nonexistent verb -> 422 validation_failed
curl -s -X POST 'https://fastapi.maludb.org/v1/projects/11/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' --data-raw '{"verb_id":999999}'

# --- PUT (replace set) -> 501 not_implemented (needs SVPOR delete helper)
curl -s -X PUT 'https://fastapi.maludb.org/v1/projects/11/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' --data-raw '{"verb_ids":[5]}'

# --- GET (unsupported) -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/projects/11/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST link a verb -> 201 {"verb":{...},"edge_id":...}   *** PERMANENT (no API delete) ***
#     A repeat of the same link -> 409 conflict.
curl -s -X POST 'https://fastapi.maludb.org/v1/projects/11/verbs' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' --data-raw '{"verb_id":5}'
