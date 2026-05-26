# Regression curl commands — /v1/subjects/{id}/related-subjects
# Read-only blocks are safe; the POST block self-cleans by deleting what it created.
# Uses real subjects 9 (Edward Honour) and 11 (Zozocal).

# --- GET related subjects -> 200 {"related_subjects":[ ... ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/9/related-subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET for a missing subject -> 404
curl -s -X GET 'https://fastapi.maludb.org/v1/subjects/999999/related-subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST missing field -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/9/related-subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- POST relate to self -> 422 validation_failed
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/9/related-subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"related_subject_id":9}'

# --- POST nonexistent related subject -> 422 validation_failed
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/9/related-subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"related_subject_id":999999}'

# --- POST link (self-cleaning: create 9->11, then DELETE it again) -----------
# 1) link -> 201 {"related_subject":{"id":11,"label":"Zozocal","relationship_type":"related_to",...}}
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/9/related-subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"related_subject_id":11}'
# 2) duplicate -> 409 conflict
curl -s -X POST 'https://fastapi.maludb.org/v1/subjects/9/related-subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"related_subject_id":11}'
# 3) clean up -> 200
curl -s -X DELETE 'https://fastapi.maludb.org/v1/subjects/9/related-subjects/11' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- PATCH (unsupported) -> 405
curl -s -X PATCH 'https://fastapi.maludb.org/v1/subjects/9/related-subjects' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
