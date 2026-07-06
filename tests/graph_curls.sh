# Regression curl commands — /v1/graph/* + /v1/communities* + /v1/datamodel/*   (run from your desktop)
# Each block is standalone; copy/paste individually. Comment above = expected result.
# Token below is the dev token (device_name 'claude-dev').

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
B='https://fastapi.maludb.org'

# ===========================================================================
#  /v1/graph/path   (core >= 0.101.0; 409 not_supported on older cores)
# ===========================================================================

# --- GET path -> 200 {"source_kind":..., "paths":[{"depth":..., "path":[...]}]}
curl -s -X GET "$B/v1/graph/path?source_kind=subject&source_id=1&target_kind=subject&target_id=2" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET path with max_depth + rel filter -> 200
curl -s -X GET "$B/v1/graph/path?source_kind=subject&source_id=1&target_kind=subject&target_id=2&max_depth=3&direction=both&rel=mentions,concerns" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET missing target_kind -> 400 {"error":{"code":"missing_field", ...}}
curl -s -X GET "$B/v1/graph/path?source_kind=subject&source_id=1&target_id=2" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET max_depth out of range -> 422 {"error":{"code":"validation_failed", ...}}
curl -s -X GET "$B/v1/graph/path?source_kind=subject&source_id=1&target_kind=subject&target_id=2&max_depth=99" \
    -H "$TOK" -H 'Accept: application/json'; echo

# ===========================================================================
#  /v1/graph/stats
# ===========================================================================

# --- GET stats -> 200 {"stats":{"edges":...,"nodes":...,"by_store":{...},"top_rels":[...]}}
curl -s -X GET "$B/v1/graph/stats" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET stats with top_rels cap -> 200, at most 5 rels
curl -s -X GET "$B/v1/graph/stats?top_rels=5" \
    -H "$TOK" -H 'Accept: application/json'; echo

# ===========================================================================
#  /v1/graph/god-nodes   (core >= 0.102.0; 409 not_supported on older cores)
# ===========================================================================

# --- GET god-nodes -> 200 {"limit":10,"god_nodes":[{object_kind, object_id, label, degree_*}]}
curl -s -X GET "$B/v1/graph/god-nodes" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET god-nodes with limit -> 200, at most 3 nodes
curl -s -X GET "$B/v1/graph/god-nodes?limit=3" \
    -H "$TOK" -H 'Accept: application/json'; echo

# ===========================================================================
#  /v1/graph/surprises   (core >= 0.102.0; 409 not_supported on older cores)
# ===========================================================================

# --- GET surprises -> 200 {"namespace":"...","limit":25,"surprises":[...]}
#     (run graph_import below first so the namespace has a community set)
curl -s -X GET "$B/v1/graph/surprises?namespace=curltest" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET missing namespace -> 400 {"error":{"code":"missing_field", ...}}
curl -s -X GET "$B/v1/graph/surprises" \
    -H "$TOK" -H 'Accept: application/json'; echo

# ===========================================================================
#  /v1/graph/query   (lexical seed + bounded walk)
# ===========================================================================

# --- GET query -> 200 {"query":...,"namespace":null,"depth":2,"seeds":[...],"nodes":[...],"edges":[...]}
curl -s -X GET "$B/v1/graph/query?q=zozocal+menu" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET query scoped + tuned -> 200
curl -s -X GET "$B/v1/graph/query?q=database+schema&namespace=curltest&depth=1&seeds=2&max_nodes=20" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET query with no searchable terms -> 422 {"error":{"code":"validation_failed", ...}}
curl -s -X GET "$B/v1/graph/query?q=%21%40%23" \
    -H "$TOK" -H 'Accept: application/json'; echo

# ===========================================================================
#  /v1/communities  +  /v1/communities/{id}/members   (core >= 0.102.0)
# ===========================================================================

