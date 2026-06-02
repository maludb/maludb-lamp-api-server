# Regression curl commands — /v1/memory/config  (maludb_core memory; endpoint group 1)
# Model/embedding/prompt config: secret_set + register provider/alias + set_model_config.
#
# Uses the per-tenant self-service facades (maludb_core 0.91.0): maludb_register_model_provider /
# maludb_register_model_alias are granted to maludb_memory_executor, so POST returns 200 with no
# global model-admin grant. NOTE: provider/alias/config-binding rows are append-only for the
# executor role (no delete facade) — this writes a 'cfgtest'-namespace binding + provider/alias
# that only a superuser can remove. The secret IS revocable via maludb_core.secret_revoke(name).

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
B='https://fastapi.maludb.org'

# --- GET read-back (200; config null until bound) ----------------------------
curl -s "$B/v1/memory/config?namespace=default" -H "$TOK" -H 'Accept: application/json'; echo

# --- POST full config -> 200 + read-back (secret_ref echoes the NAME, never the token) -------
curl -s -X POST "$B/v1/memory/config" -H "$TOK" -H 'Content-Type: application/json' -d '{
  "namespace":"cfgtest",
  "secret_name":"zz_apitest_tok",
  "token":"sk-apitest-SECRET-123",
  "provider":{"name":"zz_apitest_prov","kind":"cloud_api","adapter_name":"anthropic","data_sensitivity":"internal"},
  "alias":{"name":"zz_apitest_ext","model_identifier":"claude-opus-4-8","context_length":200000,"base_url":"https://api.anthropic.com"},
  "prompt_template":"Extract SVPO edges from: {{chunk}}",
  "embedding_model":"text-embedding-3-small",
  "generation_params":{"temperature":0},
  "default_subject_type":"other",
  "default_provenance":"suggested"
}'; echo
# cleanup the secret (provider/alias/config binding are append-only — superuser to remove):
#   psql> SELECT maludb_core.secret_revoke('zz_apitest_tok','test cleanup');

# --- validation: missing provider.name -> 400 -------------------------------
curl -s -X POST "$B/v1/memory/config" -H "$TOK" -H 'Content-Type: application/json' \
  -d '{"alias":{"name":"x","model_identifier":"m","base_url":"https://h"},"embedding_model":"e","provider":{"kind":"cloud_api"}}'; echo

# --- validation: prompt_template without {{chunk}} -> 422 -------------------
curl -s -X POST "$B/v1/memory/config" -H "$TOK" -H 'Content-Type: application/json' \
  -d '{"provider":{"name":"p","kind":"cloud_api"},"alias":{"name":"a","model_identifier":"m","base_url":"https://h"},"embedding_model":"e","prompt_template":"no placeholder"}'; echo

# --- wrong method DELETE -> 405 ; no token -> 401 ---------------------------
curl -s -o /dev/null -w 'DELETE -> %{http_code}\n' -X DELETE "$B/v1/memory/config" -H "$TOK"
curl -s -o /dev/null -w 'no token -> %{http_code}\n' "$B/v1/memory/config"
