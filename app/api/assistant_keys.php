<?php
/**
 * api/assistant_keys.php — the AI Assistant's own API keys (settings page, Step 3).
 *
 * POST { project_id, action, provider?, key?, model? }
 *   action "status"  what the page may know about the keys (see below)
 *          "save"    store key as the assistant's own key for provider, then test it
 *          "import"  copy the study key of Step 2 into the assistant's slot for
 *                    provider (on the server: the key never reaches the browser), then test it
 *          "remove"  delete the assistant's own key for provider
 * The keys are stored at once, without the Save button of the page
 * (includes/assistant_keys.php). A test is one tiny model call with model (the
 * model chosen in the assistant panel when it belongs to provider) or the
 * default model of provider.
 *
 * Reply: { ok: true, keys: assistant_keys_status(), test?: { ok, hint, model } }
 *     or { ok: false, error }
 * Access: the unlocked settings session, or the project password.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/llm.php';
require_once __DIR__ . '/../includes/key_test.php';
require_once __DIR__ . '/../includes/assistant_keys.php';

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
session_write_close(); // the key test takes a moment; do not hold the session lock
if (!$authorized) {
    json_fail('Session expired. Please reopen the study from the Studies page.', 403);
}

$action = (string)($payload['action'] ?? 'status');
if ($action === 'status') {
    json_out(['ok' => true, 'keys' => assistant_keys_status($project)]);
}
if (!in_array($action, ['save', 'import', 'remove'], true)) {
    json_fail('Unknown action');
}
$provider = (string)($payload['provider'] ?? '');
if (!in_array($provider, ASSISTANT_KEY_PROVIDERS, true)) {
    json_fail('The AI Assistant stores keys for OpenAI and Anthropic only.');
}

$keys = assistant_keys_load($project);
if ($action === 'remove') {
    $keys[$provider] = ['key' => '', 'test' => null];
} else {
    $key = $action === 'import'
        ? assistant_study_key($project, $provider)
        : substr(preg_replace('/\s+/', '', (string)($payload['key'] ?? '')), 0, 512);
    if ($key === '') {
        json_fail($action === 'import'
            ? 'Step 2 has no key for ' . ($provider === 'openai' ? 'OpenAI' : 'Anthropic') . ' to import. A key in Step 2 only counts after Save.'
            : 'Please paste a key first.');
    }
    $keys[$provider] = ['key' => $key, 'test' => null];
}
if (!assistant_keys_store($project_id, $keys)) {
    json_fail('The key could not be stored. Run install/install.php once to add the column for the assistant keys.', 500);
}
$project['assistant_keys'] = json_encode($keys);
if ($action === 'remove') {
    json_out(['ok' => true, 'keys' => assistant_keys_status($project)]);
}

// Test the new key with one tiny request, as the Test button of Step 2 does.
$model = substr(trim((string)($payload['model'] ?? '')), 0, 64);
$row = $project;
$row['api_provider'] = $provider;
$row['model']        = $model;
$row['api_endpoint'] = '';
$row['api_key']      = $keys[$provider]['key'];
$row['director_effort'] = 'minimal';
$cfg = llm_provider_config($row, 'director');
db_close();
set_time_limit(60); // the test may wait 30 s for the model; PHP must not stop first
$res = llm_call(llm_build_request($cfg, [
    ['role' => 'system', 'content' => 'This is a connection test. Reply with the single word OK.'],
    ['role' => 'user', 'content' => 'Connection test'],
], ['stream' => false]), 30);
$test = [
    'at'    => time(),
    'ok'    => (bool)$res['ok'],
    'model' => $cfg['model'],
    'hint'  => $res['ok'] ? '' : test_key_hint((int)$res['status'], (string)$res['error'], $cfg), // never the provider's text: it can quote the key
];
// The test took a while: another request may have changed the keys meanwhile
// (the other provider's key, or this one removed). Store the result in a fresh
// copy, and only while the tested key is still the stored one.
$fresh = load_project($project_id) ?: $project;
$keys  = assistant_keys_load($fresh);
if (($keys[$provider]['key'] ?? '') === $row['api_key']) {
    $keys[$provider]['test'] = $test;
    assistant_keys_store($project_id, $keys);
}
$fresh['assistant_keys'] = json_encode($keys);
json_out(['ok' => true, 'keys' => assistant_keys_status($fresh), 'test' => $test]);
