-- Local MySQL (MariaDB) auth/routing store for the MaluDB API.
-- One row per API token: it carries the user's role and the Postgres connection
-- (DB_NAME / DB_USER / DB_PASS) that requests authenticated by that token connect with.
-- The token itself is stored only as a sha256 hash (of the token after the `malu_` prefix),
-- matching how the legacy Postgres api_tokens table hashed it. DB_HOST/DB_PORT stay constant
-- in config/database.php; only name/user/pass are resolved here.
--
-- ----------------------------------------------------------------------------
-- BOOTSTRAP: this script creates the local auth store from scratch — the
-- database, a dedicated (non-root) application user, the grants, and the tables.
-- Run it ONCE as the MySQL root user on a fresh install:
--
--     sudo mysql < config/local-database.sql
--
-- IMPORTANT: the database name, user, and password below MUST match the
-- DB_NAME / DB_USER / DB_PASS constants in config/local-database.php.
-- Change the placeholder password before running — never ship the default.
-- ----------------------------------------------------------------------------

-- 1. Database --------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS maludb_auth
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Dedicated application user (do NOT use root in config/local-database.php).
--    Change the password, then mirror it into config/local-database.php.
CREATE USER IF NOT EXISTS 'maludb'@'localhost'
    IDENTIFIED BY 'CHANGE_ME_AUTH_DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON maludb_auth.* TO 'maludb'@'localhost';
FLUSH PRIVILEGES;

-- 3. Tables ----------------------------------------------------------------
USE maludb_auth;

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
    model_name       VARCHAR(128) PRIMARY KEY,    -- lookup key (the `model` request value)
    model_identifier VARCHAR(128) NULL,           -- actual API model id (e.g. 'gpt-4o'); defaults to model_name
    api_format       VARCHAR(16)  NOT NULL DEFAULT 'openai',   -- 'openai' | 'anthropic'
    system_prompt    MEDIUMTEXT   NOT NULL,
    base_url         VARCHAR(255) NOT NULL,
    api_key          VARCHAR(255) NULL,
    max_tokens       INT          NOT NULL DEFAULT 2048,
    generation_params JSON        NULL,            -- merged into the request body (e.g. temperature, response_format)
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
