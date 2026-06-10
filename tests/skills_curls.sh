# Regression curl commands — /v1/skills
# Read-only/validation blocks are safe; the create block self-cleans via DELETE.

# --- GET list -> 200 {"skills":[ {id,name,description,version,visibility,packaging_kind,enabled,...} ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET with visibility filter -> 200
curl -s -X GET 'https://fastapi.maludb.org/v1/skills?visibility=private' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET tag-aware discovery (0.97.0): ?subject= / ?verb= route through maludb_skill_search
#     -> 200 {"skills":[ {owner_schema,id,name,description,version,visibility,subjects,verbs,
#                         keywords,score,match_reasons,is_public,is_forkable,
#                         source_owner_schema,source_skill_id,updated_at} ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/skills?subject=pdf%20file' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X GET 'https://fastapi.maludb.org/v1/skills?verb=extract' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET combined q + subject (q feeds the keyword/tsquery rails of the same search) -> 200
curl -s -X GET 'https://fastapi.maludb.org/v1/skills?q=pdf&subject=pdf%20file&limit=10' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST missing name -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}'

# --- POST invalid visibility -> 422 (DB check: private|shared|public)
curl -s -X POST 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"bad-skill","visibility":"bogus"}'

# --- GET no token -> 401 ; PATCH collection -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/skills' -H 'Accept: application/json'
curl -s -X PATCH 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST create (self-cleaning: create then DELETE) -------------------------
SID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/skills' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"skill-create-test","description":"d","markdown":"# Title\n\nbody","visibility":"private","packaging_kind":"markdown"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created skill id=$SID"
curl -s -X DELETE "https://fastapi.maludb.org/v1/skills/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
