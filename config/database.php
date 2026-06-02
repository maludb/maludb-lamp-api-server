<?php
/**
 * Database Configuration
 *
 * PDO singleton connection to PostgreSQL database
 * Provides secure, reusable database connection for the application
 */

/**
 * Raised when the tenant Postgres connection itself fails (e.g. the password stored in the local
 * MySQL `users` row is stale/wrong, or the database is unreachable). Carries the Postgres SQLSTATE
 * so the global error handler can return a clear 502/503 instead of an opaque 500.
 */
class TenantDatabaseException extends RuntimeException {
    public string $sqlstate;
    public bool $isAuthFailure;
    public function __construct(string $message, string $sqlstate, bool $isAuthFailure) {
        parent::__construct($message);
        $this->sqlstate = $sqlstate;
        $this->isAuthFailure = $isAuthFailure;
    }
}

class Database {
    private static $instance = null;
    private $connection;

    // Host/port are fixed for the deployment; the database name, user and password are
    // resolved per-request from the local MySQL auth store (keyed by API token) and supplied
    // via configure() before the first connection. See config/local-database.php + require_auth().
    private const DB_HOST = '192.168.100.163';
    private const DB_PORT = '5432';
    private static $dbName = null;
    private static $dbUser = null;
    private static $dbPass = null;

    /**
     * Set the Postgres connection credentials for this request (called by require_auth() after
     * resolving the API token against the local MySQL store). Drops any existing connection so
     * the next getInstance() reconnects with the supplied credentials.
     */
    public static function configure(string $dbName, string $dbUser, string $dbPass): void {
        self::$dbName = $dbName;
        self::$dbUser = $dbUser;
        self::$dbPass = $dbPass;
        self::$instance = null;
    }

    /**
     * Verify a set of Postgres credentials by attempting a connection (host/port are the fixed
     * deployment values). Returns true if the login succeeds. Used by the token-issuing endpoint:
     * knowing a working Postgres login authorizes minting/managing tokens for that connection.
     */
    public static function testCredentials(string $dbName, string $dbUser, string $dbPass): bool {
        try {
            $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s;sslmode=disable", self::DB_HOST, self::DB_PORT, $dbName);
            new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        if (self::$dbName === null) {
            throw new Exception('Database not configured — call Database::configure() (require_auth resolves it from the API token).');
        }
        try {
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;sslmode=disable",
                self::DB_HOST,
                self::DB_PORT,
                self::$dbName
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
            ];

            $this->connection = new PDO($dsn, self::$dbUser, self::$dbPass, $options);

        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            // Classify so the handler can return a rejected-credential (502) vs unreachable (503).
            // PDO's pgsql driver surfaces "password authentication failed" as a generic connection
            // failure (08006), not 28P01, so detect auth failures by message as well as SQLSTATE.
            $msg = $e->getMessage();
            $sqlstate = (is_array($e->errorInfo ?? null) && isset($e->errorInfo[0]) && $e->errorInfo[0] !== '')
                ? (string) $e->errorInfo[0]
                : (preg_match('/SQLSTATE\[([0-9A-Za-z]{5})\]/', $msg, $m) ? $m[1] : (string) $e->getCode());
            $isAuth = str_starts_with($sqlstate, '28') || stripos($msg, 'authentication failed') !== false;
            throw new TenantDatabaseException('Tenant database connection failed.', $sqlstate, $isAuth);
        }
    }

    /**
     * Get singleton instance of Database
     *
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get PDO connection
     *
     * @return PDO
     */
    public function getConnection() {
        return $this->connection;
    }

    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the instance
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
