<?php
/**
 * api/test_key.php — check an API key and model with one tiny request.
 *
 * The settings page sends what is currently in the form (possibly unsaved):
 *   { project_id, provider, model, api_key, api_endpoint, api_format, signature }
 * An empty api_key tests the key the project would fall back to: its own
 * stored key (same provider) or the server-wide key from config.local.php.
 * The model is asked to answer "OK". The reply tells the researcher in plain
 * words whether the key, the account's credit, the model name and (for a
 * custom gateway) the endpoint work.
 *
 * The outcome is stored with the project at once, without Save, together with
 * the browser's fingerprint of the tested values ("signature"), so the
 * settings page still knows later when the key was last tested
 * (includes/key_test.php).
 *
 * Reply: { ok: true, provider, model, ms, reply, tested_at, stored }
 *     or { ok: false, model, status, error, hint, tested_at, stored }   (HTTP 200: the test itself ran)
 *     tested_at: Unix time of the test; stored: whether it was remembered
 * Access: the unlocked settings session, or the project password.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/llm.php';
require_once __DIR__ . '/../includes/key_test.php';

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
} catch (RuntimeException $e) {
    json_fail($e->getMessage(), 500);
}
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
session_write_close(); // the model call takes a moment; do not hold the session lock
if (!$authorized) {
    json_fail('Session expired. Please reopen the study from the Studies page.', 403);
}

$provider = (string)($payload['provider'] ?? '');
if (!in_array($provider, LLM_PROVIDERS, true)) {
    json_fail('Unknown provider');
}
$row = $project;
$row['api_provider']    = $provider;
$row['model']           = substr(trim((string)($payload['model'] ?? '')), 0, 64);
$row['api_endpoint']    = substr(trim((string)($payload['api_endpoint'] ?? '')), 0, 255);
$row['api_format']      = (string)($payload['api_format'] ?? 'auto');
$row['director_effort'] = 'minimal'; // the quickest possible answer
$key = substr(preg_replace('/\s+/', '', (string)($payload['api_key'] ?? '')), 0, 512);
if ($key !== '') {
    $row['api_key'] = $key;
} elseif ($provider !== (string)$project['api_provider']) {
    $row['api_key'] = ''; // the stored key belongs to another provider: use the server key
}
$cfg = llm_provider_config($row, 'director');
if (($problem = llm_config_problem($cfg)) !== '') {
    json_out(['ok' => false, 'status' => 0, 'error' => $problem, 'hint' => $cfg['key'] === ''
        ? 'There is no key to test. Paste a key into the API key field first.'
        : ($cfg['endpoint'] === '' ? 'Enter the endpoint URL of your gateway first.' : $problem)]);
}

db_close();
set_time_limit(60); // the test may wait 30 s for the model; PHP must not stop first (wall-clock limits on some systems)
$req = llm_build_request($cfg, [
    ['role' => 'system', 'content' => 'This is a connection test. Reply with the single word OK.'],
    ['role' => 'user', 'content' => 'Connection test'],
], ['stream' => false]);
$res = llm_call($req, 30); // well below the host's 45-second limit for a response

// Remember the outcome with the project right away (no Save needed). The
// provider's own error text is not kept: it can quote parts of the key.
$testedAt  = time();
$hint      = $res['ok'] ? '' : test_key_hint((int)$res['status'], (string)$res['error'], $cfg);
$signature = substr(preg_replace('/[^a-f0-9]/', '', strtolower((string)($payload['signature'] ?? ''))), 0, 32);
$stored    = key_test_store($project_id, [
    'at'        => $testedAt,
    'ok'        => (bool)$res['ok'],
    'provider'  => $cfg['provider'],
    'model'     => $cfg['model'],
    'status'    => $res['ok'] ? 0 : (int)$res['status'],
    'hint'      => $hint,
    'signature' => $signature,
]);

if ($res['ok']) {
    $reply = trim($res['content']);
    json_out([
        'ok'        => true,
        'provider'  => $cfg['provider'],
        'model'     => $cfg['model'],
        'ms'        => $res['duration_ms'],
        'reply'     => function_exists('mb_substr') ? mb_substr($reply, 0, 40, 'UTF-8') : substr($reply, 0, 40),
        'tested_at' => $testedAt,
        'stored'    => $stored,
    ]);
}
json_out([
    'ok'        => false,
    'model'     => $cfg['model'],
    'status'    => $res['status'],
    'error'     => $res['error'],
    'hint'      => $hint,
    'tested_at' => $testedAt,
    'stored'    => $stored,
]);
