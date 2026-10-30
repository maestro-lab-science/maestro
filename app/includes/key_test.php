<?php
/**
 * key_test.php — the last API key test of a project.
 *
 * The Test button of the settings page (api/test_key.php) checks a key and a
 * model with one tiny request. Its outcome is stored with the project at once
 * (no Save needed), so the settings still know after a reload, or in another
 * browser, when the key was last tested and whether it worked. The settings
 * page shows a test that is older than a week in yellow.
 *
 * projects.key_test holds JSON:
 *   { "at": 1790849000,       when the test ran (Unix time in seconds)
 *     "ok": true,             whether the model answered
 *     "provider": "openai",   what was tested
 *     "model": "gpt-5-mini",
 *     "status": 0,            the provider's HTTP status of a failed test
 *     "hint": "",             what a failed test means, in plain words
 *     "signature": "…" }      the browser's fingerprint of the tested form
 *                             values (provider, model, key, endpoint, format)
 * The signature lets the settings page tell whether the stored test is about
 * the values that are in the form now. The key itself is not part of the record.
 *
 * On a database without the column (an installation that has not run
 * install.php since) both functions do nothing: the test still works, it is
 * just not remembered.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/db.php';

/** The stored test of a project, or null when there is none. */
function key_test_load(string $project_id): ?array
{
    try {
        $stmt = db()->prepare('SELECT key_test FROM projects WHERE project_id = ? LIMIT 1');
        $stmt->bind_param('s', $project_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
    } catch (Throwable $e) {
        return null;
    }
    $test = $row ? json_decode((string)$row[0], true) : null;
    if (!is_array($test) || (int)($test['at'] ?? 0) <= 0) {
        return null;
    }
    return [
        'at'        => (int)$test['at'],
        'ok'        => !empty($test['ok']),
        'provider'  => (string)($test['provider'] ?? ''),
        'model'     => (string)($test['model'] ?? ''),
        'status'    => (int)($test['status'] ?? 0),
        'hint'      => (string)($test['hint'] ?? ''),
        'signature' => (string)($test['signature'] ?? ''),
    ];
}

/** Store the outcome of a test with the project. Returns true when it was written. */
function key_test_store(string $project_id, array $test): bool
{
    try {
        // updated_at says when the settings were last changed; a test is not a change.
        $stmt = db()->prepare('UPDATE projects SET key_test = ?, updated_at = updated_at WHERE project_id = ? LIMIT 1');
        $json = json_encode($test, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt->bind_param('ss', $json, $project_id);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** What a failed test means, in words a first-time user can act on. */
function test_key_hint(int $status, string $error, array $cfg): string
{
    $who     = ['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'custom' => 'The gateway'][$cfg['provider']] ?? 'The provider';
    $whoMid  = $who === 'The gateway' ? 'the gateway' : $who;
    $account = $who === 'The gateway' ? 'your gateway account' : 'your ' . $who . ' account';
    if ($status === 401) {
        return $who . ' did not accept this key. Check that you copied the whole key and that it is a key for ' . $whoMid . '.';
    }
    if ($status === 403) {
        return 'The key was recognized, but it is not allowed to use this model. Check the permissions of the key in ' . $account . '.';
    }
    if ($status === 404) {
        return $cfg['provider'] === 'custom'
            ? 'The gateway did not find the model "' . $cfg['model'] . '". Check the model name and the endpoint URL.'
            : 'The key works, but the model "' . $cfg['model'] . '" does not exist or is not available to this account. Check the model name.';
    }
    if ($status === 429) {
        return 'The key works, but the request was refused because of a rate limit or because the account has no credit left. Check the billing page of ' . $account . '.';
    }
    if ($status >= 500) {
        return $who . ' had a temporary problem. Please try again in a moment.';
    }
    if ($status === 0 && stripos($error, 'sent nothing within') !== false) { // reached, but too slow (llm_call timeout)
        return $who . ' did not answer within the time limit of the test. The service may be slow or overloaded right now. Please try again in a moment.';
    }
    if ($status === 0) {
        return $who . ' could not be reached. Check the endpoint URL and the internet connection of the server.';
    }
    if (stripos($error, 'empty response') !== false) {
        return 'The key and the model work, but the model sent an empty answer. An empty answer can happen with models that think for a long time.';
    }
    return 'The request was rejected. The message below comes from ' . $whoMid . ' and usually says what to change.';
}
