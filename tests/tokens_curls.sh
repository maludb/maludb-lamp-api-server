# Regression curl commands — /v1/tokens + /v1/tokens/{id}  (token issuance/list/revoke)
# Authorization IS the Postgres login: supply pg_dbname/pg_user/pg_password (verified by
# connecting). Knowing a working Postgres password authorizes minting/managing tokens for that
# connection. Self-cleaning: creates a token then revokes it.

B='https://fastapi.maludb.org'
H='Content-Type: application/json'
PG='"pg_dbname":"zozocal","pg_user":"zozocal","pg_password":"!Meelup578Loipol229!"'

# --- create: wrong pg password -> 403 pg_auth_failed --------------------------
curl -s -X POST "$B/v1/tokens" -H "$H" -d '{"pg_dbname":"zozocal","pg_user":"zozocal","pg_password":"wrong"}'; echo
# --- create: missing creds -> 400 -------------------------------------------
curl -s -X POST "$B/v1/tokens" -H "$H" -d '{}'; echo

# --- create: valid creds -> 201, token shown ONCE ---------------------------
RESP=$(curl -s -X POST "$B/v1/tokens" -H "$H" -d "{$PG,\"role\":\"executor\",\"device_name\":\"regression-token\",\"expires_in_days\":30}")
echo "$RESP"
NEWTOK=$(echo "$RESP" | grep -o '"token":"[^"]*"' | head -1 | sed 's/"token":"//;s/"//')
NEWID=$(echo "$RESP"  | grep -o '"id":[0-9]*'      | head -1 | grep -o '[0-9]*')

# --- the freshly minted token authenticates a normal request -> 200 ---------
curl -s -o /dev/null -w 'new token GET /v1/subjects -> %{http_code}\n' "$B/v1/subjects?limit=1" -H "Authorization: Bearer $NEWTOK"

# --- list tokens for this connection (metadata only; no token value/password) -
curl -s -X GET "$B/v1/tokens" -H "$H" -d "{$PG}"; echo

# --- revoke (DELETE) -> 200 ; revoked token then 401 ------------------------
curl -s -X DELETE "$B/v1/tokens/$NEWID" -H "$H" -d "{$PG}"; echo
curl -s -o /dev/null -w 'revoked token -> %{http_code}\n' "$B/v1/subjects?limit=1" -H "Authorization: Bearer $NEWTOK"

# --- revoke a token you don't own (wrong connection can't even authorize) -> 403
curl -s -X DELETE "$B/v1/tokens/1" -H "$H" -d '{"pg_dbname":"zozocal","pg_user":"zozocal","pg_password":"wrong"}'; echo
