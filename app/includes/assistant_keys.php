<?php
/**
 * assistant_keys.php — the API keys of the AI Assistant of the settings page.
 *
 * The assistant can have its own key per provider (OpenAI, Anthropic), stored
 * with the study apart from the key of Step 2 (projects.api_key), so a
 * researcher can pay for the assistant with another account than for the
 * participant chats. The keys and the last test of each key are stored as JSON
 * in projects.assistant_keys:
 *
 *   {"openai":    {"key": "sk-…", "test": {"at": 1790849000, "ok": true, "model": "gpt-5-mini", "hint": ""}},
 *    "anthropic": {"key": "",     "test": null}}
 *
 * Which key a request of the assistant uses, for the provider of the chosen
 * model (assistant_key_for):
 *   1. the assistant's own key for that provider,
 *   2. the study key of Step 2 when that key belongs to the same provider
 *      (this covers a custom gateway too),
 *   3. the server's default key from config.local.php (SERVER_KEY_PROVIDERS only).
 * The browser only ever receives where the key comes from and the last four
 * characters of an own key (assistant_keys_status), never a key.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/llm.php';

const ASSISTANT_KEY_PROVIDERS = ['openai', 'anthropic'];

/** The stored keys of a project row: ['openai' => ['key' => …, 'test' => …|null], 'anthropic' => …]. */
function assistant_keys_load(array $project): array
{
    $raw = json_decode((string)($project['assistant_keys'] ?? ''), true);
    $out = [];
    foreach (ASSISTANT_KEY_PROVIDERS as $p) {
        $entry = is_array($raw) && is_array($raw[$p] ?? null) ? $raw[$p] : [];
        $key   = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
        $test  = is_array($entry['test'] ?? null) && (int)($entry['test']['at'] ?? 0) > 0 ? [
            'at'    => (int)$entry['test']['at'],
            'ok'    => !empty($entry['test']['ok']),
            'model' => (string)($entry['test']['model'] ?? ''),
            'hint'  => (string)($entry['test']['hint'] ?? ''),
        ] : null;
        $out[$p] = ['key' => $key, 'test' => $key !== '' ? $test : null];
    }
    return $out;
}

/** Store the keys of a project. Returns true when they were written. */
function assistant_keys_store(string $project_id, array $keys): bool
{
    $json = json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    try {
        // A key is not a change of the study settings: updated_at stays.
        $stmt = db()->prepare('UPDATE projects SET assistant_keys = ?, updated_at = updated_at WHERE project_id = ? LIMIT 1');
        $stmt->bind_param('ss', $json, $project_id);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** The server's default key for a provider ('' when there is none or studies may not use it). */
function assistant_server_key(string $provider): string
{
    return llm_server_key($provider);
}

/** The study key of Step 2 for a provider ('' when the study key belongs to another provider). */
function assistant_study_key(array $project, string $provider): string
{
    return (string)($project['api_provider'] ?? '') === $provider ? trim((string)($project['api_key'] ?? '')) : '';
}

/** The key a request of the assistant uses for a provider: ['key' => …, 'source' => own|study|server|none]. */
function assistant_key_for(array $project, string $provider): array
{
    $own = in_array($provider, ASSISTANT_KEY_PROVIDERS, true) ? assistant_keys_load($project)[$provider]['key'] : '';
    if ($own !== '') {
        return ['key' => $own, 'source' => 'own'];
    }
    if (($study = assistant_study_key($project, $provider)) !== '') {
        return ['key' => $study, 'source' => 'study'];
    }
    if (($server = assistant_server_key($provider)) !== '') {
        return ['key' => $server, 'source' => 'server'];
    }
    return ['key' => '', 'source' => 'none'];
}

/** "…a1B2": the end of a key, enough to recognize the key and too little to use the key. */
function assistant_key_tail(string $key): string
{
    return strlen($key) > 8 ? '…' . substr($key, -4) : '';
}

/**
 * What the settings page may know about the keys, per provider (custom included):
 * source, the tail of an own key, the last test of an own key, and whether Step 2
 * holds a key for the provider (for the "Import" link).
 */
function assistant_keys_status(array $project): array
{
    $stored = assistant_keys_load($project);
    $out = [];
    foreach (LLM_PROVIDERS as $p) {
        $use = assistant_key_for($project, $p);
        $own = $stored[$p] ?? null;
        $out[$p] = [
            'source'    => $use['source'],
            'tail'      => $own && $own['key'] !== '' ? assistant_key_tail($own['key']) : '',
            'test'      => $own['test'] ?? null,
            'study_key' => assistant_study_key($project, $p) !== '',
        ];
    }
    return $out;
}

/**
 * The model configuration of one assistant request: the study's row with the
 * chosen provider, model and effort, and the key from assistant_key_for().
 * $provider '' is the assistant's default (ASSISTANT_PROVIDER with
 * ASSISTANT_MODEL), and $model '' the default model of the chosen provider.
 */
function assistant_provider_config(array $project, string $provider, string $model, string $effort): array
{
    $row = $project;
    $own = (string)($project['api_provider'] ?? '');
    if (!in_array($provider, LLM_PROVIDERS, true)) {
        $provider = in_array(ASSISTANT_PROVIDER, LLM_PROVIDERS, true) ? ASSISTANT_PROVIDER : 'openai';
        if ($model === '') {
            $model = ASSISTANT_MODEL;
        }
    }
    if ($provider !== $own) {
        $row['api_provider'] = $provider;
        $row['model'] = '';
        if ($provider !== 'custom') {
            $row['api_endpoint'] = '';
        }
    }
    if ($model !== '') {
        $row['model'] = $model;
    }
    $row['api_key'] = assistant_key_for($project, $provider)['key'];
    $cfg = llm_provider_config($row, 'assistant');
    if (in_array($effort, LLM_EFFORTS, true)) {
        $cfg['effort'] = $effort;
    }
    return $cfg;
}
