# Regression curl commands — local MySQL auth/routing layer
# The bearer token is resolved against the local MySQL `users` table (sha256 hash lookup); the
# matching row supplies the Postgres DB_NAME/USER/PASS the request connects with. Run
# `php tests/local_db_setup.php` first to create + seed the users table.

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
B='https://fastapi.maludb.org'

# --- valid token: resolves MySQL → connects Postgres → 200 with data ---------
curl -s -o /dev/null -w 'GET /v1/subjects (valid token) -> %{http_code}\n' "$B/v1/subjects?limit=2" -H "$TOK"
curl -s "$B/v1/subjects?limit=1" -H "$TOK" | head -c 160; echo

# --- unknown token -> 401 (not in MySQL users) -------------------------------
curl -s -o /dev/null -w 'unknown token -> %{http_code}\n' "$B/v1/subjects" -H 'Authorization: Bearer malu_not_a_real_token'
# --- malformed token (no malu_ prefix) -> 401 --------------------------------
curl -s -o /dev/null -w 'malformed token -> %{http_code}\n' "$B/v1/subjects" -H 'Authorization: Bearer nope'
# --- no token -> 401 ---------------------------------------------------------
curl -s -o /dev/null -w 'no token -> %{http_code}\n' "$B/v1/subjects"
