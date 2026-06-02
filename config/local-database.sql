-- Local MySQL (MariaDB) auth/routing store for the MaluDB API.
-- One row per API token: it carries the user's role and the Postgres connection
-- (DB_NAME / DB_USER / DB_PASS) that requests authenticated by that token connect with.
-- The token itself is stored only as a sha256 hash (of the token after the `malu_` prefix),
-- matching how the legacy Postgres api_tokens table hashed it. DB_HOST/DB_PORT stay constant
-- in config/database.php; only name/user/pass are resolved here.

CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    token_hash  CHAR(64)     NOT NULL,
    token_prefix VARCHAR(16) NULL,            -- first chars of the token, for diagnostics/listing
    user_id     INT          NOT NULL,
    role        VARCHAR(64)  NOT NULL DEFAULT 'executor',
    pg_dbname   VARCHAR(128) NOT NULL,
    pg_user     VARCHAR(128) NOT NULL,
    pg_password VARCHAR(255) NOT NULL,
    expires_at  DATETIME     NULL,
    device_name VARCHAR(128) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-model extraction prompts + LLM connection. The system prompt may differ slightly per model;
-- api_format selects the request shape (OpenAI chat vs Anthropic messages). The prompt contains
-- placeholders the /v1/memory/ingest endpoint fills before sending: {{verbs}}, {{verb_types}},
-- {{subjects}}, {{subject_types}}, {{hints}}. base_url + api_key are the LLM connection.
CREATE TABLE IF NOT EXISTS model_prompts (
    model_name    VARCHAR(128) PRIMARY KEY,
    api_format    VARCHAR(16)  NOT NULL DEFAULT 'openai',   -- 'openai' | 'anthropic'
    system_prompt MEDIUMTEXT   NOT NULL,
    base_url      VARCHAR(255) NOT NULL,
    api_key       VARCHAR(255) NULL,
    max_tokens    INT          NOT NULL DEFAULT 2048,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
