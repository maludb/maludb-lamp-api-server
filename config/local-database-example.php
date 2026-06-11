<?php
/**
 * Local Database Configuration (MySQL / MariaDB) — EXAMPLE
 *
 * Copy this file to `config/local-database.php` and fill in your local auth-store
 * credentials. The real `config/local-database.php` is gitignored so secrets never
 * reach the repo.
 *
 *   cp config/local-database-example.php config/local-database.php
 *
 * PDO singleton to the LOCAL auth/routing store. One `users` row per API token carries the
 * user's role and the Postgres connection (DB_NAME / DB_USER / DB_PASS) that requests
 * authenticated by that token connect with. The token is stored only as a sha256 hash (of the
 * token after the `malu_` prefix). See config/local-database.sql for the schema.
 *
 * require_auth() (config/response.php) resolves the bearer token here, then calls
 * Database::configure(...) with the row's Postgres creds before any Postgres query runs.
 */

class LocalDatabase {
    private static $instance = null;
    private $connection;

    // Local MySQL credentials. Host is localhost (the auth store lives on the API box).
    private const DB_HOST = 'localhost';
    private const DB_PORT = '3306';
    private const DB_NAME = 'maludb_auth';          // must match config/local-database.sql
    private const DB_USER = 'maludb';               // the dedicated user created by that script
    private const DB_PASS = 'YOUR_AUTH_DB_PASSWORD'; // the password you set in that script

