<?php
/**
 * limits.php — counting requests to stop misuse.
 *
 * The participant link is public: anyone who has it can start conductor calls
 * on the study's API key. These are counted per conversation and per network
 * address (director_rate_problem). Wrong passwords for studies and accounts
 * are counted per network address too, and lock further tries for a while
 * (password_attempt, password_locked). The limits are set in config.php.
 *
 * Every counted event is a row in rate_events (bucket, time). Network
 * addresses and conversation ids are stored only as hashes, and rows older
 * than a day are deleted. When the table does not exist yet (install.php not
 * run after an update), nothing is counted and nothing is refused.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/db.php';

const RATE_KEEP_SECONDS = 86400;
/** A valid hash no password matches: a login for an unknown email takes as long as one for a real account. */
const PASSWORD_DUMMY_HASH = '$2y$12$dt/Q1fUqdWAE2DWEwYD2NerQktV98Xl1.54bWBApniomqGPh33IoO';

/**
 * The client's network address. Behind a proxy on this host or network (the
 * request comes from a private or loopback address), the address the proxy
 * added last to X-Forwarded-For; a client can put any address in front of it,
 * but not after it.
 */
function client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $viaProxy = $remote !== ''
        && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($viaProxy && $forwarded !== '') {
        $hops = array_map('trim', explode(',', $forwarded));
        $last = (string)end($hops);
        if (filter_var($last, FILTER_VALIDATE_IP)) {
            return $last;
        }
    }
    return $remote;
}

/** A bucket name: what is counted, and a hash of whose (never the address or id itself). */
function rate_bucket(string $kind, string $value): string
{
    return $kind . ':' . substr(hash('sha256', 'maestro-rate|' . DB_NAME . '|' . $value), 0, 40);
}

/** How many events a bucket had in the last $seconds (0 when the table is missing). */
function rate_count(string $bucket, int $seconds): int
{
    try {
        $since = date('Y-m-d H:i:s', time() - $seconds);
        $stmt = db()->prepare('SELECT COUNT(*) AS n FROM rate_events WHERE bucket = ? AND created_at >= ?');
        $stmt->bind_param('ss', $bucket, $since);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $n;
    } catch (mysqli_sql_exception $e) {
        return 0;
    }
}

/** Count one event; now and then, rows older than a day go. */
function rate_record(string $bucket): void
{
    try {
        $now = date('Y-m-d H:i:s');
        $stmt = db()->prepare('INSERT INTO rate_events (bucket, created_at) VALUES (?, ?)');
        $stmt->bind_param('ss', $bucket, $now);
        $stmt->execute();
        $stmt->close();
        if (random_int(1, 200) === 1) {
            $old = date('Y-m-d H:i:s', time() - RATE_KEEP_SECONDS);
            $stmt = db()->prepare('DELETE FROM rate_events WHERE created_at < ?');
            $stmt->bind_param('s', $old);
            $stmt->execute();
            $stmt->close();
        }
    } catch (mysqli_sql_exception $e) {
        // no table yet: nothing is counted
    }
}

// ---- Conductor calls of the participant chat ----------------------------------

/**
 * Whether the participant chat may start another conductor call: null when it
 * may (the call is then counted), otherwise why not. A real chat needs one call
 * per participant message and one per segment start, far below every limit.
 */
function director_rate_problem(string $project_id, string $conversation_id): ?string
{
    $conv = rate_bucket('dir-conv', $project_id . '|' . $conversation_id);
    $ip   = rate_bucket('dir-ip', client_ip());
    if (RATE_DIRECTOR_PER_CONVERSATION_MIN > 0 && rate_count($conv, 60) >= RATE_DIRECTOR_PER_CONVERSATION_MIN) {
        return 'Too many messages in a short time. Please wait a moment.';
    }
    if (RATE_DIRECTOR_PER_CONVERSATION > 0 && rate_count($conv, RATE_KEEP_SECONDS) >= RATE_DIRECTOR_PER_CONVERSATION) {
        return 'This conversation has reached its limit of conductor calls.';
    }
    if (RATE_DIRECTOR_PER_IP_MIN > 0 && rate_count($ip, 60) >= RATE_DIRECTOR_PER_IP_MIN) {
        return 'Too many requests from this network. Please wait a moment.';
    }
    rate_record($conv);
    rate_record($ip);
    return null;
}

// ---- Passwords ---------------------------------------------------------------

/** The buckets of wrong passwords for one study or account ($scope), and for the address overall. */
function password_buckets(string $scope): array
{
    $ip = client_ip();
    return [rate_bucket('pw', $scope . '|' . $ip), rate_bucket('pw-ip', $ip)];
}

/**
 * True while tries from this network address are locked: PASSWORD_MAX_FAILURES
 * wrong passwords for this study or account, or three times as many for any,
 * within PASSWORD_LOCK_MINUTES.
 */
function password_locked(string $scope): bool
{
    if (PASSWORD_MAX_FAILURES <= 0) {
        return false;
    }
    [$one, $any] = password_buckets($scope);
    $window = PASSWORD_LOCK_MINUTES * 60;
    return rate_count($one, $window) >= PASSWORD_MAX_FAILURES
        || rate_count($any, $window) >= 3 * PASSWORD_MAX_FAILURES;
}

/** The message for a locked try. */
function password_locked_message(): string
{
    return 'Too many wrong passwords. Please wait ' . PASSWORD_LOCK_MINUTES . ' minutes and try again.';
}

/**
 * Check a password against its hash, counting a wrong one. While tries are
 * locked (password_locked), every password counts as wrong without a check.
 * $scope names what the password opens, e.g. "project:<id>" or "account:<email>".
 */
function password_attempt(string $password, string $hash, string $scope): bool
{
    if ($password === '' || password_locked($scope)) {
        return false;
    }
    if (password_verify($password, $hash)) {
        return true;
    }
    foreach (password_buckets($scope) as $bucket) {
        rate_record($bucket);
    }
    return false;
}
