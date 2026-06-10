# Regression curl commands — POST /v1/skills/ingest  (agent-skill bundle ingest, maludb_core 0.97.0)
# The preview blocks are read-only (no model credentials needed). The ingest blocks WRITE:
# they register skill versions named lamp-curl-skill-* (clean up via DELETE /v1/skills/{id}).

# --- POST preview, deterministic path (no model, no credentials) -> 200
#     {"model":null,"extraction":{...document/subjects/keywords...},"bundle_hash","materiality","parent"}
curl -s -X POST 'https://fastapi.maludb.org/v1/skills/ingest' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{
          "name": "lamp-curl-skill-pdf",
          "markdown": "# lamp-curl-skill-pdf\n\nExtract text and tables from PDF files, fill forms, merge documents.\n",
          "frontmatter": {"name": "lamp-curl-skill-pdf",
                          "description": "Extract text from PDF files. Use when working with PDFs.",
                          "metadata": {"version": "1.0"}},
          "preview": true
        }'

# --- POST preview with a model -> 200 {"model","system_prompt","user_message","bundle_hash",...}
#     (renders the stored skill-extract prompt + the live {{ENTITY_TYPES}}/{{EVENT_KINDS}} catalog;
#      needs a model_prompts row for the model, but NOT an api_key)
curl -s -X POST 'https://fastapi.maludb.org/v1/skills/ingest' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"lamp-curl-skill-pdf","markdown":"# x\n\nbody","model":"chatgpt-4o","preview":true}'

# --- POST missing markdown -> 400 missing_field ; unsafe file path -> 422 validation_failed
curl -s -X POST 'https://fastapi.maludb.org/v1/skills/ingest' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"lamp-curl-skill-pdf"}'
curl -s -X POST 'https://fastapi.maludb.org/v1/skills/ingest' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"name":"x","markdown":"# x","files":[{"relative_path":"../etc/passwd","content_text":"nope"}]}'

# --- POST full ingest, deterministic discovery + one base64 script file -> 201
#     {"skill_id","version","bundle_hash","reused":false,"model":null,"parent","materiality","register","ingest"}
#     (content_base64 of "#!/usr/bin/env python3\nprint('extract')\n")
SID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/skills/ingest' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{
          "name": "lamp-curl-skill-pdf",
          "markdown": "# lamp-curl-skill-pdf\n\nExtract text and tables from PDF files, fill forms, merge documents.\n",
          "frontmatter": {"name": "lamp-curl-skill-pdf",
                          "description": "Extract text from PDF files. Use when working with PDFs.",
                          "metadata": {"version": "1.0"}},
          "files": [{"relative_path": "scripts/extract.py",
                     "content_base64": "IyEvdXNyL2Jpbi9lbnYgcHl0aG9uMwpwcmludCgnZXh0cmFjdCcpCg==",
                     "is_executable": true, "media_type": "text/x-python"}]
        }' | grep -o '"skill_id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "registered skill id=$SID"

# --- POST the SAME bundle again -> 200 {"skill_id":<same>,"version","bundle_hash","reused":true}
#     (idempotent re-push: same name + bundle hash, no LLM call, no new version)
curl -s -X POST 'https://fastapi.maludb.org/v1/skills/ingest' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{
          "name": "lamp-curl-skill-pdf",
          "markdown": "# lamp-curl-skill-pdf\n\nExtract text and tables from PDF files, fill forms, merge documents.\n",
          "frontmatter": {"name": "lamp-curl-skill-pdf",
                          "description": "Extract text from PDF files. Use when working with PDFs.",
                          "metadata": {"version": "1.0"}},
          "files": [{"relative_path": "scripts/extract.py",
                     "content_base64": "IyEvdXNyL2Jpbi9lbnYgcHl0aG9uMwpwcmludCgnZXh0cmFjdCcpCg==",
                     "is_executable": true, "media_type": "text/x-python"}]
        }'

# --- POST a revision with materially_different:false (the terminal's --supersede) -> 201
#     New skill_id; the parent version is disabled (register.superseded_skill_id reports it).
#     Parent auto-detected by name (materiality.reasons gains "caller_override").
curl -s -X POST 'https://fastapi.maludb.org/v1/skills/ingest' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{
          "name": "lamp-curl-skill-pdf",
          "markdown": "# lamp-curl-skill-pdf\n\nExtract text and tables from PDF files, fill forms, merge documents. Typo fixed.\n",
          "frontmatter": {"name": "lamp-curl-skill-pdf",
                          "description": "Extract text from PDF files. Use when working with PDFs.",
                          "metadata": {"version": "1.0.1"}},
          "version": "1.0.1",
          "materially_different": false,
          "files": [{"relative_path": "scripts/extract.py",
                     "content_base64": "IyEvdXNyL2Jpbi9lbnYgcHl0aG9uMwpwcmludCgnZXh0cmFjdCcpCg==",
                     "is_executable": true, "media_type": "text/x-python"}]
        }'

# --- PATCH a content field on a registered agent skill -> 409 skill_content_immutable
#     (bundle_hash is set; description/visibility/enabled stay editable)
curl -s -X PATCH "https://fastapi.maludb.org/v1/skills/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"markdown":"# tampered"}'

# --- PATCH a lifecycle field on the same skill -> 200
curl -s -X PATCH "https://fastapi.maludb.org/v1/skills/$SID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"description":"Updated description (lifecycle fields stay editable)."}'

# --- GET the full bundle back (skill pull; see skills_id_bundle_curls.sh) -> 200
curl -s -X GET "https://fastapi.maludb.org/v1/skills/$SID/bundle" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
