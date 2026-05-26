#!/usr/bin/env bash
#
# Regression tests for the MaluDB API — /v1/subjects.
# Pure curl + grep; no other dependencies. Targets the clean URLs, so the host
# must have mod_rewrite + AllowOverride enabled (see requirements.md §1.3).
#
# Usage:
#   ./tests/regression_subjects.sh
#   BASE=https://fastapi.maludb.org TOKEN=malu_xxx ./tests/regression_subjects.sh
#   RUN_WRITE=1 ./tests/regression_subjects.sh        # also runs POST create (inserts a row)
#
# Exit code is 0 only if every test passes.

set -u

BASE="${BASE:-https://fastapi.maludb.org}"
TOKEN="${TOKEN:-malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123}"
AUTH="Authorization: Bearer ${TOKEN}"

pass=0; fail=0
body="$(mktemp)"; CODE=""
trap 'rm -f "$body"' EXIT

# req <curl args...>  — run a request, capture status in $CODE and body in $body.
req() { CODE="$(curl -s -o "$body" -w '%{http_code}' "$@")"; }

# assert <name> <expected_status> [expected_body_substring]
assert() {
  local name="$1" exp="$2" sub="${3:-}" ok=1
  [ "$CODE" = "$exp" ] || ok=0
  if [ -n "$sub" ] && ! grep -qF "$sub" "$body"; then ok=0; fi
  if [ "$ok" = 1 ]; then
    printf 'PASS  %-46s [%s]\n' "$name" "$CODE"; pass=$((pass+1))
  else
    printf 'FAIL  %-46s [got %s, want %s]\n' "$name" "$CODE" "$exp"
    [ -n "$sub" ] && printf '        expected body to contain: %s\n' "$sub"
    printf '        body: %s\n' "$(head -c 300 "$body")"
    fail=$((fail+1))
  fi
}

echo "Target: $BASE/v1/subjects"
echo

# --- Auth -------------------------------------------------------------------
req "$BASE/v1/subjects"
assert "GET no token -> 401 auth_missing"            401 '"auth_missing"'

req -H "Authorization: Bearer not-a-malu-token" "$BASE/v1/subjects"
assert "GET malformed token -> 401 auth_invalid"     401 '"auth_invalid"'

req -H "Authorization: Bearer malu_unknownunknownunknownunknownunknown0000" "$BASE/v1/subjects"
assert "GET unknown token -> 401 auth_invalid"       401 '"auth_invalid"'

# --- GET list ---------------------------------------------------------------
req -H "$AUTH" "$BASE/v1/subjects"
assert "GET list -> 200"                             200 '"subjects"'

req -H "$AUTH" "$BASE/v1/subjects"
assert "GET list carries linked_verbs"               200 '"linked_verbs"'

req -H "$AUTH" "$BASE/v1/subjects"
assert "GET list carries related_subjects"           200 '"related_subjects"'

req -H "$AUTH" "$BASE/v1/subjects?q=zzz_no_match_zzz"
assert "GET q no-match -> empty list"                200 '"subjects":[]'

req -H "$AUTH" "$BASE/v1/subjects?limit=1"
assert "GET limit=1 -> 200"                          200 '"subjects"'

# meta.debug only appears when the server has MALUDB_DEBUG=1; this just checks 200.
req -H "$AUTH" "$BASE/v1/subjects?debug=1"
assert "GET debug=1 -> 200"                          200 '"subjects"'

# --- POST validation (non-destructive: both fail before insert) -------------
req -X POST -H "$AUTH" -H "Content-Type: application/json" -d '{"type":"project"}' "$BASE/v1/subjects"
assert "POST missing label -> 400 missing_field"     400 '"missing_field"'

req -X POST -H "$AUTH" -H "Content-Type: application/json" -d '{bad json' "$BASE/v1/subjects"
assert "POST malformed JSON -> 400"                  400 '"body_invalid_json"'

# --- Method handling --------------------------------------------------------
req -X PATCH  -H "$AUTH" "$BASE/v1/subjects"
assert "PATCH collection -> 405"                     405 '"method_not_allowed"'

req -X PUT    -H "$AUTH" "$BASE/v1/subjects"
assert "PUT collection -> 405"                       405 '"method_not_allowed"'

req -X DELETE -H "$AUTH" "$BASE/v1/subjects"
assert "DELETE collection -> 405"                    405 '"method_not_allowed"'

# --- Write test (opt-in; inserts a real row) --------------------------------
if [ "${RUN_WRITE:-0}" = "1" ]; then
  label="regression-test-$(date +%s)"
  req -X POST -H "$AUTH" -H "Content-Type: application/json" \
      -d "{\"label\":\"$label\",\"type\":\"project\",\"description\":\"regression\"}" \
      "$BASE/v1/subjects"
  assert "POST create -> 201"                        201 "\"label\":\"$label\""
  newid="$(grep -o '"id":[0-9]*' "$body" | head -1 | grep -o '[0-9]*')"
  echo "        created subject id=${newid:-?} (label=$label)"
  echo "        cleanup (no DELETE endpoint yet): DELETE /v1/subjects/$newid once subjects_id.php lands, or"
  echo "        psql:  DELETE FROM maludb_subject WHERE canonical_name = '$label';"
fi

echo
echo "Subjects regression: $pass passed, $fail failed."
[ "$fail" = 0 ]
