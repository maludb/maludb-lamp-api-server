# Regression curl commands — /v1/attribute-templates   (0.83.0 form catalog)
# The typed-property catalog that drives forms: which attributes apply to a node/edge
# type, their value_type / requirement / label / unit. No PATCH (create + delete only).
# Self-cleaning: creates one template and deletes it.

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
H='https://fastapi.maludb.org'

# --- GET full catalog -> 200 {"attribute_templates":[...]}
curl -s "$H/v1/attribute-templates?limit=5" -H "$TOK"; echo

# --- GET filtered by applies_to + type_value (this is what a form fetches) -> 200
curl -s "$H/v1/attribute-templates?applies_to=episode_type&type_value=Meeting" -H "$TOK"; echo

# --- POST create -> 201 {"attribute_template":{...}}
TID=$(curl -s -X POST "$H/v1/attribute-templates" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"applies_to":"episode_type","type_value":"Meeting","attr_name":"zz_test_attr","value_type":"numeric","requirement":"optional","label":"ZZ Test","unit":"x","display_order":99}' \
    | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
echo "template id=$TID"

# --- POST bad value_type -> 422 validation_failed (DB enum check)
curl -s -X POST "$H/v1/attribute-templates" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"applies_to":"episode_type","type_value":"Meeting","attr_name":"q","value_type":"NOTATYPE"}'; echo

# --- POST missing field -> 400 missing_field
curl -s -X POST "$H/v1/attribute-templates" -H "$TOK" -H 'Content-Type: application/json' \
    -d '{"applies_to":"episode_type"}'; echo

# --- cleanup: delete the template ---
curl -s -X DELETE "$H/v1/attribute-templates/$TID" -H "$TOK"; echo
