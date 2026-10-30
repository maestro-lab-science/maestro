<?php
/**
 * api/duplicate_project.php — copy a study (settings, "Duplicate").
 *
 * POST { project_id }
 * Creates a new project with a new ID and every setting of the stored project:
 * study context, participants, sample conversation, director instructions,
 * session flow and AI model (including its API key). The copy has the same
 * password, starts active, and contains no data (no messages, no model calls).
 * Its name gets " (copy)" appended. The new project is unlocked in this
 * browser session and, for a signed-in researcher whose account holds the
 * original's password, added to the account too.
 *
 * Reply: { ok: true, project_id, name }
 * Access: the unlocked settings session, or the project password.
 * (Visitors copy the example studies through settings.php?example=ID instead,
 * which stores the copy on its first Save.)
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/account.php';

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST required', 405);
}

$payload    = read_json_body();
$project_id = sanitize_project_id((string)($payload['project_id'] ?? ''));
if ($project_id === '') {
    json_fail('project_id required');
}

/** A new random project ID (16 alphanumeric characters) that is not taken yet. */
function duplicate_new_id(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    for ($try = 0; $try < 10; $try++) {
        $id = '';
        for ($i = 0; $i < 16; $i++) {
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (!load_project($id)) {
            return $id;
        }
    }
    throw new RuntimeException('Could not create a new study ID. Please try again.');
}

try {
    $project = load_project($project_id);
    if (!$project) {
        json_fail('Unknown study', 404);
    }
    $authorized = session_authorized($project_id);
    if (!$authorized) {
        $password = trim((string)($payload['password'] ?? ''));
        if (password_attempt($password, (string)$project['password_hash'], 'project:' . $project_id)) {
            session_grant($project_id);
            $authorized = true;
        }
    }
    if (!$authorized) {
        json_fail('Session expired. Please reopen the study from the Studies page.', 403);
    }

    $newId = duplicate_new_id();
    $base  = trim((string)$project['name']) !== '' ? trim((string)$project['name']) : $project_id;
    $name  = (function_exists('mb_substr') ? mb_substr($base, 0, 73, 'UTF-8') : substr($base, 0, 73)) . ' (copy)';

    // Every configuration column is copied as stored; data and the pause state are not.
    $columns = ['password_hash', 'participant_instructions', 'show_instructions', 'director_instructions', 'director_sections', 'conversation_script',
        'study_context', 'team_json', 'show_ready_modal', 'ready_wait_seconds', 'timer_enabled', 'timer_duration_seconds',
        'show_chat_instructions', 'chat_instructions',
        'api_provider', 'model', 'api_format', 'reasoning_effort', 'director_effort', 'api_endpoint', 'api_key', 'assistant_keys'];
    $list = implode(', ', $columns);
    $stmt = db()->prepare("INSERT INTO projects (project_id, name, {$list}) SELECT ?, ?, {$list} FROM projects WHERE project_id = ?");
    $stmt->bind_param('sss', $newId, $name, $project_id);
    $stmt->execute();
    $stmt->close();

    $reply = ['ok' => true, 'project_id' => $newId, 'name' => $name];

    session_grant($newId);
    $user = account_boot();
    if ($user) {
        $stored = account_project_password((int)$user['id'], $project_id);
        if ($stored !== '') {
            account_link_project((int)$user['id'], $newId, $stored);
        }
    }
    json_out($reply);
} catch (mysqli_sql_exception $e) { // before RuntimeException: it is a subclass of it
    json_fail('Database error: ' . $e->getMessage(), 500);
} catch (RuntimeException $e) {
    json_fail($e->getMessage(), 500);
}
