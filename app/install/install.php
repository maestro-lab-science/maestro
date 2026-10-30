<?php
/**
 * install.php — creates the aiteam_studio database and its tables, and adds
 * missing columns to older databases.
 *
 * Usage:
 *   php install/install.php
 *
 * The database name and credentials come from includes/config.php
 * (override them in includes/config.local.php). The table definitions live in
 * schema.sql; this script substitutes the configured database name.
 *
 * @author Marc Becker
 */

if (PHP_SAPI !== 'cli') {
    // Allow running from the browser during local setup, but keep output plain.
    header('Content-Type: text/plain; charset=utf-8');
    // STDERR exists on the command line only: in the browser, errors go to the page.
    define('STDERR', fopen('php://output', 'w'));
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/team.php';

echo "MAESTRO installer\n";
echo "========================\n";
echo 'Target database: ' . DB_NAME . ' @ ' . DB_HOST . ':' . DB_PORT . ' (user ' . DB_USER . ")\n\n";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, '', (int)DB_PORT);
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, "Could not connect to MySQL: {$e->getMessage()}\n");
    fwrite(STDERR, "Check that MySQL is running and the credentials in includes/config(.local).php are correct.\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

// ---- Run schema.sql (with the configured DB name) ---------------------------
$schema = file_get_contents(__DIR__ . '/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "schema.sql not found next to install.php\n");
    exit(1);
}
$schema = str_replace('aiteam_studio', DB_NAME, $schema);

// Strip "--" comments, then split on semicolons (schema.sql contains no string
// literals with semicolons, so a simple split is safe).
$lines = array_filter(array_map('rtrim', explode("\n", $schema)), fn($l) => !str_starts_with(ltrim($l), '--'));
$statements = array_filter(array_map('trim', explode(';', implode("\n", $lines))));

foreach ($statements as $sql) {
    $mysqli->query($sql);
    $first = strtok($sql, " \n");
    $second = strtok(" \n");
    echo "  ✓ {$first} {$second}…\n";
}
$mysqli->select_db(DB_NAME);
echo "\nDatabase and tables are ready.\n";

// ---- Migrations -------------------------------------------------------------
// Idempotent column additions so re-running install.php upgrades an existing
// database. Fresh installs already get these from schema.sql.
$existing = [];
$res = $mysqli->query('SHOW COLUMNS FROM projects');
while ($row = $res->fetch_assoc()) {
    $existing[$row['Field']] = true;
}
$res->free();

$migrations = [
    'show_instructions' => "ALTER TABLE projects ADD COLUMN show_instructions TINYINT(1) NOT NULL DEFAULT 0 AFTER participant_instructions",
    'api_provider'      => "ALTER TABLE projects ADD COLUMN api_provider VARCHAR(16) NOT NULL DEFAULT 'openai' AFTER timer_duration_seconds",
    'reasoning_effort'  => "ALTER TABLE projects ADD COLUMN reasoning_effort VARCHAR(16) NOT NULL DEFAULT 'medium' AFTER model",
    'api_endpoint'      => "ALTER TABLE projects ADD COLUMN api_endpoint VARCHAR(255) NOT NULL DEFAULT '' AFTER reasoning_effort",
    'api_key'           => "ALTER TABLE projects ADD COLUMN api_key VARCHAR(512) NOT NULL DEFAULT '' AFTER api_endpoint",
    'director_effort'   => "ALTER TABLE projects ADD COLUMN director_effort VARCHAR(16) NOT NULL DEFAULT 'minimal' AFTER reasoning_effort",
    'api_format'        => "ALTER TABLE projects ADD COLUMN api_format VARCHAR(16) NOT NULL DEFAULT 'auto' AFTER model",
    'team_json'         => "ALTER TABLE projects ADD COLUMN team_json MEDIUMTEXT NULL AFTER conversation_script",
    'study_context'     => "ALTER TABLE projects ADD COLUMN study_context TEXT NULL AFTER conversation_script",
    'is_paused'         => "ALTER TABLE projects ADD COLUMN is_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER timer_duration_seconds",
    'paused_at'         => "ALTER TABLE projects ADD COLUMN paused_at DATETIME NULL AFTER is_paused",
    'show_chat_instructions' => "ALTER TABLE projects ADD COLUMN show_chat_instructions TINYINT(1) NOT NULL DEFAULT 0 AFTER timer_duration_seconds",
    'chat_instructions'      => "ALTER TABLE projects ADD COLUMN chat_instructions MEDIUMTEXT NULL AFTER show_chat_instructions",
    'key_test'               => "ALTER TABLE projects ADD COLUMN key_test TEXT NULL AFTER api_key",
    'director_sections'      => "ALTER TABLE projects ADD COLUMN director_sections MEDIUMTEXT NULL AFTER director_instructions",
    'assistant_keys'         => "ALTER TABLE projects ADD COLUMN assistant_keys TEXT NULL AFTER key_test",
];
$migrated = 0;
foreach ($migrations as $column => $sql) {
    if (!isset($existing[$column])) {
        $mysqli->query($sql);
        echo "  + added projects.{$column}\n";
        $migrated++;
    }
}

// team_json holds small avatar pictures (data URLs) since 2026-09: TEXT (64 KB)
// is too small for a full team with pictures.
$res = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'team_json'");
$teamCol = $res->fetch_assoc();
$res->free();
if ($teamCol && strtolower((string)$teamCol['Type']) === 'text') {
    $mysqli->query("ALTER TABLE projects MODIFY team_json MEDIUMTEXT NULL");
    echo "  + projects.team_json is now MEDIUMTEXT (room for avatar pictures)\n";
    $migrated++;
}

// Session-flow options are OFF by default for new projects (existing rows keep
// their values — these only change the column defaults). Idempotent.
$mysqli->query("ALTER TABLE projects ALTER COLUMN show_ready_modal SET DEFAULT 0");
$mysqli->query("ALTER TABLE projects ALTER COLUMN timer_enabled SET DEFAULT 0");
$mysqli->query("ALTER TABLE projects ALTER COLUMN show_instructions SET DEFAULT 0");
$mysqli->query("ALTER TABLE projects ALTER COLUMN reasoning_effort SET DEFAULT 'medium'");
echo "  ✓ session-flow defaults set to off\n";

// messages: client-generated id + unique key for idempotent saves; sender as
// plain text (the original ENUM only knew three teammates)
$existingMsg = [];
$res = $mysqli->query('SHOW COLUMNS FROM messages');
while ($row = $res->fetch_assoc()) {
    $existingMsg[$row['Field']] = strtolower((string)$row['Type']);
}
$res->free();
if (!isset($existingMsg['client_id'])) {
    $mysqli->query("ALTER TABLE messages ADD COLUMN client_id VARCHAR(40) NULL AFTER conversation_id");
    $mysqli->query("ALTER TABLE messages ADD UNIQUE KEY uq_client_message (project_id, conversation_id, client_id)");
    echo "  + added messages.client_id (+ unique key)\n";
    $migrated++;
}
if (str_starts_with($existingMsg['sender'] ?? '', 'enum')) {
    $mysqli->query("ALTER TABLE messages MODIFY sender VARCHAR(16) NOT NULL");
    echo "  + messages.sender is now VARCHAR(16) (teams of up to " . TEAM_MAX . " teammates)\n";
    $migrated++;
}

// director_events: keep the model's plan for auditing; link calls to the
// participant message that triggered them
$existingEv = [];
$res = $mysqli->query('SHOW COLUMNS FROM director_events');
while ($row = $res->fetch_assoc()) {
    $existingEv[$row['Field']] = true;
}
$res->free();
if (!isset($existingEv['output_json'])) {
    $mysqli->query("ALTER TABLE director_events ADD COLUMN output_json TEXT NULL AFTER output_chars");
    echo "  + added director_events.output_json\n";
    $migrated++;
}
if (!isset($existingEv['message_client_id'])) {
    $mysqli->query("ALTER TABLE director_events ADD COLUMN message_client_id VARCHAR(40) NULL AFTER conversation_id");
    echo "  + added director_events.message_client_id\n";
    $migrated++;
}
// Token usage per call (data page: tokens and estimated costs)
// (added in reverse, each right after output_chars, so they end up in reading order)
foreach (['cache_write_tokens', 'cached_tokens', 'output_tokens', 'input_tokens'] as $col) {
    if (!isset($existingEv[$col])) {
        $mysqli->query("ALTER TABLE director_events ADD COLUMN {$col} INT NULL AFTER output_chars");
        echo "  + added director_events.{$col}\n";
        $migrated++;
    }
}

// users-table migrations (table exists after schema.sql ran above)
$existingUsers = [];
$res = $mysqli->query('SHOW COLUMNS FROM users');
while ($row = $res->fetch_assoc()) {
    $existingUsers[$row['Field']] = true;
}
$res->free();
if (!isset($existingUsers['display_name'])) {
    $mysqli->query("ALTER TABLE users ADD COLUMN display_name VARCHAR(80) NOT NULL DEFAULT '' AFTER email");
    echo "  + added users.display_name\n";
    $migrated++;
}

echo $migrated ? "Schema upgraded ({$migrated} column(s) added).\n" : "Schema is up to date.\n";

$mysqli->close();
echo "\nDone. Start the app from the project root with:  php -S localhost:8020 -t .  (see SELF-HOSTING.md)\n";
