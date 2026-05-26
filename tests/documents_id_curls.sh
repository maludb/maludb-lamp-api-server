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
    -F 'description=detail test' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created document id=$DID"

# GET detail -> 200 (title, media_type, content_size, content_hash, description)
curl -s -X GET "https://fastapi.maludb.org/v1/documents/$DID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Accept: application/json'

# PATCH (unsupported) -> 405
curl -s -X PATCH "https://fastapi.maludb.org/v1/documents/$DID" \
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
