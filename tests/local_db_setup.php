<?php
/**
 * One-time (idempotent) provisioning for the local MySQL auth/routing store.
 *
 *   php tests/local_db_setup.php
 *
 * Creates the `users` table (from config/local-database.sql) and migrates every row from the
 * Postgres `api_tokens` table into it — preserving the sha256 token_hash so existing tokens keep
 * authenticating — attaching the Postgres connection (DB_NAME/USER/PASS) the API connects with.
 * Re-runnable: ON DUPLICATE KEY UPDATE refreshes existing rows. Edit the seed creds below if a
 * token should map to a different tenant database.
 */

$PG_HOST = '192.168.100.163';
$PG_PORT = '5432';
$PG_DB   = 'zozocal';
$PG_USER = 'zozocal';
$PG_PASS = '!Meelup578Loipol229!';   // same password used by config/database.php + local-database.php

$MY_DSN  = 'mysql:host=localhost;port=3306;dbname=maludb;charset=utf8mb4';
$MY_USER = 'maludb';
$MY_PASS = $PG_PASS;

$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

$pg = new PDO("pgsql:host=$PG_HOST;port=$PG_PORT;dbname=$PG_DB;sslmode=disable", $PG_USER, $PG_PASS, $opts);
$my = new PDO($MY_DSN, $MY_USER, $MY_PASS, $opts);

// 1. ensure schema (strip -- comment lines from the .sql, run the CREATE TABLE)
$ddl = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../config/local-database.sql'));
$my->exec(trim($ddl));
echo "users table ensured\n";

// 2. migrate Postgres api_tokens → MySQL users (by hash; attach Postgres creds + role)
$tokens = $pg->query("SELECT user_id, token_hash, expires_at FROM api_tokens")->fetchAll();
$ins = $my->prepare(
    "INSERT INTO users (token_hash, user_id, role, pg_dbname, pg_user, pg_password, expires_at, device_name)
     VALUES (?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), role=VALUES(role), pg_dbname=VALUES(pg_dbname),
       pg_user=VALUES(pg_user), pg_password=VALUES(pg_password), expires_at=VALUES(expires_at)"
);
foreach ($tokens as $t) {
    $exp = $t['expires_at'] ? date('Y-m-d H:i:s', strtotime($t['expires_at'])) : null;
    $ins->execute([$t['token_hash'], (int) $t['user_id'], 'executor', $PG_DB, $PG_USER, $PG_PASS, $exp, 'migrated-from-postgres']);
    echo "seeded user_id={$t['user_id']} (" . substr($t['token_hash'], 0, 12) . "...)\n";
}
echo "done: " . count($tokens) . " token(s) migrated\n";
