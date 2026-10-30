<?php
/**
 * api/study_status.php — pause or continue a study (settings, Step 4).
 *
 * POST { project_id, paused: 1 | 0 }
 * Takes effect immediately and independently of the settings form (no Save
 * needed). While a study is paused, the participant link shows a notice that
 * the study is not active instead of the chat (see aiTeam.php); test links
 * (conversationID=TEST…) keep working, and chats that already run can finish.
 *
 * Reply: { ok: true, paused: bool, paused_at: "YYYY-MM-DD HH:MM:SS" | null }
 * Access: the unlocked settings session, or the project password.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/../includes/db.php';

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST required', 405);
}

$payload    = read_json_body();
$project_id = sanitize_project_id((string)($payload['project_id'] ?? ''));
if ($project_id === '') {
    json_fail('project_id required');
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

    $paused = empty($payload['paused']) ? 0 : 1;
    $stmt = db()->prepare('UPDATE projects SET is_paused = ?, paused_at = IF(? = 1, NOW(), NULL) WHERE project_id = ? LIMIT 1');
    $stmt->bind_param('iis', $paused, $paused, $project_id);
    $stmt->execute();
    $stmt->close();

    $row = load_project($project_id);
    json_out(['ok' => true, 'paused' => (int)$row['is_paused'] === 1, 'paused_at' => $row['paused_at']]);
} catch (mysqli_sql_exception $e) { // before RuntimeException: it is a subclass of it
    json_fail('Database error: ' . $e->getMessage(), 500);
} catch (RuntimeException $e) {
    json_fail($e->getMessage(), 500);
}