    private function __construct() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                self::DB_HOST, self::DB_PORT, self::DB_NAME
            );
            $this->connection = new PDO($dsn, self::DB_USER, self::DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
            ]);
        } catch (PDOException $e) {
            error_log('Local DB Connection Error: ' . $e->getMessage());
            throw new Exception('Local auth database connection failed.');
        }
    }

    public static function getInstance(): LocalDatabase {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->connection;
    }

    /**
     * Resolve a presented token's sha256 hash to its user row, or null if unknown/expired.
     * Returns ['user_id','role','pg_dbname','pg_user','pg_password'].
     */
    public static function resolveToken(string $token_hash): ?array {
        $stmt = self::getInstance()->getConnection()->prepare(
            'SELECT user_id, role, pg_dbname, pg_user, pg_password
               FROM users
              WHERE token_hash = ?
                AND (expires_at IS NULL OR expires_at > NOW())
              LIMIT 1'
        );
        $stmt->execute([$token_hash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Load the per-model extraction prompt + LLM connection, or null if the model has no row.
     * Returns ['model_name','api_format','system_prompt','base_url','api_key','max_tokens'].
     */
    public static function modelPrompt(string $model): ?array {
        $stmt = self::getInstance()->getConnection()->prepare(
            "SELECT model_name, model_identifier, api_format, system_prompt, base_url, api_key,
                    max_tokens, generation_params
               FROM model_prompts WHERE model_name = ? LIMIT 1"
        );
        $stmt->execute([$model]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Next app user_id to assign when a token-create request doesn't supply one. */
    public static function nextUserId(): int {
        $n = self::getInstance()->getConnection()->query("SELECT COALESCE(MAX(user_id), 0) + 1 AS n FROM users")->fetchColumn();
        return (int) $n;
    }

    /* -----------------------------------------------------------------------
     * Default-prompt catalog (seeded by tests/local_db_setup.php) + per-user
     * provider keys + per-user task → model choices. Backs /v1/llm/* and the
     * mem_resolve_task_config() resolver in config/llm.php.
     * --------------------------------------------------------------------- */

    /** Load the catalog row for (model_name, task), or null. */
    public static function defaultPrompt(string $model_name, string $task): ?array {
        $stmt = self::getInstance()->getConnection()->prepare(
            "SELECT provider, model_name, model_identifier, api_format, base_url,
                    task, system_prompt, max_tokens, generation_params
               FROM default_prompts
              WHERE model_name = ? AND task = ?
              LIMIT 1"
        );
        $stmt->execute([$model_name, $task]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** All catalog rows (without the prompt text — it can be large). */
    public static function listDefaultPrompts(): array {
        $rows = self::getInstance()->getConnection()->query(
            "SELECT provider, model_name, model_identifier, api_format, base_url,
                    task, max_tokens,
                    (system_prompt IS NOT NULL AND system_prompt <> '') AS has_system_prompt
               FROM default_prompts
              ORDER BY task, provider, model_name"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['max_tokens']        = (int) $r['max_tokens'];
            $r['has_system_prompt'] = (bool) $r['has_system_prompt'];
        }
        unset($r);
        return $rows;
    }

    /** Distinct providers present in the catalog. */
    public static function catalogProviders(): array {
        return self::getInstance()->getConnection()->query(
            "SELECT DISTINCT provider FROM default_prompts ORDER BY provider"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Distinct tasks present in the catalog. */
    public static function catalogTasks(): array {
        return self::getInstance()->getConnection()->query(
            "SELECT DISTINCT task FROM default_prompts ORDER BY task"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /** The user's key row for a provider (includes api_key — internal use only). */
    public static function userProviderKey(int $user_id, string $provider): ?array {
        $stmt = self::getInstance()->getConnection()->prepare(
            "SELECT provider, api_key, base_url FROM user_provider_keys
              WHERE user_id = ? AND provider = ? LIMIT 1"
        );
        $stmt->execute([$user_id, $provider]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** The user's providers — key value never selected, only key_set. */
    public static function listUserProviderKeys(int $user_id): array {
        $stmt = self::getInstance()->getConnection()->prepare(
            "SELECT provider,
                    (api_key IS NOT NULL AND api_key <> '') AS key_set,
                    base_url, updated_at
               FROM user_provider_keys
              WHERE user_id = ?
              ORDER BY provider"
        );
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { $r['key_set'] = (bool) $r['key_set']; }
        unset($r);
        return $rows;
    }

    /**
     * Insert or update a provider key. A null api_key on update preserves the stored key
     * (COALESCE — same convention as /v1/model-prompts).
     */
    public static function upsertUserProviderKey(int $user_id, string $provider, ?string $api_key, ?string $base_url): void {
        $stmt = self::getInstance()->getConnection()->prepare(
            "INSERT INTO user_provider_keys (user_id, provider, api_key, base_url)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE api_key = COALESCE(VALUES(api_key), api_key),
               base_url = VALUES(base_url)"
        );
        $stmt->execute([$user_id, $provider, $api_key, $base_url]);
    }

    /** Delete a provider key; returns true if a row was removed. */
    public static function deleteUserProviderKey(int $user_id, string $provider): bool {
        $stmt = self::getInstance()->getConnection()->prepare(
            "DELETE FROM user_provider_keys WHERE user_id = ? AND provider = ?"
        );
        $stmt->execute([$user_id, $provider]);
        return $stmt->rowCount() > 0;
    }

    /** The user's model choice for a task, or null. */
    public static function userModelChoice(int $user_id, string $task): ?array {
        $stmt = self::getInstance()->getConnection()->prepare(
            "SELECT task, model_name, system_prompt FROM user_model_choices
              WHERE user_id = ? AND task = ? LIMIT 1"
        );
        $stmt->execute([$user_id, $task]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** All of the user's task → model choices (no prompt text — only the override flag). */
    public static function listUserModelChoices(int $user_id): array {
        $stmt = self::getInstance()->getConnection()->prepare(
            "SELECT task, model_name,
                    (system_prompt IS NOT NULL AND system_prompt <> '') AS system_prompt_override,
                    updated_at
               FROM user_model_choices
              WHERE user_id = ?
              ORDER BY task"
        );
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { $r['system_prompt_override'] = (bool) $r['system_prompt_override']; }
        unset($r);
        return $rows;
    }

    /** Insert or replace the user's model choice for a task. */
    public static function upsertUserModelChoice(int $user_id, string $task, string $model_name, ?string $system_prompt): void {
        $stmt = self::getInstance()->getConnection()->prepare(
            "INSERT INTO user_model_choices (user_id, task, model_name, system_prompt)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE model_name = VALUES(model_name),
               system_prompt = VALUES(system_prompt)"
        );
        $stmt->execute([$user_id, $task, $model_name, $system_prompt]);
    }

    /** Delete the user's model choice for a task; true if a row was removed. */
    public static function deleteUserModelChoice(int $user_id, string $task): bool {
        $stmt = self::getInstance()->getConnection()->prepare(
            "DELETE FROM user_model_choices WHERE user_id = ? AND task = ?"
        );
        $stmt->execute([$user_id, $task]);
        return $stmt->rowCount() > 0;
    }

    private function __clone() {}
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}
