# Regression curl commands — GET /v1/skills/{id}/bundle  (agent-skill pull, maludb_core 0.97.0)
# Read-only. Register a bundle first via tests/skills_ingest_curls.sh and reuse its $SID.

# --- GET bundle -> 200 {"skill":{id,name,description,markdown,version,visibility,enabled,
#                                 bundle_hash,frontmatter_jsonb,source_owner_schema,source_skill_id,created_at},
#                        "files":[{relative_path,file_hash,file_size,is_executable,media_type,content_base64}]}
#     Files are ordered by relative_path; the client verifies each file_hash (sha256 of the
#     decoded bytes) and the recomputed canonical bundle hash against skill.bundle_hash.
curl -s -X GET "https://fastapi.maludb.org/v1/skills/$SID/bundle" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- Decode one file locally (sanity round-trip of content_base64)
curl -s -X GET "https://fastapi.maludb.org/v1/skills/$SID/bundle" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json' \
    | python3 -c 'import sys,json,base64,hashlib
b = json.load(sys.stdin)
for f in b["files"]:
    raw = base64.b64decode(f["content_base64"])
    assert hashlib.sha256(raw).hexdigest() == f["file_hash"], f["relative_path"]
    print(f["relative_path"], f["file_size"], "ok")'

# --- GET bundle of a legacy markdown-only skill (no bundle rows) -> 200 with ONE synthesized
#     SKILL.md file built from the markdown column (bundle_hash null on the skill).
#     Create one via POST /v1/skills {"name","markdown"} and use its id here.
curl -s -X GET 'https://fastapi.maludb.org/v1/skills/1/bundle' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET unknown id -> 404 not_found ; POST -> 405 method_not_allowed ; no token -> 401
curl -s -X GET 'https://fastapi.maludb.org/v1/skills/999999/bundle' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X POST "https://fastapi.maludb.org/v1/skills/$SID/bundle" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X GET "https://fastapi.maludb.org/v1/skills/$SID/bundle" -H 'Accept: application/json'
