<?php
/**
 * db.php — the shared base layer of every page and endpoint.
 *
 * One database connection per request (db / db_close), JSON replies for the
 * API endpoints (json_out / json_fail / read_json_body), versioned asset URLs,
 * the project loader, and the session-based unlock of a project for the
 * researcher pages (session_grant / session_authorized / data_authorized).
 * Participant endpoints use the first two parts only and never open a session.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Returns a connected mysqli handle (one per request).
 * Throws RuntimeException with a friendly message when the DB is unreachable.
 */
function db(): mysqli
{
    global $__ait_db;
    if ($__ait_db instanceof mysqli) {
        return $__ait_db;
    }
    try {
        $__ait_db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    } catch (mysqli_sql_exception $e) {
        throw new RuntimeException(
            'Database connection failed. Is MySQL running and did you run install/install.php? (' . $e->getMessage() . ')'
        );
    }
    $__ait_db->set_charset('utf8mb4');
    return $__ait_db;
}

/**
 * Release the DB connection early. Long-running requests (the director relay
 * holds its connection to the LLM for up to ~2 minutes) must call this before
 * waiting on upstream, otherwise hundreds of concurrent chats would pin
 * hundreds of idle MySQL connections (default max_connections is only 151).
 */
function db_close(): void
{
    global $__ait_db;
    if ($__ait_db instanceof mysqli) {
        try {
            $__ait_db->close();
        } catch (Throwable $e) {
            // already gone — nothing to release
        }
        $__ait_db = null;
    }
}

/** Send a JSON response and stop. */
function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload);
    exit;
}

function json_fail(string $error, int $status = 400): void
{
    json_out(['ok' => false, 'error' => $error], $status);
}

/**
 * Read a JSON request body as an assoc array (or fail the request).
 * The body must be declared as JSON: a form on another site cannot send that
 * type without the browser asking this server first (CORS), so a forged form
 * post (text/plain bodies that happen to parse as JSON) is refused. Only the
 * participant's save endpoint, which uses no cookies, may skip the check.
 */
function read_json_body(bool $require_json_type = true): array
{
    $type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if ($require_json_type && !str_starts_with(trim($type), 'application/json')) {
        json_fail('Expected a JSON request (Content-Type: application/json)', 415);
    }
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);
    if (!is_array($payload)) {
        json_fail('Invalid JSON payload');
    }
    return $payload;
}

/**
 * URL of a file under app/ with a version tag, so browsers fetch a fresh copy
 * after every deployment instead of serving a cached older one next to newer
 * files (assets/.htaccess adds no-cache headers for the modules' own imports).
 */
function asset_url(string $path): string
{
    $mtime = @filemtime(__DIR__ . '/../' . $path);
    return $path . '?v=' . ($mtime ?: '0');
}

/**
 * The <link> tags for the site icon (the colorful MAESTRO mark). $base is the
 * path to the app folder: '' from inside app/, 'app/' from the web root.
 * The .ico comes first because Safari ignores SVG icons and takes the next one.
 */
function icon_links(string $base = ''): string
{
    return '<link rel="icon" href="' . $base . 'assets/img/favicon.ico" sizes="32x32">' . "\n"
         . '  <link rel="icon" href="' . $base . 'assets/img/favicon.svg" type="image/svg+xml">' . "\n"
         . '  <link rel="apple-touch-icon" href="' . $base . 'assets/img/apple-touch-icon.png">';
}

/** Normalize a project id: 16 alphanumeric chars, else ''. */
function sanitize_project_id(string $value): string
{
    $value = substr(preg_replace('/[^A-Za-z0-9]/', '', $value), 0, 16);
    return preg_match('/^[A-Za-z0-9]{16}$/', $value) ? $value : '';
}

/**
 * Normalize a conversation id as the chat and the data pages use it: trimmed,
 * valid UTF-8, without control characters, at most 64 characters (the length
 * of the column; counted in characters, never in bytes, so a name with accents
 * is not cut inside a character). '' when nothing usable is left.
 */
