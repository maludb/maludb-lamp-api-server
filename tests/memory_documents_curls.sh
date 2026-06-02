# Regression curl commands — /v1/memory/documents  (maludb_core memory; endpoint group 2)
# Process: upload -> chunk -> extract (LLM, or caller-supplied "edges") -> embed -> ingest.
#
# This smoke uses caller-supplied edges + the deterministic local embedding (no live model
# creds), so the upload->ingest->search pipeline round-trips. Cleanup deletes the document and
# the subjects it created; NOTE the graph-bound vector store is append-only for the API role —
# the ingested vector chunks can only be GC'd by a superuser (tombstone_vector_chunk /
# malu$vector_chunk are owner-restricted here), so a couple of chunks linger in the test namespace.

TOK='Authorization: Bearer malu_devLOCALdevLOCALdevLOCALdevLOCALdevLOCAL123'
B='https://fastapi.maludb.org'
NS='apismoke'

# --- validation ---
curl -s -X POST "$B/v1/memory/documents" -H "$TOK" -H 'Content-Type: application/json' -d '{"text":"x"}'; echo   # missing title -> 400
curl -s -X POST "$B/v1/memory/documents" -H "$TOK" -H 'Content-Type: application/json' -d '{"title":"x"}'; echo   # missing text  -> 400
# no edges + no configured model -> 409 model_not_configured (would call the LLM otherwise)
curl -s -X POST "$B/v1/memory/documents" -H "$TOK" -H 'Content-Type: application/json' \
  -d '{"title":"x","text":"some text without provided edges","namespace":"'"$NS"'"}'; echo

# --- real ingest (provided edges + deterministic embedding) -> 201 ----------
RESP=$(curl -s -X POST "$B/v1/memory/documents" -H "$TOK" -H 'Content-Type: application/json' -d '{
  "title":"ZZ Mem Smoke","text":"Oracle 21c upgrade completed on 2026-03-30. Jane Doe approved the change.",
  "source_type":"document","namespace":"'"$NS"'","projects":["ZZ Mem Project"],
  "edges":[
    {"subject_text":"Oracle 21c","subject_type":"software","verb_text":"upgrade",
     "predicate":[{"attr_name":"status","value_text":"completed"}],
     "source_span":"Oracle 21c upgrade completed on 2026-03-30.","confidence":0.94},
    {"subject_text":"Jane Doe","subject_type":"person","verb_text":"approve",
     "predicate":[{"attr_name":"role","value_text":"approver"}],
     "source_span":"Jane Doe approved the change.","confidence":0.9}
  ]
}')
echo "$RESP"
DID=$(echo "$RESP" | grep -o '"document_id":[0-9]*' | head -1 | grep -o '[0-9]*')

# --- search it back (subject/verb pre-filter required) ----------------------
curl -s -X POST "$B/v1/memory/search" -H "$TOK" -H 'Content-Type: application/json' \
  -d '{"query":"Oracle 21c upgrade completed on 2026-03-30.","subject":"Oracle 21c","verb":"upgrade","namespace":"'"$NS"'","limit":5}'; echo

# --- best-effort cleanup: delete the document (removes doc + document->subject edges) --------
[ -n "$DID" ] && curl -s -X DELETE "$B/v1/documents/$DID" -H "$TOK"; echo
# delete the subjects it created
for NAME in 'Oracle%2021c' 'Jane%20Doe' 'ZZ%20Mem%20Project'; do
  SID=$(curl -s "$B/v1/subjects?q=$NAME&limit=1" -H "$TOK" | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
  [ -n "$SID" ] && curl -s -X DELETE "$B/v1/subjects/$SID" -H "$TOK" >/dev/null
done
echo "cleanup done (vector chunks in namespace $NS are append-only; superuser GC required)"
