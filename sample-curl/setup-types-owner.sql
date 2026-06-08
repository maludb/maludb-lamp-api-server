-- ============================================================================
-- setup-types-owner.sql  —  Register Life-Coach SUBJECT TYPES and VERB TYPES
-- ============================================================================
--
-- WHY THIS IS SQL AND NOT CURL:
--   Subject types and verb types are a CLOSED, DB-enforced vocabulary. The API
--   token's role (e.g. `zozocal`) cannot add them — every write path returns
--   `42501 insufficient_privilege`, and `/v1/subject-types` + `/v1/verb-types`
--   are GET-only. The base tables are owned by the extension owner role
--   (`maludb`). So new types must be registered by the OWNER, once, via SQL.
--   (The only API-side exception is that creating an EVENT auto-registers its
--   kind as a subject type — but that mints a stray event and gives no control
--   over display name / description, so it is not used here.)
--
-- HOW TO RUN (as the owner role, against the tenant database):
--   psql "host=<host> dbname=<tenant_db> user=maludb" -f setup-types-owner.sql
--
-- SCOPE: these tables live in the shared `maludb_core` schema, so the types are
--   visible to every tenant schema in THIS database. Re-running is safe — every
--   statement is ON CONFLICT DO NOTHING.
--
-- This is the prerequisite for `setup-structures-pathB.md` (first-class types).
-- `setup-structures-pathA.md` does NOT need it (it reuses the `concept` type).
-- ============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- SUBJECT TYPES  (the genuine NODE types for the coaching domain)
-- Attribute-like concepts (target, deadline, priority, goal_status, metric,
-- success_criteria) are intentionally NOT types — they are modeled as typed
-- ATTRIBUTES on a goal, not as separate nodes.
-- ---------------------------------------------------------------------------
INSERT INTO maludb_core."malu$svpor_subject_type"
    (subject_type, display_name, description, sort_order, system_defined)
VALUES
    ('goal',               'Goal',               'A declared objective the user wants to reach.',        300, false),
    ('outcome',            'Outcome',            'A desired end result or benefit of pursuing a goal.',  301, false),
    ('goal_category',      'Goal Category',      'Classification of a goal (health, career, learning).', 302, false),
    ('milestone',          'Milestone',          'An intermediate target on the way to a goal.',         303, false),
    ('motivation',         'Motivation',         'A reason the user wants something.',                   304, false),
    ('value',              'Value',              'A deeper guiding principle (family, mastery).',        305, false),
    ('identity_statement', 'Identity Statement', 'How the user currently sees themselves.',              306, false),
    ('desired_identity',   'Desired Identity',   'Who the user wants to become.',                        307, false),
    ('fear',               'Fear',               'A negative motivator the user wants to avoid.',        308, false),
    ('reward',             'Reward',             'A positive motivator the user is drawn toward.',       309, false),
    ('meaning',            'Meaning',            'Why a goal personally matters to the user.',           310, false),
    ('vision',             'Vision',             'A future-state description the user aspires to.',      311, false)
ON CONFLICT (subject_type) DO NOTHING;

-- ---------------------------------------------------------------------------
-- VERB TYPES  (semantic classes for the coaching verbs)
-- `semantic_class` is CHECK-constrained to:
--   action | state | event | decision | communication | verification |
--   failure | planning | documentation | other
-- ---------------------------------------------------------------------------
INSERT INTO maludb_core."malu$svpor_verb_type"
    (verb_type, display_name, semantic_class, description, sort_order, system_defined)
VALUES
    ('aspiration', 'Aspiration', 'planning',      'Wanting, intending, or targeting a future result.', 300, false),
    ('commitment', 'Commitment', 'planning',      'Committing to or scheduling an action.',            301, false),
    ('obstacle',   'Obstacle',   'state',         'Struggling with, blocked by, or constrained.',      302, false),
    ('motivation', 'Motivation', 'state',         'Being motivated by, valuing, or needing something.',303, false),
    ('emotion',    'Emotion',    'state',         'Feeling an emotional state.',                       304, false),
    ('preference', 'Preference', 'communication', 'Preferring, disliking, or opting in/out.',          305, false),
    ('progress',   'Progress',   'state',         'Advancing, completing, missing, or regressing.',    306, false),
    ('reflection', 'Reflection', 'documentation', 'Realizing, learning, believing, or deciding.',      307, false)
ON CONFLICT (verb_type) DO NOTHING;

COMMIT;

-- Verify (any role can read these views):
--   SELECT subject_type, sort_order FROM maludb_core.maludb_subject_type ORDER BY sort_order;
--   SELECT verb_type, semantic_class FROM maludb_core.maludb_verb_type ORDER BY sort_order;
-- Or over the API:
--   GET /v1/subject-types     GET /v1/verb-types
