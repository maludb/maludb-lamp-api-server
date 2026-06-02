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
// Run each CREATE TABLE statement from the .sql. Strip ALL -- comments (incl. inline) first —
// an inline comment can contain ';' which would otherwise break the statement split.
$ddl = preg_replace('/--.*$/m', '', file_get_contents(__DIR__ . '/../config/local-database.sql'));
foreach (array_filter(array_map('trim', explode(';', $ddl))) as $stmt) {
    if (stripos($stmt, 'CREATE TABLE') !== false) { $my->exec($stmt); }
}
// idempotent column adds (MariaDB supports IF NOT EXISTS)
$my->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS token_prefix VARCHAR(16) NULL AFTER token_hash");
$my->exec("ALTER TABLE model_prompts ADD COLUMN IF NOT EXISTS model_identifier VARCHAR(128) NULL AFTER model_name");
$my->exec("ALTER TABLE model_prompts ADD COLUMN IF NOT EXISTS generation_params JSON NULL");
echo "users + model_prompts tables ensured\n";

// Seed/refresh the default 'chatgpt-4o' prompt row from the versioned prompt file. The system
// prompt + generation params are refreshed on every run; the api_key is preserved (COALESCE) so
// re-running setup never clobbers a key set via POST /v1/model-prompts. KNOWN_SUBJECTS / KNOWN_VERBS
// / HINTS are injected into the USER message by the ingest endpoint (not into the system prompt).
$default_prompt = file_get_contents(__DIR__ . '/../config/prompts/chatgpt-4o.system.txt');
$gen = json_encode(['temperature' => 0.1, 'response_format' => ['type' => 'json_object']]);
$seed = $my->prepare(
    "INSERT INTO model_prompts (model_name, model_identifier, api_format, system_prompt, base_url, api_key, max_tokens, generation_params)
     VALUES ('chatgpt-4o','gpt-4o','openai',?,'https://api.openai.com/v1',NULL,2048,?)
     ON DUPLICATE KEY UPDATE model_identifier=VALUES(model_identifier), api_format=VALUES(api_format),
       system_prompt=VALUES(system_prompt), base_url=VALUES(base_url), max_tokens=VALUES(max_tokens),
       generation_params=VALUES(generation_params)"
);
$seed->execute([$default_prompt, $gen]);
echo "default chatgpt-4o prompt seeded/refreshed from config/prompts/chatgpt-4o.system.txt\n";

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
