# Regression curl commands — /v1/notes/{id}/reopen-issue   (self-cleaning)
# Reopens a closed issue (clears issue_closed_at). 409 if not an issue / not closed.
A='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'

# Create an issue-type note
IID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/notes' -H "$A" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-raw '{"title":"Bug report","type":"issue"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "issue id=$IID"

# --- reopen while open -> 409 not closed
curl -s -X POST "https://fastapi.maludb.org/v1/notes/$IID/reopen-issue" -H "$A" -H 'Accept: application/json'

# close it, then reopen -> 200
curl -s -o /dev/null -X POST "https://fastapi.maludb.org/v1/notes/$IID/close-issue" -H "$A"
curl -s -X POST "https://fastapi.maludb.org/v1/notes/$IID/reopen-issue" -H "$A" -H 'Accept: application/json'

# --- reopen again -> 409 not closed ; GET (unsupported) -> 405
curl -s -X POST "https://fastapi.maludb.org/v1/notes/$IID/reopen-issue" -H "$A" -H 'Accept: application/json'
curl -s -X GET "https://fastapi.maludb.org/v1/notes/$IID/reopen-issue" -H "$A" -H 'Accept: application/json'

# clean up
curl -s -o /dev/null -X DELETE "https://fastapi.maludb.org/v1/notes/$IID" -H "$A"
echo "cleaned up note $IID"
