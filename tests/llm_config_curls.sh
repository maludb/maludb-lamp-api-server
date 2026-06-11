# Regression curl commands — /v1/llm/*  (per-user LLM config: seeded catalog, provider keys,
# task → model choices). All reads/writes hit only the local MySQL auth store (default_prompts /
# user_provider_keys / user_model_choices) — no tenant Postgres writes — and the script deletes
# the dummy key/choices it creates, so it is safe to re-run.
#
# Requires the seeded catalog (run `php tests/local_db_setup.php` once). Usage:
#
#   TOKEN=malu_... [BASE=https://fastapi.maludb.org] bash tests/llm_config_curls.sh

TOKEN="${TOKEN:-malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123}"
B="${BASE:-https://fastapi.maludb.org}"
AUTH="Authorization: Bearer $TOKEN"
JSON='Content-Type: application/json'

pass=0; fail=0
check () {  # check <label> <haystack> <needle>...  — every needle must appear
    local label="$1" hay="$2"; shift 2
    local needle
    for needle in "$@"; do
        if ! printf '%s' "$hay" | grep -q -- "$needle"; then
            echo "FAIL  $label  (missing: $needle)"
            echo "      got: $hay"
            fail=$((fail + 1)); return
        fi
    done
    echo "ok    $label"; pass=$((pass + 1))
}
absent () {  # absent <label> <haystack> <needle> — the needle must NOT appear
    if printf '%s' "$2" | grep -q -- "$3"; then
        echo "FAIL  $1  (must not contain: $3)"; fail=$((fail + 1))
    else
        echo "ok    $1"; pass=$((pass + 1))
    fi
}

# clean slate for the rows this script writes (ignore output; 404s are fine)
curl -s -X DELETE "$B/v1/llm/providers/openai"     -H "$AUTH" > /dev/null
curl -s -X DELETE "$B/v1/llm/providers/anthropic"  -H "$AUTH" > /dev/null
curl -s -X DELETE "$B/v1/llm/models/extract"       -H "$AUTH" > /dev/null
curl -s -X DELETE "$B/v1/llm/models/skill_extract" -H "$AUTH" > /dev/null
curl -s -X DELETE "$B/v1/llm/models/embed"         -H "$AUTH" > /dev/null

# --- GET /v1/llm/catalog -> 200 {"tasks":[...],"models":[...]} (seeded matrix) ---------------
R=$(curl -s "$B/v1/llm/catalog" -H "$AUTH")
check "catalog lists seeded models x tasks" "$R" '"tasks"' '"extract"' '"skill_extract"' '"embed"' \
    '"gpt-4o"' '"claude-sonnet"' '"has_system_prompt"' '"key_set"' '"is_choice"'

# --- no token -> 401 -------------------------------------------------------------------------
C=$(curl -s -o /dev/null -w '%{http_code}' "$B/v1/llm/catalog")
check "catalog without token -> 401" "$C" '401'

# --- wrong methods -> 405 --------------------------------------------------------------------
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/v1/llm/catalog" -H "$AUTH")
check "POST catalog -> 405" "$C" '405'
C=$(curl -s -o /dev/null -w '%{http_code}' "$B/v1/llm/providers/openai" -H "$AUTH")
check "GET providers/{provider} -> 405" "$C" '405'

# --- PUT /v1/llm/providers/openai (first set) -> {"provider":{...,"key_set":true}} -----------
R=$(curl -s -X PUT "$B/v1/llm/providers/openai" -H "$AUTH" -H "$JSON" \
        -d '{"api_key":"sk-curltest-SECRET-123"}')
check "store openai key" "$R" '"provider":"openai"' '"key_set":true'
absent "key value never echoed back" "$R" 'sk-curltest-SECRET-123'

# --- key-preserving update: omit api_key, set base_url only -> key stays set -----------------
R=$(curl -s -X PUT "$B/v1/llm/providers/openai" -H "$AUTH" -H "$JSON" \
        -d '{"base_url":"https://proxy.example.com/v1"}')
