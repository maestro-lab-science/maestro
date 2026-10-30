<?php
/**
 * account.php — optional researcher accounts.
 *
 * An account is a thin convenience layer on top of the password-based project
 * access: it stores and organizes project credentials, nothing more. Projects
 * remain fully usable and shareable without an account, and removing a project
 * from an account only forgets the stored credential — the project itself and
 * everyone else's access are untouched.
 *
 * Only researcher pages include this file. Participant endpoints (aiTeam.php,
 * api/director.php, api/save_message.php) stay account- and session-free.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/examples.php';

const ACCOUNT_COOKIE     = 'aiteamstudio_account';
const ACCOUNT_TOKEN_DAYS = 180;

/**
 * The signed-in user for this request, or null: PHP session first, then the
 * "stay signed in" cookie. Result is cached per request.
 */
function account_boot(): ?array
{
    global $__ait_user;
    if (isset($__ait_user)) {
        return $__ait_user ?: null;
    }
    $__ait_user = false;
    session_boot();
    if (!empty($_SESSION['user_id'])) {
        $user = account_load_user((int)$_SESSION['user_id']);
        if ($user) {
            $__ait_user = $user;
        } else {
            unset($_SESSION['user_id']);
        }
    }
    if ($__ait_user === false && isset($_COOKIE[ACCOUNT_COOKIE])) {
        $user = account_consume_remember_cookie((string)$_COOKIE[ACCOUNT_COOKIE]);
        if ($user) {
            $_SESSION['user_id'] = (int)$user['id'];
            $__ait_user = $user;
        }
    }
    return $__ait_user ?: null;
}

