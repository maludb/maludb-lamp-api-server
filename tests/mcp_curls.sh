# Regression curl commands — POST /mcp  (MCP server endpoint; stateless Streamable HTTP)
# Smoke sequence per the cross-server contract, then the protocol negatives.
# Override the dev defaults with env vars:  TOKEN=malu_…  B=https://host  bash tests/mcp_curls.sh
# Protocol-only checks (registry shape, -32602 validation, SQLSTATE map) run without a
# server: php tests/mcp_protocol_test.php

TOKEN="${TOKEN:-malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123}"
TOK="Authorization: Bearer $TOKEN"
B="${B:-https://fastapi.maludb.org}"
CT='Content-Type: application/json'

# --- initialize -> protocolVersion echoed, serverInfo {maludb / MaluDB Memory / 0.1.0} ----
curl -s -X POST "$B/mcp" -H "$TOK" -H "$CT" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}'; echo

# --- notifications/initialized (no id) -> HTTP 202, empty body ----------------------------
curl -s -o /dev/null -w 'notification -> %{http_code}\n' -X POST "$B/mcp" -H "$TOK" -H "$CT" \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'

# --- tools/list -> exactly 8 tools, no nextCursor ----------------------------------------
curl -s -X POST "$B/mcp" -H "$TOK" -H "$CT" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | { command -v jq >/dev/null && jq '{tools: (.result.tools | length), names: [.result.tools[].name]}' || cat; }

# --- ping -> {} ---------------------------------------------------------------------------
curl -s -X POST "$B/mcp" -H "$TOK" -H "$CT" -d '{"jsonrpc":"2.0","id":3,"method":"ping"}'; echo

# --- tools/call find_subjects -> {"subjects":[...]} in one text content block -------------
curl -s -X POST "$B/mcp" -H "$TOK" -H "$CT" \
  -d '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"find_subjects","arguments":{"limit":5}}}'; echo

# --- search_memory WITHOUT subject/verb -> isError:true, missing_field + subject
#     suggestions (the agent self-corrects; the core is never called) ----------------------
curl -s -X POST "$B/mcp" -H "$TOK" -H "$CT" \
  -d '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"search_memory","arguments":{"query":"oracle upgrade"}}}'; echo

# --- negatives -----------------------------------------------------------------------------
curl -s -o /dev/null -w 'GET -> %{http_code}\n' "$B/mcp" -H "$TOK"                       # 405 + Allow: POST
curl -s -o /dev/null -w 'DELETE -> %{http_code}\n' -X DELETE "$B/mcp" -H "$TOK"          # 405
curl -s -o /dev/null -w 'foreign Origin -> %{http_code}\n' -X POST "$B/mcp" -H "$TOK" -H "$CT" \
  -H 'Origin: https://evil.example.net' -d '{"jsonrpc":"2.0","id":6,"method":"ping"}'    # 403
curl -s -o /dev/null -w 'bad protocol header -> %{http_code}\n' -X POST "$B/mcp" -H "$TOK" -H "$CT" \
  -H 'MCP-Protocol-Version: 2024-11-05' -d '{"jsonrpc":"2.0","id":7,"method":"ping"}'    # 400
curl -s -o /dev/null -w 'no token -> %{http_code}\n' -X POST "$B/mcp" -H "$CT" \
  -d '{"jsonrpc":"2.0","id":8,"method":"ping"}'                                          # 401
curl -s -X POST "$B/mcp" -H "$TOK" -H "$CT" \
  -d '[{"jsonrpc":"2.0","id":9,"method":"ping"}]'; echo                                  # -32600 (no batches)
curl -s -X POST "$B/mcp" -H "$TOK" -H "$CT" -d '{not json'; echo                         # -32700, id null
