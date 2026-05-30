# Regression curl commands — /v1/attribute-templates/{id}   (0.83.0 form catalog)
#   GET    one template row
#   DELETE remove it   (no PATCH -> 405)
# Self-cleaning: creates one template and deletes it.

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
H='https://fastapi.maludb.org'

TID=$(curl -s -X POST "$H/v1/attribute-templates" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"applies_to":"subject_type","type_value":"person","attr_name":"zz_id_test","value_type":"text","label":"ZZ Id Test"}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "template id=$TID"

# --- GET one -> 200 {"attribute_template":{...}}
curl -s "$H/v1/attribute-templates/$TID" -H "$TOK"; echo

# --- PATCH not supported -> 405 method_not_allowed
curl -s -o /dev/null -w "PATCH -> %{http_code}\n" -X PATCH "$H/v1/attribute-templates/$TID" \
    -H "$TOK" -H 'Content-Type: application/json' -d '{"label":"x"}'

# --- GET missing -> 404
curl -s -o /dev/null -w "GET missing -> %{http_code}\n" "$H/v1/attribute-templates/888888888" -H "$TOK"

# --- DELETE -> 200, again -> 404
curl -s -X DELETE "$H/v1/attribute-templates/$TID" -H "$TOK"; echo
curl -s -o /dev/null -w "DELETE again -> %{http_code}\n" -X DELETE "$H/v1/attribute-templates/$TID" -H "$TOK"
