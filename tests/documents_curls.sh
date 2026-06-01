# Regression curl commands — /v1/documents   (multipart upload; bytes stored in-DB)
# The upload block self-cleans by deleting the document it creates.

# --- GET list -> 200 {"documents":[ {id,title,source_type,media_type,description,content_size,...} ]}
curl -s -X GET 'https://fastapi.maludb.org/v1/documents' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- GET search (q on title) -> 200
curl -s -X GET 'https://fastapi.maludb.org/v1/documents?q=sample' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST with no file part -> 400 missing_field
curl -s -X POST 'https://fastapi.maludb.org/v1/documents' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -F 'filename=x.txt'

# --- GET no token -> 401 ; PATCH collection -> 405
curl -s -X GET 'https://fastapi.maludb.org/v1/documents' -H 'Accept: application/json'
curl -s -X PATCH 'https://fastapi.maludb.org/v1/documents' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# --- POST upload (multipart) -> 201, then self-clean via DELETE ---------------
printf 'Hello, this is a test document.\n' > /tmp/maludb_sample.txt
DID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/documents' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -F 'file=@/tmp/maludb_sample.txt' \
    -F 'filename=sample.txt' \
    -F 'mime_type=text/plain' \
    -F 'description=a sample upload' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created document id=$DID"
curl -s -X DELETE "https://fastapi.maludb.org/v1/documents/$DID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
rm -f /tmp/maludb_sample.txt

# --- POST upload with a SEEDED document_type ("Meeting Notes") -> 201 ---------
# Response should echo "document_type":"Meeting Notes".
printf 'Seeded-type upload.\n' > /tmp/maludb_seeded.txt
DID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/documents' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -F 'file=@/tmp/maludb_seeded.txt' \
    -F 'filename=seeded.txt' \
    -F 'mime_type=text/plain' \
    -F 'description=seeded type' \
    -F 'document_type=Meeting Notes' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created document id=$DID (document_type=Meeting Notes)"
# GET detail -> 200, "document_type":"Meeting Notes" round-trips
curl -s -X GET "https://fastapi.maludb.org/v1/documents/$DID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X DELETE "https://fastapi.maludb.org/v1/documents/$DID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
rm -f /tmp/maludb_seeded.txt

# --- POST upload with a BRAND-NEW UNSEEDED document_type -> 201 (must succeed) -
# Advisory list, no FK: an arbitrary type string is accepted.
printf 'Unseeded-type upload.\n' > /tmp/maludb_unseeded.txt
DID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/documents' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -F 'file=@/tmp/maludb_unseeded.txt' \
    -F 'filename=unseeded.txt' \
    -F 'mime_type=text/plain' \
    -F 'document_type=Totally Made Up Type' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created document id=$DID (document_type=Totally Made Up Type)"
curl -s -X GET "https://fastapi.maludb.org/v1/documents/$DID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
curl -s -X DELETE "https://fastapi.maludb.org/v1/documents/$DID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'
rm -f /tmp/maludb_unseeded.txt

# --- POST upload with projects + subjects -> wires the graph (maludb_core 0.87.0) -
# primary_project_id is set from the first project; each name becomes a document→subject
# edge + soft tag (tag_object_id resolved). The document is then reachable from the graph.
# Self-cleans the document AND the subjects it created.
TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
B='https://fastapi.maludb.org'
printf 'graph-linked upload.\n' > /tmp/maludb_graph.txt
RESP=$(curl -s -X POST "$B/v1/documents" -H "$TOK" \
    -F 'file=@/tmp/maludb_graph.txt' -F 'filename=graph.txt' -F 'mime_type=text/plain' \
    -F 'projects=Regression Project G' -F 'subjects=Regression Subject G')
echo "$RESP"                                  # -> "primary_project_id": <id>
DID=$(echo "$RESP" | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
PID=$(echo "$RESP" | grep -o '"primary_project_id":[0-9]*' | grep -o '[0-9]*')

# GET detail -> tags[] carry tag_object_type:"subject" + resolved tag_object_id
curl -s "$B/v1/documents/$DID" -H "$TOK" -H 'Accept: application/json'
# graph walk from the project subject -> the document appears as an object_kind:"document"
curl -s "$B/v1/graph/walk?kind=subject&id=$PID&direction=both" -H "$TOK" -H 'Accept: application/json'
# project detail -> documents[] lists the document
curl -s "$B/v1/projects/$PID" -H "$TOK" -H 'Accept: application/json'

# self-clean: delete the document + the two subjects it created
curl -s -X DELETE "$B/v1/documents/$DID" -H "$TOK" -H 'Accept: application/json'
for NAME in 'Regression%20Project%20G' 'Regression%20Subject%20G'; do
    SID=$(curl -s "$B/v1/subjects?q=$NAME&limit=1" -H "$TOK" | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
    [ -n "$SID" ] && curl -s -X DELETE "$B/v1/subjects/$SID" -H "$TOK" -H 'Accept: application/json'
done
rm -f /tmp/maludb_graph.txt