function sanitize_conversation_id(string $value): string
{
    $s = trim($value);
    if ($s !== '' && !preg_match('//u', $s)) {
        $s = function_exists('mb_convert_encoding')
            ? (string)mb_convert_encoding($s, 'UTF-8', 'UTF-8')
            : (string)@iconv('UTF-8', 'UTF-8//IGNORE', $s);
    }
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
    return function_exists('mb_substr') ? mb_substr($s, 0, 64, 'UTF-8') : substr($s, 0, 64);
}

/** Project id from common request params (project | studyID | study_id). */
function request_project_id(): string
{
    $raw = $_GET['project'] ?? $_GET['studyID'] ?? $_GET['study_id'] ?? $_POST['project'] ?? '';
    return sanitize_project_id((string)$raw);
}

/** Load one project row (or null). */
function load_project(string $project_id): ?array
{
    $stmt = db()->prepare(
        'SELECT project_id, name, participant_instructions, show_instructions, director_instructions, director_sections, conversation_script,
                study_context, team_json, show_ready_modal, ready_wait_seconds, timer_enabled, timer_duration_seconds,
                show_chat_instructions, chat_instructions, is_paused, paused_at,
                api_provider, model, api_format, reasoning_effort, director_effort, api_endpoint, api_key, assistant_keys, password_hash, created_at, updated_at
         FROM projects WHERE project_id = ? LIMIT 1'
    );
    $stmt->bind_param('s', $project_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * True when the request arrived over HTTPS, also behind a proxy that ends the
 * TLS connection and forwards plain HTTP (X-Forwarded-Proto).
 */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

// ---- Session auth -----------------------------------------------------------

/**
 * Start the PHP session of the researcher pages (settings, data, accounts).
 * The cookie is HttpOnly and SameSite=Lax, and Secure on HTTPS, so scripts
 * cannot read it and other sites' requests do not carry it. Participant
 * endpoints never call this: a chat opens no session.
 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1'); // only IDs this server issued
        session_name('aiteamstudio');
        session_set_cookie_params([
            'path'     => '/',
            'secure'   => request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/** Mark a project as unlocked in this browser session. */
function session_grant(string $project_id): void
{
    session_boot();
    if (empty($_SESSION['projects'][$project_id])) {
        session_regenerate_id(true); // new rights, new session ID (no fixation)
    }
    $_SESSION['projects'][$project_id] = time();
}

/** True when this browser session has unlocked the project. */
function session_authorized(string $project_id): bool
{
    session_boot();
    return !empty($_SESSION['projects'][$project_id]);
}

/**
 * The key of a project's data share link: derived from the server secret
 * DATA_ACCESS_KEY and the project ID, so the key of one study never opens
 * another. '' when the server has no secret (share links off).
 */
function data_share_key(string $project_id): string
{
    if (DATA_ACCESS_KEY === '') {
        return '';
    }
    return substr(hash_hmac('sha256', 'data-share:' . $project_id, DATA_ACCESS_KEY), 0, 32);
}

/**
 * True when the request may read this project's data:
 * an unlocked session, a valid password, or the project's data share key.
 */
function data_authorized(array $project): bool
{
    if (session_authorized($project['project_id'])) {
        return true;
    }
    $key      = (string)($_GET['key'] ?? '');
    $expected = data_share_key((string)$project['project_id']);
    if ($key !== '' && $expected !== '' && hash_equals($expected, $key)) {
        return true;
    }
    $password = (string)($_GET['password'] ?? $_POST['password'] ?? '');
    if (password_attempt($password, (string)$project['password_hash'], 'project:' . $project['project_id'])) {
        session_grant($project['project_id']);
        return true;
    }
    return false;
}

// Counting requests against misuse (conductor calls, wrong passwords).
require_once __DIR__ . '/limits.php';
