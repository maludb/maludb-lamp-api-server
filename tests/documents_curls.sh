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
