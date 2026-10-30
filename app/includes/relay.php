<?php
/**
 * relay.php — model jobs and the WebSocket relay hand-off.
 *
 * A "job" is everything one model call needs after api/director.php has
 * validated the request: the resolved provider configuration and the prompt
 * (rewrite), or the assistant's inputs (assist_plan / assist_field / assist_review). A job runs
 * in one of two places, with identical behavior and identical events:
 *
 *   direct  api/director.php streams it back as Server-Sent Events in the
 *           same HTTP request (the original path; also the fallback).
 *   relay   api/director.php stores it as a one-time job file and answers
 *           with a token. The browser hands the token to the WebSocket relay
 *           (app/relay/server.js), which starts app/relay/worker.php — command-
 *           line PHP running run_model_job() — and forwards its events.
 *
 * Why the relay exists: the production host cuts every HTTP response after
 * 45 seconds (measured 2026-09-14, independent of content and output rate),
 * which breaks long assistant generations; WebSocket connections are not cut.
 * Relay jobs also do not occupy a web-server PHP worker while the model
 * writes, so the number of simultaneous chats is no longer bounded by the
 * PHP-FPM / Apache connection limit.
 *
 * Job files contain the provider key. They live outside the web root
 * (RELAY_JOB_DIR, mode 0700/0600), are deleted when read and expire after
 * RELAY_JOB_TTL seconds.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/llm.php';

// RELAY_WS_URL and RELAY_JOB_DIR are configured in config.php.

/**
 * Seconds a job file stays valid. The browser redeems its token at once, but
 * the relay queues the start while all its workers are busy (RELAY_MAX_WORKERS
 * in server.js; an assistant job can hold a worker for minutes), so a job has
 * to outlive that wait. Unredeemed files are removed by relay_cleanup_jobs().
 */
const RELAY_JOB_TTL = 600;

function relay_enabled(): bool
{
    return trim((string)RELAY_WS_URL) !== '';
}

/** Store a job for the relay and return its one-time token (64 hex chars). */
function relay_create_job(array $job): string
{
    $dir = RELAY_JOB_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Relay job directory is not writable.');
    }
    relay_cleanup_jobs();
    $token = bin2hex(random_bytes(32));
    $job['created_at'] = time();
    $tmp  = $dir . '/' . $token . '.tmp';
    $json = json_encode($job, JSON_UNESCAPED_UNICODE); // false for text that is not valid UTF-8
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Relay job could not be stored.');
    }
    @chmod($tmp, 0600);
    rename($tmp, $dir . '/' . $token . '.json'); // atomic: the worker never sees a half-written file
    return $token;
}

/** Read and delete a job. Null when the token is malformed, unknown, used or expired. */
function relay_take_job(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $path = RELAY_JOB_DIR . '/' . $token . '.json';
    $raw = @file_get_contents($path);
    if ($raw === false || !@unlink($path)) {
        return null; // unknown, or another worker took it first
    }
    $job = json_decode($raw, true);
    if (!is_array($job) || time() - (int)($job['created_at'] ?? 0) > RELAY_JOB_TTL) {
        return null;
    }
    return $job;
}

/** Remove job files nobody picked up (browser gone before starting it). */
function relay_cleanup_jobs(): void
{
    foreach (glob(RELAY_JOB_DIR . '/*.{json,tmp}', GLOB_BRACE) ?: [] as $file) {
        if (@filemtime($file) < time() - 2 * RELAY_JOB_TTL) {
            @unlink($file);
        }
    }
}

/**
 * Run one job and stream its events (LlmSse). Never returns for assistant
 * jobs (assist_job_run exits); rewrite jobs return after logging.
 */
function run_model_job(array $job): void
{
    if (($job['mode'] ?? '') === 'rewrite') {
        require_once __DIR__ . '/director.php';
        $cfg = $job['cfg'];
        LlmSse::begin();
        $res = llm_stream(llm_build_request($cfg, $job['messages'], ['stream' => true]), [
            'text' => fn(string $d) => LlmSse::send(['d' => $d]),
        ]);
        if (!$res['ok']) {
            LlmSse::send(['error' => $res['error'], 'code' => $res['status']]);
        }
        LlmSse::done();

        director_log_event($job['project_id'], $job['conversation_id'], [
            'mode' => $job['log_mode'] ?? 'rewrite', 'provider' => $cfg['provider'], 'model' => $cfg['model'], 'effort' => $res['effort'] ?? $cfg['effort'],
            'status' => $res['ok'] ? 'ok' : (LlmSse::aborted() ? 'aborted' : 'error'),
            'first_token_ms' => $res['first_token_ms'], 'duration_ms' => $res['duration_ms'],
            'output_chars' => strlen($res['content']), 'output' => $res['content'],
            'error' => !$res['ok'] && LlmSse::aborted() ? LLM_CANCELLED : $res['error'],
            'message_client_id' => $job['message_client_id'] ?? '',
            'usage' => $res['usage_reported'] ? $res['usage'] : null,
        ]);
        return;
    }

    require_once __DIR__ . '/assist.php';
    assist_job_run($job); // assist_plan, assist_field, assist_review
}