function account_load_user(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, email, display_name, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function account_load_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT id, email, display_name, password_hash, created_at FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Start a signed-in session and issue a long-lived "stay signed in" cookie. */
function account_sign_in(array $user): void
{
    global $__ait_user;
    session_boot();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $__ait_user = [
        'id' => (int)$user['id'],
        'email' => (string)$user['email'],
        'display_name' => (string)($user['display_name'] ?? ''),
        'created_at' => $user['created_at'] ?? null,
    ];

    $selector  = bin2hex(random_bytes(8));
    $validator = bin2hex(random_bytes(16));
    $hash      = hash('sha256', $validator);
    $expires   = date('Y-m-d H:i:s', time() + ACCOUNT_TOKEN_DAYS * 86400);
    $stmt = db()->prepare('INSERT INTO user_tokens (selector, user_id, token_hash, expires_at) VALUES (?, ?, ?, ?)');
    $uid = (int)$user['id'];
    $stmt->bind_param('siss', $selector, $uid, $hash, $expires);
    $stmt->execute();
    $stmt->close();
    account_set_cookie($selector . ':' . $validator, time() + ACCOUNT_TOKEN_DAYS * 86400);
}

/** Sign out: drop this device's token + cookie. Project unlocks in the session are kept. */
function account_sign_out(): void
{
    global $__ait_user;
    session_boot();
    $raw = (string)($_COOKIE[ACCOUNT_COOKIE] ?? '');
    if (preg_match('/^([a-f0-9]{16}):/', $raw, $m)) {
        $stmt = db()->prepare('DELETE FROM user_tokens WHERE selector = ?');
        $stmt->bind_param('s', $m[1]);
        $stmt->execute();
        $stmt->close();
    }
    account_set_cookie('', time() - 86400);
    unset($_SESSION['user_id']);
    session_regenerate_id(true);
    $__ait_user = false;
}

function account_set_cookie(string $value, int $expires): void
{
    setcookie(ACCOUNT_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Validate a "selector:validator" cookie; sliding expiry on success. */
function account_consume_remember_cookie(string $raw): ?array
{
    if (!preg_match('/^([a-f0-9]{16}):([a-f0-9]{32})$/', $raw, $m)) {
        return null;
    }
    [, $selector, $validator] = $m;
    $stmt = db()->prepare('SELECT user_id, token_hash, expires_at FROM user_tokens WHERE selector = ? LIMIT 1');
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $valid = $row
        && strtotime((string)$row['expires_at']) > time()
        && hash_equals((string)$row['token_hash'], hash('sha256', $validator));
    if (!$valid) {
        if ($row) {
            $stmt = db()->prepare('DELETE FROM user_tokens WHERE selector = ?');
            $stmt->bind_param('s', $selector);
            $stmt->execute();
            $stmt->close();
        }
        account_set_cookie('', time() - 86400);
        return null;
    }

    $expires = date('Y-m-d H:i:s', time() + ACCOUNT_TOKEN_DAYS * 86400);
    $stmt = db()->prepare('UPDATE user_tokens SET expires_at = ? WHERE selector = ?');
    $stmt->bind_param('ss', $expires, $selector);
    $stmt->execute();
    $stmt->close();
    return account_load_user((int)$row['user_id']);
}

// ---- Project links ----------------------------------------------------------

/**
 * Keep a project (and its credential) in the account. Re-adding updates the
 * stored password. Example studies are never kept in an account (they open
 * from the example cards only), so for them nothing is stored and the result
 * is false.
 */
function account_link_project(int $userId, string $projectId, string $password): bool
{
    if (example_is_project($projectId)) {
        return false;
    }
    // Two portable statements instead of ON DUPLICATE KEY UPDATE: the alias
    // syntax (MySQL 8.0.19+) breaks MariaDB, and VALUES() is deprecated in
    // MySQL 9 — this runs identically on both.
    $stmt = db()->prepare('INSERT IGNORE INTO user_projects (user_id, project_id, project_password) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $userId, $projectId, $password);
    $stmt->execute();
    $stmt->close();

    $stmt = db()->prepare('UPDATE user_projects SET project_password = ? WHERE user_id = ? AND project_id = ?');
    $stmt->bind_param('sis', $password, $userId, $projectId);
    $stmt->execute();
    $stmt->close();
    return true;
}

/** Forget a project in this account only. Returns true when a link was removed. */
function account_unlink_project(int $userId, string $projectId): bool
{
    $stmt = db()->prepare('DELETE FROM user_projects WHERE user_id = ? AND project_id = ?');
    $stmt->bind_param('is', $userId, $projectId);
    $stmt->execute();
    $removed = $stmt->affected_rows > 0;
    $stmt->close();
    return $removed;
}

/**
 * All projects kept in the account (most recently added first), with activity
 * stats. Example studies linked before they became examples are left out.
 */
function account_projects(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT up.project_id, up.added_at, p.name, p.created_at,
                COALESCE(m.convs, 0) AS conversations, m.last_at
         FROM user_projects up
         JOIN projects p ON p.project_id = up.project_id
         LEFT JOIN (
             SELECT project_id, COUNT(DISTINCT conversation_id) AS convs, MAX(shown_at) AS last_at
             FROM messages GROUP BY project_id
         ) m ON m.project_id = up.project_id
         WHERE up.user_id = ?
         ORDER BY up.added_at DESC'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_values(array_filter($rows, fn($r) => !example_is_project((string)$r['project_id'])));
}

/** The stored credential for one linked project ('' when not linked, and always for example studies). */
function account_project_password(int $userId, string $projectId): string
{
    if (example_is_project($projectId)) {
        return '';
    }
    $stmt = db()->prepare('SELECT project_password FROM user_projects WHERE user_id = ? AND project_id = ? LIMIT 1');
    $stmt->bind_param('is', $userId, $projectId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (string)$row[0] : '';
}

/**
 * Unlock a project using the account's stored credential. Returns true and
 * grants the session when the stored password still matches; false when the
 * project isn't in the account or the password changed since it was stored.
 */
function account_auto_unlock(array $user, array $project): bool
{
    $projectId = (string)$project['project_id'];
    $stored = account_project_password((int)$user['id'], $projectId);
    if ($stored === '' || !password_verify($stored, (string)$project['password_hash'])) {
        return false;
    }
    session_grant($projectId);
    return true;
}
