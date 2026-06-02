# Regression curl commands — /v1/memory/search  (maludb_core memory; endpoint group 3)
# Embed the query with the same model used at ingest, then maludb_memory_search(...).
# A subject and/or verb is REQUIRED (the graph-bound search pre-filters to a compartment).

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
B='https://fastapi.maludb.org'

# --- validation: missing query -> 400 --------------------------------------
curl -s -X POST "$B/v1/memory/search" -H "$TOK" -H 'Content-Type: application/json' -d '{}'; echo
# --- validation: no subject AND no verb -> 400 (compartment pre-filter required) ----------
curl -s -X POST "$B/v1/memory/search" -H "$TOK" -H 'Content-Type: application/json' \
  -d '{"query":"anything"}'; echo

# --- valid search (run memory_documents_curls.sh first to populate apismoke) ---------------
curl -s -X POST "$B/v1/memory/search" -H "$TOK" -H 'Content-Type: application/json' \
  -d '{"query":"Oracle 21c upgrade completed on 2026-03-30.","subject":"Oracle 21c","verb":"upgrade","namespace":"apismoke","limit":5}'; echo

# --- wrong method -> 405 ; no token -> 401 ---------------------------------
curl -s -o /dev/null -w 'GET -> %{http_code}\n' "$B/v1/memory/search" -H "$TOK"
curl -s -o /dev/null -w 'no token -> %{http_code}\n' -X POST "$B/v1/memory/search"
