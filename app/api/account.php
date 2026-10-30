<?php
/**
 * api/account.php — researcher accounts: a store for project credentials.
 *
 * Actions (POST): register, login, logout
 *                 update (signed in) — name, email and, with the current
 *                 password, a new password
 *                 add, remove (signed in) — add verifies the project password
 *                 and stores it; remove forgets the project in this account only.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/../includes/account.php';

header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST required', 405);
}

function account_public(array $user): array
{
    return [
        'id' => (int)$user['id'],
        'email' => (string)$user['email'],
        'name' => (string)($user['display_name'] ?? ''),
    ];
}

try {
    if ($action === 'register') {
        $payload  = read_json_body();
        $email    = strtolower(trim((string)($payload['email'] ?? '')));
        $password = (string)($payload['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_fail('Enter a valid email address');
        }
        if (strlen($password) < 8) {
            json_fail('Password must be at least 8 characters');
        }
        if (account_load_user_by_email($email)) {
            json_fail('An account with this email already exists', 409);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        $stmt->bind_param('ss', $email, $hash);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        $user = ['id' => $id, 'email' => $email];
        account_sign_in($user);
        json_out(['ok' => true, 'user' => account_public($user)]);
    }

    if ($action === 'login') {
        $payload  = read_json_body();
        $email    = strtolower(trim((string)($payload['email'] ?? '')));
        $password = (string)($payload['password'] ?? '');
        if (password_locked('account:' . $email)) {
            json_fail(password_locked_message(), 429);
        }
        $user = $email !== '' ? account_load_user_by_email($email) : null;
        // An unknown email is checked against a dummy hash: it is counted and
        // takes as long as a real account, so the answer does not tell them apart.
        if (!password_attempt($password, $user ? (string)$user['password_hash'] : PASSWORD_DUMMY_HASH, 'account:' . $email) || !$user) {
            json_fail('Wrong email or password', 403);
        }
        account_sign_in($user);
        json_out(['ok' => true, 'user' => account_public($user)]);
    }

    if ($action === 'logout') {
        account_sign_out();
        json_out(['ok' => true]);
    }

    if ($action === 'update') {
        $user = account_boot();
        if (!$user) {
            json_fail('Not signed in', 401);
        }
        $payload = read_json_body();
        $name    = trim((string)($payload['name'] ?? ''));
        $email   = strtolower(trim((string)($payload['email'] ?? '')));
        $newPw   = (string)($payload['new_password'] ?? '');
        $currPw  = (string)($payload['current_password'] ?? '');
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 80, 'UTF-8');
        } else {
            $name = substr($name, 0, 80);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_fail('Enter a valid email address');
        }
        $other = account_load_user_by_email($email);
        if ($other && (int)$other['id'] !== (int)$user['id']) {
            json_fail('Another account already uses this email', 409);
        }
        if ($newPw !== '') {
            if (strlen($newPw) < 8) {
                json_fail('The new password must be at least 8 characters');
            }
            // Setting a new password requires the current one.
            $full = account_load_user_by_email((string)$user['email']);
            if (!$full || !password_attempt($currPw, (string)$full['password_hash'], 'account:' . (string)$user['email'])) {
                json_fail(password_locked('account:' . (string)$user['email']) ? password_locked_message() : 'The current password is wrong', 403);
            }
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $stmt = db()->prepare('UPDATE users SET email = ?, display_name = ?, password_hash = ? WHERE id = ?');
            $uid  = (int)$user['id'];
            $stmt->bind_param('sssi', $email, $name, $hash, $uid);
        } else {
            $stmt = db()->prepare('UPDATE users SET email = ?, display_name = ? WHERE id = ?');
            $uid  = (int)$user['id'];
            $stmt->bind_param('ssi', $email, $name, $uid);
        }
        $stmt->execute();
        $stmt->close();
        if ($newPw !== '') {
            // A new password signs out every other device: their "stay signed in"
            // tokens go, only this browser's token stays.
            $keep = preg_match('/^([a-f0-9]{16}):/', (string)($_COOKIE[ACCOUNT_COOKIE] ?? ''), $m) ? $m[1] : '';
            $stmt = db()->prepare('DELETE FROM user_tokens WHERE user_id = ? AND selector <> ?');
            $stmt->bind_param('is', $uid, $keep);
            $stmt->execute();
            $stmt->close();
        }
        json_out(['ok' => true, 'user' => ['id' => (int)$user['id'], 'email' => $email, 'name' => $name]]);
    }

    if ($action === 'add') {
        $user = account_boot();
        if (!$user) {
            json_fail('Not signed in', 401);
        }
        $payload    = read_json_body();
        $project_id = sanitize_project_id((string)($payload['project_id'] ?? ''));
        $password   = trim((string)($payload['password'] ?? ''));
        if ($project_id === '' || $password === '') {
            json_fail('Study ID and password required');
        }
        if (password_locked('project:' . $project_id)) {
            json_fail(password_locked_message(), 429);
        }
        $project = load_project($project_id);
        if (!$project || !password_attempt($password, (string)$project['password_hash'], 'project:' . $project_id)) {
            json_fail('Invalid study ID or password', 403);
        }
        if (!account_link_project((int)$user['id'], $project_id, $password)) {
            json_fail('Example studies cannot be added to an account. The example studies open from the studies page.', 409);
        }
        session_grant($project_id);
        json_out(['ok' => true, 'project_id' => $project_id, 'name' => (string)$project['name']]);
    }

    if ($action === 'remove') {
        $user = account_boot();
        if (!$user) {
            json_fail('Not signed in', 401);
        }
        $payload    = read_json_body();
        $project_id = sanitize_project_id((string)($payload['project_id'] ?? ''));
        if ($project_id === '') {
            json_fail('Study ID required');
        }
        account_unlink_project((int)$user['id'], $project_id);
        json_out(['ok' => true]);
    }

    json_fail('Unsupported action');
} catch (mysqli_sql_exception $e) { // before RuntimeException: it is a subclass of it
    json_fail('Database error: ' . $e->getMessage(), 500);
} catch (RuntimeException $e) {
    json_fail($e->getMessage(), 500);
}
