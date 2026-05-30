# Regression curl commands — /v1/objects/{kind}/{id}   (canonical handle detail)
# GET resolves one (object_kind, object_id) handle inline with its typed attributes
# (and, for episodes, statements + details) via maludb_object_get.
# NOTE: attributes don't cascade on subject delete -> cleanup removes them first.

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
H='https://fastapi.maludb.org'

# Create a subject (with an attribute) via the atomic POST so there's a handle to read:
SID=$(curl -s -X POST "$H/v1/objects/subject" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"canonical_name":"Objects Id Test Person","subject_type":"person","attributes":[{"attr_name":"title","value_text":"Engineer"}]}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "subject handle id=$SID"

# --- GET the handle -> 200 {"object":{kind:"subject",id,object,attributes}}
curl -s "$H/v1/objects/subject/$SID" -H "$TOK"; echo

# --- GET unknown handle -> 404 not_found
curl -s -o /dev/null -w "GET unknown -> %{http_code}\n" "$H/v1/objects/subject/888888888" -H "$TOK"

# --- non-GET -> 405
curl -s -o /dev/null -w "DELETE on handle -> %{http_code}\n" -X DELETE "$H/v1/objects/subject/$SID" -H "$TOK"

# --- cleanup: delete the subject's attributes (no cascade), then the subject ---
for AID in $(curl -s "$H/v1/attributes?target_kind=subject&target_id=$SID" -H "$TOK" | grep -o '"id":[0-9]*' | grep -o '[0-9]*'); do
    curl -s -o /dev/null -X DELETE "$H/v1/attributes/$AID" -H "$TOK"
done
curl -s -X DELETE "$H/v1/subjects/$SID" -H "$TOK"; echo
