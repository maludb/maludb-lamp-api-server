# Regression curl commands — /v1/memory/config  (maludb_core memory; endpoint group 1)
# Model/embedding/prompt config: secret_set + register provider/alias + set_model_config.
#
# NOTE: register_model_provider/register_model_alias are owner-restricted in this deployment —
# the POST flow returns 403 insufficient_privilege until the API role (zozocal) is granted
# maludb_llm_model_admin. GET read-back works regardless (SECURITY DEFINER).

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
B='https://fastapi.maludb.org'

# --- GET read-back (200; config null until bound) ----------------------------
curl -s "$B/v1/memory/config?namespace=default" -H "$TOK" -H 'Accept: application/json'; echo

# --- POST full config -> 200 once granted; 403 insufficient_privilege until then -------------
curl -s -X POST "$B/v1/memory/config" -H "$TOK" -H 'Content-Type: application/json' -d '{
  "namespace":"default",
  "secret_name":"llm_token_default",
  "token":"sk-REPLACE-ME",
  "provider":{"name":"llm_primary","kind":"cloud_api","adapter_name":"anthropic","data_sensitivity":"internal"},
  "alias":{"name":"extractor","model_identifier":"claude-opus-4-8","context_length":200000,"base_url":"https://api.anthropic.com"},
  "prompt_template":"Extract SVPO edges from: {{chunk}}",
  "embedding_model":"text-embedding-3-small",
  "generation_params":{"temperature":0},
  "default_subject_type":"other",
  "default_provenance":"suggested"
}'; echo

# --- validation: missing provider.name -> 400 -------------------------------
curl -s -X POST "$B/v1/memory/config" -H "$TOK" -H 'Content-Type: application/json' \
  -d '{"alias":{"name":"x","model_identifier":"m","base_url":"https://h"},"embedding_model":"e","provider":{"kind":"cloud_api"}}'; echo

# --- validation: prompt_template without {{chunk}} -> 422 -------------------
curl -s -X POST "$B/v1/memory/config" -H "$TOK" -H 'Content-Type: application/json' \
  -d '{"provider":{"name":"p","kind":"cloud_api"},"alias":{"name":"a","model_identifier":"m","base_url":"https://h"},"embedding_model":"e","prompt_template":"no placeholder"}'; echo

# --- wrong method DELETE -> 405 ; no token -> 401 ---------------------------
curl -s -o /dev/null -w 'DELETE -> %{http_code}\n' -X DELETE "$B/v1/memory/config" -H "$TOK"
curl -s -o /dev/null -w 'no token -> %{http_code}\n' "$B/v1/memory/config"
