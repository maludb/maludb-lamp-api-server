<?php
/**
 * Database Connection Test Script
 *
 * Tests database connectivity and displays information about the database
 */

// Include database configuration
require_once __DIR__ . '/../config/database.php';

// Enable error display for testing
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Connection Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .status-success { color: #26ba4f; }
        .status-error { color: #f87957; }
        .test-section { margin-bottom: 2rem; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div id="page-header" class="mb-4">
            <h1><i class="bi bi-database"></i> Database Connection Test</h1>
            <p class="text-muted">Testing connection to vibe_templates database</p>
        </div>

        <?php
        try {
            // Test 1: Database Connection
            echo '<div id="test-connection" class="test-section card">';
            echo '<div class="card-body">';
            echo '<h3 class="card-title"><i class="bi bi-1-circle"></i> Connection Status</h3>';

            $db = Database::getInstance();
            $conn = $db->getConnection();

            echo '<div class="alert alert-success">';
            echo '<i class="bi bi-check-circle-fill status-success"></i> ';
            echo '<strong>Success!</strong> Connected to database successfully.';
            echo '</div>';

            // Display connection info
            echo '<ul class="list-group">';
            echo '<li class="list-group-item"><strong>Driver:</strong> ' . $conn->getAttribute(PDO::ATTR_DRIVER_NAME) . '</li>';
            echo '<li class="list-group-item"><strong>Server Version:</strong> ' . $conn->getAttribute(PDO::ATTR_SERVER_VERSION) . '</li>';
            echo '<li class="list-group-item"><strong>Client Version:</strong> ' . $conn->getAttribute(PDO::ATTR_CLIENT_VERSION) . '</li>';
            echo '<li class="list-group-item"><strong>Connection Status:</strong> ' . $conn->getAttribute(PDO::ATTR_CONNECTION_STATUS) . '</li>';
            echo '</ul>';
            echo '</div></div>';

            // Test 2: List Tables
            echo '<div id="test-tables" class="test-section card">';
            echo '<div class="card-body">';
            echo '<h3 class="card-title"><i class="bi bi-2-circle"></i> Database Tables</h3>';

            $stmt = $conn->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($tables) > 0) {
                echo '<div class="alert alert-info">';
                echo 'Found <strong>' . count($tables) . '</strong> table(s) in the database.';
                echo '</div>';

                echo '<ul class="list-group">';
                foreach ($tables as $table) {
                    echo '<li class="list-group-item"><i class="bi bi-table"></i> ' . htmlspecialchars($table) . '</li>';
                }
                echo '</ul>';
            } else {
                echo '<div class="alert alert-warning">';
                echo '<i class="bi bi-exclamation-triangle"></i> No tables found in the database.';
                echo '</div>';
            }
            echo '</div></div>';

            // Test 3: Count Records in Key Tables
            echo '<div id="test-counts" class="test-section card">';
            echo '<div class="card-body">';
            echo '<h3 class="card-title"><i class="bi bi-3-circle"></i> Record Counts</h3>';

            $keyTables = ['users', 'tasks', 'teams'];
            $counts = [];

            echo '<div class="table-responsive">';
            echo '<table class="table table-striped">';
            echo '<thead><tr><th>Table</th><th>Record Count</th><th>Status</th></tr></thead>';
            echo '<tbody>';

            foreach ($keyTables as $table) {
                if (in_array($table, $tables)) {
                    $stmt = $conn->query("SELECT COUNT(*) as count FROM `$table`");
                    $count = $stmt->fetch()['count'];
                    $counts[$table] = $count;

                    echo '<tr>';
                    echo '<td><i class="bi bi-table"></i> ' . htmlspecialchars($table) . '</td>';
                    echo '<td><strong>' . number_format($count) . '</strong></td>';
                    echo '<td><i class="bi bi-check-circle status-success"></i> Exists</td>';
                    echo '</tr>';
                } else {
                    echo '<tr>';
                    echo '<td><i class="bi bi-table"></i> ' . htmlspecialchars($table) . '</td>';
                    echo '<td>-</td>';
                    echo '<td><i class="bi bi-x-circle status-error"></i> Not Found</td>';
                    echo '</tr>';
                }
            }

            echo '</tbody></table>';
            echo '</div>';
            echo '</div></div>';

            // Test 4: Simple SELECT Query
            echo '<div id="test-query" class="test-section card">';
            echo '<div class="card-body">';
            echo '<h3 class="card-title"><i class="bi bi-4-circle"></i> Test Query</h3>';

            // Try to query the users table if it exists
            if (in_array('users', $tables)) {
                $stmt = $conn->prepare("SELECT * FROM users LIMIT 5");
                $stmt->execute();
                $users = $stmt->fetchAll();

                echo '<div class="alert alert-success">';
                echo '<i class="bi bi-check-circle-fill"></i> Successfully executed SELECT query on users table.';
                echo '</div>';

                if (count($users) > 0) {
                    echo '<p><strong>Sample Data (first 5 records):</strong></p>';
                    echo '<div class="table-responsive">';
                    echo '<table class="table table-sm table-bordered">';
                    echo '<thead class="table-light"><tr>';

                    // Table headers
                    foreach (array_keys($users[0]) as $column) {
                        echo '<th>' . htmlspecialchars($column) . '</th>';
                    }
                    echo '</tr></thead><tbody>';

                    // Table data
                    foreach ($users as $user) {
                        echo '<tr>';
                        foreach ($user as $key => $value) {
                            // Hide password hashes for security
                            if (stripos($key, 'password') !== false) {
                                echo '<td><em>[hidden]</em></td>';
                            } else {
                                echo '<td>' . htmlspecialchars($value ?? '') . '</td>';
                            }
                        }
                        echo '</tr>';
                    }

                    echo '</tbody></table>';
                    echo '</div>';
                } else {
                    echo '<div class="alert alert-info">';
                    echo 'Users table exists but contains no records.';
                    echo '</div>';
                }
            } else {
                echo '<div class="alert alert-warning">';
                echo '<i class="bi bi-exclamation-triangle"></i> Users table not found. Cannot perform test query.';
                echo '</div>';
            }

            echo '</div></div>';

            // Summary
            echo '<div id="test-summary" class="card bg-success text-white">';
            echo '<div class="card-body">';
            echo '<h3 class="card-title"><i class="bi bi-check-circle-fill"></i> Test Summary</h3>';
            echo '<p class="mb-0">All database connection tests completed successfully!</p>';
            echo '</div></div>';

        } catch (Exception $e) {
            echo '<div id="error-message" class="alert alert-danger">';
            echo '<h4><i class="bi bi-x-circle-fill status-error"></i> Connection Failed</h4>';
            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<p class="mb-0"><small>Check your database credentials in /var/www/config/database.php</small></p>';
            echo '</div>';
        }
        ?>

        <div id="back-navigation" class="mt-4">
            <a href="index.php" class="btn btn-primary"><i class="bi bi-arrow-left"></i> Back to Home</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

