<?php
/**
 * api/director.php — the relay between browsers and language models.
 *
 * Browsers never talk to a model provider directly: API keys and the director
 * instructions (the study manipulation) stay on the server. This endpoint
 * validates the request, resolves the project's model configuration, builds
 * the prompt, and streams the model's answer back as Server-Sent Events.
 *
 * Modes (POST JSON, "mode" field):
 *   rewrite          the live director during a participant chat, after a
 *                    participant message (human_text) or at a segment start (segment)
 *                    → data:{"d":"…"} text deltas · data:{"error":"…","code":N} · data:[DONE]
 *   assist_plan      the AI Assistant of the settings page (Step 3): one run is a
 *   assist_field     plan, one call per box of the plan and a review (see
 *   assist_review    includes/assist.php); the browser starts the calls one by one
 *                    → data:{"r":"…"} reasoning · data:{"d":"…"} text
 *                      · data:{"final":{…}} · data:[DONE]
 *
 * Errors before the stream starts are plain JSON {"ok":false,"error":"…"} with
 * an HTTP status; once streaming, errors are reported in-band (see above).
 *
 * Transport: with "relay": 1 in the body (and RELAY_WS_URL configured) the
 * answer is {"ok":true,"relay":{"token":"…"}} instead of the stream — the
 * browser redeems the token at the WebSocket relay, which runs the very same
 * job and delivers the very same events (see includes/relay.php). Without the
 * flag, or when the relay is off, the stream comes back in this response.
 *
 * Access: "rewrite" is open — participants are anonymous and the project's
 * director instructions bound what it can produce. The assistants run free-form
 * prompts on the researcher's key, so they require the unlocked settings
 * session (or the project password), like saving the project does.
 *
 * Scalability: the database connection is released before the model call, the
 * response is flushed chunk by chunk, keep-alive pings cover reasoning pauses,
 * and the upstream call is cancelled when the browser disconnects.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/llm.php';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/../includes/relay.php';

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST required', 405);
}

set_time_limit(LLM_ASSIST_S + 60); // the assistants are the long ones; cURL bounds each call
// Keep running when the browser goes away (a newer participant message aborts
// the old request): the relay notices the disconnect itself, cancels the
// upstream call and still records the event in director_events.
ignore_user_abort(true);

$payload    = read_json_body();
$project_id = sanitize_project_id((string)($payload['project_id'] ?? ''));
$mode       = (string)($payload['mode'] ?? '');

if ($project_id === '') {
    json_fail('project_id required');
}
if (!in_array($mode, ['rewrite', 'assist_plan', 'assist_field', 'assist_review'], true)) {
    json_fail('mode must be "rewrite", "assist_plan", "assist_field" or "assist_review"');
}

try {
    $project = load_project($project_id);
} catch (RuntimeException $e) {
    json_fail($e->getMessage(), 500);
}
if (!$project) {
    json_fail('Unknown study', 404);
}

// =============================================================================
// rewrite — the live director
// =============================================================================
if ($mode === 'rewrite') {
    require_once __DIR__ . '/../includes/director.php';

    if (trim((string)$project['director_instructions']) === '') {
        json_fail('This study has no conductor instructions yet.', 409);
    }
    $cfg = llm_provider_config($project, 'director');
    if (($problem = llm_config_problem($cfg)) !== '') {
        json_fail($problem, 500);
    }
    // A call answers a participant message, or the start of a segment (no
    // message; logged as mode "segment" with message_client_id "segment-<number>").
    $starting = director_starting_segment(segments_of_project($project), $payload);
    if ($starting === null && trim((string)($payload['human_text'] ?? '')) === '') {
        json_fail('human_text required');
    }
    // The participant link is public: count the calls (includes/limits.php).
    $conversationId = sanitize_conversation_id((string)($payload['conversation_id'] ?? ''));
    if (($limit = director_rate_problem($project_id, $conversationId)) !== null) {
        json_fail($limit, 429);
    }
    dispatch_job([
        'mode'              => 'rewrite',
        'log_mode'          => $starting === null ? 'rewrite' : 'segment', // director_events.mode
        'project_id'        => $project_id,
        'conversation_id'   => $conversationId,
        'message_client_id' => $starting === null ? (string)($payload['message_client_id'] ?? '') : 'segment-' . ($starting + 1),
        'cfg'               => $cfg,
        'messages'          => director_rewrite_messages($project, $payload),
    ], $payload);
}

// =============================================================================
// assist_plan / assist_field / assist_review — the AI Assistant of Step 3
// =============================================================================
require_once __DIR__ . '/../includes/assist.php';
require_once __DIR__ . '/../includes/assistant_keys.php';

// Researchers only: an unlocked session, or the project password as a fallback
// when the PHP session expired while the page stayed open. The session lock is
// released right away — the stream below takes a while and must not block the
// page's other requests (e.g. saving).
$authorized = session_authorized($project_id);
if (!$authorized) {
    $password = trim((string)($payload['password'] ?? ''));
    if (password_attempt($password, (string)$project['password_hash'], 'project:' . $project_id)) {
        session_grant($project_id);
        $authorized = true;
    }
}
session_write_close();
if (!$authorized) {
    json_fail('The AI Assistant needs an unlocked study. Reopen the study from the Studies page.', 403);
}

// The team, the study context and the boxes as currently edited on the
// settings page (may be unsaved); the team and context fall back to the stored ones.
$everyone = team_all($project);
if (isset($payload['team'])) {
    $edited = team_normalize_all($payload['team']);
    if (is_array($edited)) {
        $everyone = $edited;
    }
}
$members   = team_bots($everyone);
$humanName = team_human_prompt_name($everyone);
$context   = array_key_exists('study_context', $payload)
    ? trim(assist_clamp($payload['study_context'], 20000))
    : trim((string)($project['study_context'] ?? ''));
$participantInfo = trim(assist_clamp($payload['participant_info'] ?? '', 8000));
$state   = assist_state_from_payload($payload['state'] ?? [], $members);
$targets = assist_targets($members);

// The model the researcher chose in the panel, with the assistant's key for its provider.
$cfg = assistant_provider_config($project,
    (string)($payload['provider_override'] ?? ''),
    trim(assist_clamp($payload['model_override'] ?? '', 64)),
    (string)($payload['effort_override'] ?? ''));
if (($problem = llm_config_problem($cfg)) !== '') {
    json_fail($cfg['key'] === '' ? 'There is no API key for the AI Assistant yet. Click "API key" below the chat to add one.' : $problem, 400);
}

$job = ['mode' => $mode, 'project_id' => $project_id, 'cfg' => $cfg, 'targets' => $targets,
    'everyone' => $everyone]; // names for the ids in what the researcher reads; ids, names and colors new AI actors must not take
if ($mode === 'assist_plan') {
    $chat = assist_chat_from_payload($payload['chat'] ?? [], 30, 8000);
    if (count($chat) === 0 || end($chat)['role'] !== 'user') {
        json_fail('chat required');
    }
    $job['system']   = assist_system_prompt_plan($state, $members, $humanName, $context, $participantInfo);
    $job['messages'] = $chat;
    dispatch_job($job, $payload);
}

// The request and the plan of the run, for the steps and the review.
$request   = trim(assist_clamp($payload['request'] ?? '', 4000));
$planReply = trim(assist_clamp($payload['plan_reply'] ?? '', 600));
$steps     = assist_clean_steps($payload['steps'] ?? [], $targets, 12);
if ($request === '' || !$steps) {
    json_fail('request and steps required');
}

if ($mode === 'assist_field') {
    $step = assist_clean_step($payload['step'] ?? null, $targets);
    if ($step === null) {
        json_fail('Unknown box for this step');
    }
    if (assist_target_too_long($payload['state'] ?? [], $step['target'])) {
        json_fail('This box is too long for the AI Assistant, so the assistant leaves the box unchanged. Please edit this box yourself.', 413);
    }
    $job['system']   = assist_system_prompt_field($state, $members, $humanName, $context, $participantInfo, $request, $planReply, $steps, $step);
    $job['messages'] = [['role' => 'user', 'content' => 'Carry out the step for the box [' . $step['target'] . '] now.']];
    $job['target']   = $step['target'];
    $job['old']      = assist_target_content($state, $step['target']);
    $job['members']  = $members;
    dispatch_job($job, $payload);
}

// assist_review
$results = [];
foreach (array_slice((array)($payload['results'] ?? []), 0, 16) as $r) {
    if (!is_array($r) || !isset($targets[(string)($r['target'] ?? '')])) {
        continue;
    }
    $results[] = [
        'target' => (string)$r['target'],
        'title'  => trim(assist_clamp($r['title'] ?? '', 60)),
        'status' => in_array($r['status'] ?? '', ['done', 'skip', 'error', 'stopped'], true) ? $r['status'] : 'error',
        'note'   => trim(assist_clamp($r['note'] ?? '', 160)),
        'diff'   => array_map(fn($l) => assist_clamp($l, 200), array_slice(array_values(array_filter((array)($r['diff'] ?? []), 'is_string')), 0, 40)),
    ];
}
$job['final']    = !empty($payload['final']);
$job['system']   = assist_system_prompt_review($state, $members, $humanName, $context, $participantInfo, $request, $planReply, $steps, $results, $job['final']);
$job['messages'] = [['role' => 'user', 'content' => 'Review the result of the run now.']];
dispatch_job($job, $payload);

// =============================================================================

/**
 * Run a prepared job: hand it to the WebSocket relay when the browser asked
 * for that and the relay is configured (answer: {"ok":true,"relay":{"token"}}),
 * otherwise stream it right here as before. Never returns.
 */
function dispatch_job(array $job, array $payload): void
{
    if (!empty($payload['relay']) && relay_enabled()) {
        try {
            $token = relay_create_job($job);
        } catch (RuntimeException $e) {
            $token = null; // fall through to direct streaming
        }
        if ($token !== null) {
            json_out(['ok' => true, 'relay' => ['token' => $token]]);
        }
    }
    db_close(); // nothing more to read; do not hold a connection during the model call
    run_model_job($job);
    exit;
}
