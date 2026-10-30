<?php
/**
 * relay/worker.php — runs one relay job (command line only).
 *
 *   php app/relay/worker.php <token>
 *
 * Started by the WebSocket relay (server.js) for every model call. Reads and
 * deletes the job file api/director.php stored under that token, then runs it
 * with exactly the code the direct path uses (run_model_job in
 * includes/relay.php). Events go to stdout in the same SSE lines the browser
 * would receive directly ("data: {…}", "data: [DONE]"); the relay forwards
 * them. The relay keeps stdin open while the browser still wants the result and
 * closes it to cancel — LlmSse::aborted() notices and stops the upstream call.
 *
 * @author Marc Becker
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// No web server in between: nothing to push through, no padding needed.
define('LLM_SSE_PAD_BYTES', 0);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/relay.php';

$job = relay_take_job((string)($argv[1] ?? ''));
if ($job === null) {
    // Same shape as a relay-level failure: nothing was recorded server-side (relay: true).
    $message = 'This request expired before it could start. Please try again.';
    echo 'data: ' . json_encode(['error' => $message, 'code' => 410, 'relay' => true, 'final' => ['ok' => false, 'error' => $message]]) . "\n\n";
    echo "data: [DONE]\n\n";
    exit(2);
}

run_model_job($job);
exit(0);
