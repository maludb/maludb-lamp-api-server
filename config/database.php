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

    // Database credentials
    private const DB_HOST = '192.168.100.163';
    private const DB_PORT = '5432';
    private const DB_NAME = 'zozocal';
    private const DB_USER = 'zozocal';
    private const DB_PASS = '!Meelup578Loipol229!';

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        try {
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;sslmode=disable",
                self::DB_HOST,
                self::DB_PORT,
                self::DB_NAME
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
            ];

            $this->connection = new PDO($dsn, self::DB_USER, self::DB_PASS, $options);

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