# --- GET communities -> 200 {"communities":[{community_id, namespace, community_key, label,
#     algorithm, computed_at, member_count}]}
curl -s -X GET "$B/v1/communities" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET communities filtered -> 200, only the namespace's community set
curl -s -X GET "$B/v1/communities?namespace=curltest" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET members -> 200 {"community_id":1,"members":[{object_kind, object_id, score,
#     canonical_name, label}]}   (replace 1 with a real community_id from the list above)
curl -s -X GET "$B/v1/communities/1/members?limit=10" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET members of a missing community -> 404 {"error":{"code":"not_found", ...}}
curl -s -X GET "$B/v1/communities/999999/members" \
    -H "$TOK" -H 'Accept: application/json'; echo

# ===========================================================================
#  POST /v1/graph/import   (core >= 0.103.0; 409 not_supported on older cores)
# ===========================================================================

# --- POST import -> 200 {"namespace":"curltest","nodes":{...},"edges":{...},...}
#     NOTE: writes real subjects/edges under canonical names "curltest/...".
curl -s -X POST "$B/v1/graph/import" \
    -H "$TOK" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"namespace":"curltest","provenance":"graph_curls.sh","graph":{"nodes":[{"id":"alpha","label":"Alpha","community":0},{"id":"beta","label":"Beta","community":1}],"links":[{"source":"alpha","target":"beta","relation":"references","confidence":"EXTRACTED"}]}}'; echo

# --- POST import bad namespace -> 422 {"error":{"code":"validation_failed", ...}}
curl -s -X POST "$B/v1/graph/import" \
    -H "$TOK" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"namespace":"/bad/","graph":{"nodes":[{"id":"a"}],"links":[]}}'; echo

# --- POST import empty nodes -> 422 {"error":{"code":"validation_failed", ...}}
curl -s -X POST "$B/v1/graph/import" \
    -H "$TOK" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"namespace":"curltest","graph":{"nodes":[],"links":[]}}'; echo

# ===========================================================================
#  /v1/datamodel/refresh  +  /v1/datamodel/describe   (core >= 0.104.0)
# ===========================================================================

# --- POST refresh (defaults) -> 200 {"report":{...}}; 409 not_supported on older cores
curl -s -X POST "$B/v1/datamodel/refresh" \
    -H "$TOK" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{}'; echo

# --- POST refresh scoped -> 200 {"report":{...}}
curl -s -X POST "$B/v1/datamodel/refresh" \
    -H "$TOK" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"namespace":"datamodel","schemas":["maludb_core"]}'; echo

# --- POST refresh bad schemas -> 422 {"error":{"code":"validation_failed", ...}}
curl -s -X POST "$B/v1/datamodel/refresh" \
    -H "$TOK" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"schemas":["good_schema","bad;schema"]}'; echo

# --- GET describe -> 200 {"relation":"maludb_subject","describe":{...}}
curl -s -X GET "$B/v1/datamodel/describe?relation=maludb_subject" \
    -H "$TOK" -H 'Accept: application/json'; echo

# --- GET describe missing relation -> 400 {"error":{"code":"missing_field", ...}}
curl -s -X GET "$B/v1/datamodel/describe" \
    -H "$TOK" -H 'Accept: application/json'; echo

# ===========================================================================
#  Auth + method guards
# ===========================================================================

# --- GET no token -> 401 {"error":{"code":"auth_missing", ...}}
curl -s -o /dev/null -w 'no token   -> %{http_code}\n' "$B/v1/graph/stats" \
    -H 'Accept: application/json'

# --- wrong method -> 405 {"error":{"code":"method_not_allowed", ...}} (Allow: GET)
curl -s -o /dev/null -w 'POST stats -> %{http_code}\n' -X POST "$B/v1/graph/stats" \
    -H "$TOK" -H 'Accept: application/json'

# --- wrong method on import -> 405 (Allow: POST)
curl -s -o /dev/null -w 'GET import -> %{http_code}\n' -X GET "$B/v1/graph/import" \
    -H "$TOK" -H 'Accept: application/json'
