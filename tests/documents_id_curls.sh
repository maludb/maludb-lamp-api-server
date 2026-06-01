# Regression curl commands — /v1/documents/{id}   (self-cleaning lifecycle)
# GET returns metadata only (binary download is out of v1, requirements §6).

# --- GET missing id -> 404 not_found
curl -s -X GET 'https://fastapi.maludb.org/v1/documents/999999' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- Lifecycle: upload -> GET detail -> DELETE -> 404 ------------------------
printf 'detail test content\n' > /tmp/maludb_sample2.txt
DID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/documents' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -F 'file=@/tmp/maludb_sample2.txt' -F 'filename=detail.txt' -F 'mime_type=text/plain' \
    -F 'description=detail test' -F 'document_type=Report' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created document id=$DID"

# GET detail -> 200 (title, media_type, content_size, content_hash, description,
#                    document_type:"Report", primary_project_id, tags[])
curl -s -X GET "https://fastapi.maludb.org/v1/documents/$DID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# DELETE -> 200 ; GET again -> 404
curl -s -X DELETE "https://fastapi.maludb.org/v1/documents/$DID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X GET "https://fastapi.maludb.org/v1/documents/$DID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
rm -f /tmp/maludb_sample2.txt

# --- PATCH link/unlink graph edits (maludb_core 0.87.0) ----------------------
# Upload bare, then add a project via PATCH, swap it for another, then remove it.
# Self-cleans the document AND the subjects it created (project subjects).
TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
B='https://fastapi.maludb.org'
printf 'patch graph test\n' > /tmp/maludb_patch.txt
DID=$(curl -s -X POST "$B/v1/documents" -H "$TOK" \
    -F 'file=@/tmp/maludb_patch.txt' -F 'filename=patch.txt' -F 'mime_type=text/plain' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created document id=$DID (no links yet)"

# PATCH with neither link nor unlink -> 400 bad_request
curl -s -X PATCH "$B/v1/documents/$DID" -H "$TOK" -H 'Content-Type: application/json' -d '{}'

# PATCH link a project -> 200, primary_project_id set, tag tag_object_id resolved
curl -s -X PATCH "$B/v1/documents/$DID" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"link":{"projects":["Regression Project A"]}}'

# PATCH swap: unlink A, link B -> primary repoints to B
curl -s -X PATCH "$B/v1/documents/$DID" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"unlink":{"projects":["Regression Project A"]},"link":{"projects":["Regression Project B"]}}'

# PATCH remove B -> edge gone, primary_project_id cleared to null, tag removed
curl -s -X PATCH "$B/v1/documents/$DID" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"unlink":{"projects":["Regression Project B"]}}'

# bad shape: link.projects not an array -> 422
curl -s -X PATCH "$B/v1/documents/$DID" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"link":{"projects":"oops"}}'

# self-clean: delete the document, then delete the two project subjects it created
curl -s -X DELETE "$B/v1/documents/$DID" -H "$TOK" -H 'Accept: application/json'
for NAME in 'Regression%20Project%20A' 'Regression%20Project%20B'; do
    SID=$(curl -s "$B/v1/subjects?q=$NAME&limit=1" -H "$TOK" | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
    [ -n "$SID" ] && curl -s -X DELETE "$B/v1/subjects/$SID" -H "$TOK" -H 'Accept: application/json'
done
rm -f /tmp/maludb_patch.txt
