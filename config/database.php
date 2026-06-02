<?php
/**
 * Database Configuration
 *
 * PDO singleton connection to PostgreSQL database
 * Provides secure, reusable database connection for the application
 */

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
            throw new Exception("Database connection failed. Please check your configuration.");
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