check "update without api_key preserves the key" "$R" '"key_set":true' 'proxy.example.com'

# --- GET /v1/llm/providers -> list with key_set, never the key -------------------------------
R=$(curl -s "$B/v1/llm/providers" -H "$AUTH")
check "providers list" "$R" '"providers"' '"provider":"openai"' '"key_set":true'
absent "providers list never carries api_key" "$R" 'api_key'

# --- unknown provider -> 422 validation_failed ; first set without api_key -> 400 ------------
R=$(curl -s -X PUT "$B/v1/llm/providers/nope" -H "$AUTH" -H "$JSON" -d '{"api_key":"x"}')
check "unknown provider -> validation_failed" "$R" '"validation_failed"' 'Known providers'
R=$(curl -s -X PUT "$B/v1/llm/providers/anthropic" -H "$AUTH" -H "$JSON" -d '{}')
check "first set without api_key -> missing_field" "$R" '"missing_field"'

# --- GET /v1/llm/models -> effective defaults (extract falls back to chatgpt-4o) -------------
R=$(curl -s "$B/v1/llm/models" -H "$AUTH")
check "models shows legacy extract default" "$R" '"task":"extract"' '"chatgpt-4o"' '"chosen":false'

# --- PUT /v1/llm/models/extract -> choice stored (provider key already set: no warning) ------
R=$(curl -s -X PUT "$B/v1/llm/models/extract" -H "$AUTH" -H "$JSON" -d '{"model_name":"gpt-4o"}')
check "choose gpt-4o for extract" "$R" '"choice"' '"task":"extract"' '"model_name":"gpt-4o"' '"key_set":true'
absent "no warning when the key is set" "$R" '"warning"'

# --- choice without a key -> stored, with warning ---------------------------------------------
R=$(curl -s -X PUT "$B/v1/llm/models/skill_extract" -H "$AUTH" -H "$JSON" -d '{"model_name":"claude-haiku"}')
check "choice without key carries a warning" "$R" '"model_name":"claude-haiku"' '"key_set":false' '"warning"'

# --- catalog now reflects key_set + is_choice -------------------------------------------------
R=$(curl -s "$B/v1/llm/catalog" -H "$AUTH")
check "catalog reflects the caller state" "$R" '"is_choice":true' '"key_set":true'

# --- validation: unknown model -> 422 ; missing model_name -> 400 -----------------------------
R=$(curl -s -X PUT "$B/v1/llm/models/extract" -H "$AUTH" -H "$JSON" -d '{"model_name":"made-up"}')
check "unknown model -> validation_failed" "$R" '"validation_failed"' '/v1/llm/catalog'
R=$(curl -s -X PUT "$B/v1/llm/models/extract" -H "$AUTH" -H "$JSON" -d '{}')
check "missing model_name -> missing_field" "$R" '"missing_field"'

# --- DELETE choices -> deleted ; repeat -> 404 -------------------------------------------------
R=$(curl -s -X DELETE "$B/v1/llm/models/skill_extract" -H "$AUTH")
check "delete skill_extract choice" "$R" '"deleted":true' '"task":"skill_extract"'
R=$(curl -s -X DELETE "$B/v1/llm/models/skill_extract" -H "$AUTH")
check "delete again -> not_found" "$R" '"not_found"'
curl -s -X DELETE "$B/v1/llm/models/extract" -H "$AUTH" > /dev/null

# --- DELETE provider key -> deleted ; repeat -> 404 --------------------------------------------
R=$(curl -s -X DELETE "$B/v1/llm/providers/openai" -H "$AUTH")
check "delete openai key" "$R" '"deleted":true' '"provider":"openai"'
R=$(curl -s -X DELETE "$B/v1/llm/providers/openai" -H "$AUTH")
check "delete again -> not_found" "$R" '"not_found"'

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
