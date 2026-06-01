# Regression curl commands — /v1/documents-backfill   (maludb_core 0.87.0)
# Onboarding action: link a schema's pre-0.87 document tags into the unified graph.
# Idempotent — safe to re-run; returns {"linked": <tags processed this run>}.

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
B='https://fastapi.maludb.org'

# --- POST -> 200 {"linked": <int>} (re-run yields the same count; no duplicate edges)
curl -s -X POST "$B/v1/documents-backfill" -H "$TOK" -H 'Accept: application/json'
curl -s -X POST "$B/v1/documents-backfill" -H "$TOK" -H 'Accept: application/json'

# --- GET (unsupported) -> 405 method_not_allowed
curl -s -X GET "$B/v1/documents-backfill" -H "$TOK" -H 'Accept: application/json'

# --- no token -> 401 auth_missing
curl -s -X POST "$B/v1/documents-backfill" -H 'Accept: application/json'
