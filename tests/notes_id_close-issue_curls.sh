# Regression curl commands — /v1/notes/{id}/close-issue   (self-cleaning)
# Closes an issue-type note (issue_closed_at = now()). 409 if not an issue / already closed.
A='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'

# Create an issue-type note
IID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/notes' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"title":"Bug report","type":"issue"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "issue id=$IID"

# --- close -> 200 {"note":{...,"issue_closed_at":"..."}}
curl -s -X POST "https://fastapi.maludb.org/v1/notes/$IID/close-issue" -H "$A" -H 'Accept: application/json'

# --- close again -> 409 already closed
curl -s -X POST "https://fastapi.maludb.org/v1/notes/$IID/close-issue" -H "$A" -H 'Accept: application/json'

# --- GET (unsupported) -> 405
curl -s -X GET "https://fastapi.maludb.org/v1/notes/$IID/close-issue" -H "$A" -H 'Accept: application/json'

# Create a plain note and try to close it -> 409 not an issue
NID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/notes' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"title":"plain note"}' | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
curl -s -X POST "https://fastapi.maludb.org/v1/notes/$NID/close-issue" -H "$A" -H 'Accept: application/json'

# clean up
curl -s -o /dev/null -X DELETE "https://fastapi.maludb.org/v1/notes/$IID" -H "$A"
curl -s -o /dev/null -X DELETE "https://fastapi.maludb.org/v1/notes/$NID" -H "$A"
echo "cleaned up notes $IID, $NID"
