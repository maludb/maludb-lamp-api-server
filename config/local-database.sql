-- Local MySQL (MariaDB) auth/routing store for the MaluDB API.
-- One row per API token: it carries the user's role and the Postgres connection
-- (DB_NAME / DB_USER / DB_PASS) that requests authenticated by that token connect with.
-- The token itself is stored only as a sha256 hash (of the token after the `malu_` prefix),
-- matching how the legacy Postgres api_tokens table hashed it. DB_HOST/DB_PORT stay constant
-- in config/database.php; only name/user/pass are resolved here.

CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    token_hash  CHAR(64)     NOT NULL,
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
