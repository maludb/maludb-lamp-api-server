<?php
/**
 * Local Database Configuration (MySQL / MariaDB)
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
    private const DB_NAME = 'maludb';
    private const DB_USER = 'maludb';
    // Same password as the Postgres connection in config/database.php (per deployment setup).
    private const DB_PASS = '!Meelup578Loipol229!';

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

    /** Next app user_id to assign when a token-create request doesn't supply one. */
    public static function nextUserId(): int {
        $n = self::getInstance()->getConnection()->query("SELECT COALESCE(MAX(user_id), 0) + 1 AS n FROM users")->fetchColumn();
        return (int) $n;
    }

    private function __clone() {}
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}
