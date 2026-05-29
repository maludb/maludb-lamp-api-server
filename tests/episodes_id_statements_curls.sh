# Regression curl commands — /v1/episodes/{id}/statements   (event-scoped links)
# object_kind/object_id default to this episode. Self-cleaning.
# Models the meeting example: attendee (person), document, decision -> the event.

# --- POST to a missing episode -> 404 not_found
curl -s -X POST 'https://fastapi.maludb.org/v1/episodes/999999/statements' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"verb":"attended","subject":"Regression Attendee"}'

# --- GET statements of a missing episode -> 404 not_found
curl -s -X GET 'https://fastapi.maludb.org/v1/episodes/999999/statements' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'

# Throwaway event:
EID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/episodes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"Project kickoff","kind":"Meeting","occurred_at":"2026-05-29T15:00:00Z"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created episode id=$EID"

# --- POST attendee by name (subject defaults to a person) -> 201
S1=$(curl -s -X POST "https://fastapi.maludb.org/v1/episodes/$EID/statements" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"verb":"attended","subject":"Regression Attendee"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "attendee statement id=$S1"

# --- POST idempotent re-link (same subject+verb+object) -> 201, SAME id as above
S1B=$(curl -s -X POST "https://fastapi.maludb.org/v1/episodes/$EID/statements" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"verb":"attended","subject":"Regression Attendee"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "re-link statement id=$S1B (expect == $S1)"

# --- Full meeting model: also link a document (generated_by) and a decision (made_during) ---
# Create a throwaway document + decision (note) to link, so the file stays self-cleaning.
printf 'minutes\n' > /tmp/maludb_minutes.txt
DID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/documents' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -F 'file=@/tmp/maludb_minutes.txt' -F 'filename=minutes.txt' -F 'mime_type=text/plain' \
    -F 'document_type=Meeting Notes' | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
MID=$(curl -s -X POST 'https://fastapi.maludb.org/v1/notes' \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"title":"Ship the release","body":"decided to ship","type":"decision"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "created document id=$DID, decision(note) id=$MID"

# Document generated_by the event -> 201
S2=$(curl -s -X POST "https://fastapi.maludb.org/v1/episodes/$EID/statements" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"verb\":\"generated_by\",\"subject_kind\":\"document\",\"subject_id\":$DID}" \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "document statement id=$S2"

# Decision made_during the event -> 201
S3=$(curl -s -X POST "https://fastapi.maludb.org/v1/episodes/$EID/statements" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"verb\":\"made_during\",\"subject_kind\":\"memory\",\"subject_id\":$MID}" \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "decision statement id=$S3"

# --- POST FK violation: subject_id that doesn't exist -> 422
curl -s -X POST "https://fastapi.maludb.org/v1/episodes/$EID/statements" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"verb":"attended","subject_kind":"subject","subject_id":888888888}'

# --- POST unknown verb -> 422
curl -s -X POST "https://fastapi.maludb.org/v1/episodes/$EID/statements" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"verb":"no_such_verb","subject":"Regression Attendee"}'

# --- GET the event's links -> 200 {"statements":[...]}
curl -s -X GET "https://fastapi.maludb.org/v1/episodes/$EID/statements" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'

# --- cleanup: delete the statements, the episode, and the linked document/decision ---
for S in "$S1" "$S2" "$S3"; do
  curl -s -X DELETE "https://fastapi.maludb.org/v1/statements/$S" \
      -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
done
curl -s -X DELETE "https://fastapi.maludb.org/v1/episodes/$EID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
curl -s -X DELETE "https://fastapi.maludb.org/v1/documents/$DID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
curl -s -X DELETE "https://fastapi.maludb.org/v1/notes/$MID" \
    -H 'Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123' -H 'Accept: application/json'
rm -f /tmp/maludb_minutes.txt
