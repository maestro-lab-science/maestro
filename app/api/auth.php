<?php
/**
 * api/auth.php — create a project or unlock an existing one.
 *
 * Actions (POST JSON): create { project_id, password, name } · open { project_id, password }
 * A successful create/open marks the project as unlocked in the PHP session,
 * so the settings/data pages and their APIs work without re-sending the
 * password. When a researcher is signed in, the project is also kept in
 * their account.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/script_default.php';
require_once __DIR__ . '/../includes/account.php';

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST required', 405);
}

$action  = $_GET['action'] ?? '';
$payload = read_json_body();

$project_id = sanitize_project_id((string)($payload['project_id'] ?? ''));
$password   = trim((string)($payload['password'] ?? ''));

try {
    if ($action === 'create') {
        $name = trim((string)($payload['name'] ?? ''));
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 80, 'UTF-8');
        } else {
            $name = substr($name, 0, 80);
        }
        if ($project_id === '') {
            json_fail('Study ID must be 16 alphanumeric characters');
        }
        if (strlen($password) < 8) {
            json_fail('Password must be at least 8 characters');
        }
        if (strlen($password) > 255) {
            json_fail('Password must be at most 255 characters');
        }

        if (load_project($project_id)) {
            json_fail('This study ID already exists', 409);
        }

        $hash   = password_hash($password, PASSWORD_DEFAULT);
        $empty  = '';
        $script = default_conversation_script(); // four opening lines, so Step 3 never starts empty
        $model  = OPENAI_MODEL;
        $stmt   = db()->prepare(
            'INSERT INTO projects
               (project_id, name, password_hash, participant_instructions, director_instructions, conversation_script, model)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('sssssss', $project_id, $name, $hash, $empty, $empty, $script, $model);
        $stmt->execute();
        $stmt->close();

        session_grant($project_id);
        $user = account_boot();
        if ($user) {
            account_link_project((int)$user['id'], $project_id, $password);
        }
        json_out(['ok' => true, 'project_id' => $project_id, 'name' => $name, 'in_account' => (bool)$user]);
    }

    if ($action === 'open') {
        if ($project_id === '') {
            json_fail('Study ID must be 16 alphanumeric characters');
        }
        if ($password === '') {
            json_fail('Password required');
        }
        if (password_locked('project:' . $project_id)) {
            json_fail(password_locked_message(), 429);
        }
        $project = load_project($project_id);
        if (!$project || !password_attempt($password, (string)$project['password_hash'], 'project:' . $project_id)) {
            json_fail('Invalid study ID or password', 403);
        }
        session_grant($project_id);
        $user = account_boot();
        // Opening someone else's project while signed in adds it to the
        // account — that is how foreign studies are collected (never examples).
        $linked = $user && account_link_project((int)$user['id'], $project_id, $password);
        json_out(['ok' => true, 'project_id' => $project_id, 'name' => (string)$project['name'], 'in_account' => $linked]);
    }

    json_fail('Unsupported action');
} catch (mysqli_sql_exception $e) { // before RuntimeException: it is a subclass of it
    json_fail('Database error: ' . $e->getMessage(), 500);
} catch (RuntimeException $e) {
    json_fail($e->getMessage(), 500);
}
