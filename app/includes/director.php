<?php
/**
 * director.php — the live director: prompt construction and telemetry.
 *
 * The director is a language model that writes the entire remaining
 * conversation anew whenever the participant writes something, so the
 * scripted teammates react to what was actually said while the conversation
 * keeps the course of the researcher's script. It never writes to the
 * participant itself. The moment the participant writes, the old plan is
 * gone; whoever was typing pauses, and the new lines are placed one after the
 * other as they stream in (see assets/js/chat/session.js).
 *
 * The browser describes the state in one convention — "+Ns" = seconds after
 * the previous line (after a segment divider: after that segment's start):
 * everything that was said so far, the whole script, and the lines that were
 * still planned.
 *
 * Wire format returned by the model (compact JSON, parsed incrementally in the
 * browser as it streams — see assets/js/chat/director.js):
 *
 *   {"m":[{"s":"b1","c":"text","p":2,"d":3}, {"s":"b2","g":1,"p":9,"d":4}, …]}
 *
 *   s  sender code: b1 … b10 for the project's teammates bot1 … bot10 (team.php)
 *   c  message text
 *   p  pause in seconds after the previous line; for the first line, after the
 *      participant's message. A line whose moment has passed when it arrives
 *      (a slow model) is shown right away; the pauses after it stay as given.
 *   d  seconds of visible typing before the line
 *   g  ghost typing: the sender types for d seconds and sends nothing
 *
 * The list is the entire remaining conversation. An empty list means the team
 * is done — researchers end a conversation through the instructions, which
 * know the elapsed time and the timer ("after ten minutes, return {"m":[]}").
 *
 * Segments (includes/segments.php) mark important events at fixed times, such
 * as "Blue leaves at 5:00": moments the director cannot keep by adding up
 * pauses. In a project with segments the lists show a divider where each
 * segment starts, the prompt lists every segment with its prompt and status,
 * and the director plans only up to the next segment start. There the chat
 * cuts and asks the director again, with the new segment in place of a
 * participant message. Without segments the prompt is exactly what it was
 * before segments existed.
 *
 * The study context of a project (projects.study_context) is deliberately
 * never part of this prompt. It is background for the settings-page assistants
 * only (includes/assist.php); the director sees the instructions, the script
 * and the conversation, nothing else the researcher wrote.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/team.php';
require_once __DIR__ . '/segments.php';

/** Bound client-supplied text so one request stays reasonable. */
function director_clamp($value, int $max): string
{
    $s = (string)$value;
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

/** The last $max characters of a client text, for lists whose newest part matters most. */
function director_clamp_tail($value, int $max): string
{
    $s = (string)$value;
    if (!function_exists('mb_strlen')) {
        return strlen($s) > $max ? substr($s, -$max) : $s;
    }
    return mb_strlen($s, 'UTF-8') > $max ? '…' . mb_substr($s, -$max, null, 'UTF-8') : $s;
}

/**
 * Build the upstream messages for one rewrite.
 *
 * $payload (from the browser), the lists as "+Ns" pauses (see script.js describeSequence):
 *   elapsed_seconds    seconds since the conversation started
 *   remaining_seconds  seconds left on the session timer (null = no timer)
 *   already_said       what was said so far, ending with the participant's message
 *   script             the whole stored script — the intended course of the conversation
 *   plan               the lines that were still planned (not yet sent) when the participant wrote
 *   human_text         what the participant just wrote
 *   segment            number of the segment that starts now (a segment call: no participant message)
 *
 * @return array<int, array{role:string, content:string}>
 */
function director_rewrite_messages(array $project, array $payload): array
{
    $instructions = trim((string)$project['director_instructions']);
    $all          = team_all($project);
    $members      = team_bots($all);
    $human        = team_human_prompt_name($all); // "YELLOW" unless the researcher renamed the participant
    $elapsed      = max(0, (int)($payload['elapsed_seconds'] ?? 0));
    $remaining    = is_numeric($payload['remaining_seconds'] ?? null) ? max(0, (int)$payload['remaining_seconds']) : null;
    $alreadySaid  = trim(director_clamp_tail($payload['already_said'] ?? '', 80000)); // every message of the session; in a very long one the newest
    $script       = trim(director_clamp($payload['script'] ?? '', 40000));
    $plan         = trim(director_clamp($payload['plan'] ?? '', 30000));
    $humanText    = trim(director_clamp($payload['human_text'] ?? '', 4000));
    $segments     = segments_of_project($project);
    $starting     = director_starting_segment($segments, $payload); // index of the segment this call is for, or null

    $system = $instructions .
        "\n\n## Output format (this is what the software expects — it overrides any output format above)" .
        "\nRespond with ONLY compact JSON on a single line, no markdown fences, no explanation:" .
        "\n{\"m\":[{\"s\":\"b1|b2|b3\",\"c\":\"text\",\"p\":N,\"d\":N},...]}" .
        "\n- s: sender — " . team_code_legend($members) . ". Never write lines for " . $human . " (the human participant)." .
        "\n- c: the message text, in the teammate's own voice." .
        ($segments
            ? "\n- p: the pause in seconds after the previous message; for the first line, after now (" . $human . "'s message, or the start of a new segment)."
            : "\n- p: the pause in seconds after the previous message; for the first line, after " . $human . "'s message (now).") .
        " Every pause is yours to decide: a quick reaction is a short pause, a longer message takes longer, and after a" .
        " question to " . $human . " leave room for the answer. Do not compensate for your own thinking time: a first line whose" .
        " moment has passed by the time you answer is shown right away, and the pauses after it stay as you set them." .
        "\n- d: seconds of visible typing before the line appears, within the pause (roughly one second per 3 words)." .
        "\n- Ghost typing (someone starts typing, then stops without sending): {\"s\":\"b2\",\"g\":1,\"p\":N,\"d\":N}." .
        ($segments
            ? "\n- The list is the remaining conversation, in order, from the first reaction up to the start of the next segment" .
              " (in the last segment: to the last line the team will say)."
            : "\n- The list is the ENTIRE remaining conversation, in order, from the first reaction to the last line the team" .
              " will say.") .
        " It replaces everything that was planned: copy the lines you keep as they are. Lines of the script that" .
        " the conversation already covered are done — do not bring them back." .
        "\n- Return {\"m\":[]} when the team should say nothing more (the conversation is over).";

    $divided = $segments ? '; after a segment divider, after that segment\'s start' : '';
    $timeline = 'now = ' . $elapsed . 's since the conversation started, ' . ($remaining === null ? 'no session timer' : $remaining . 's remain on the timer');
    $user =
        "## Conversation (" . $timeline . ")" .
        "\n\n### What was said so far (+Ns = seconds after the previous message" . $divided . "):\n" . ($alreadySaid !== '' ? $alreadySaid : '(nothing yet)') .
        "\n\n### The researcher's script — the intended course of the whole conversation (+Ns = seconds after the previous line" . $divided . "):\n" .
        ($script !== '' ? $script : '(no script)') .
        "\n\n### The plan until now — what was still to come (+Ns = seconds after the previous line, the first after " .
        ($starting !== null ? 'now' : $human . "'s message") . "):\n" .
        ($plan !== '' ? $plan : '(nothing was planned)');
    if ($segments) {
        $user .= "\n\n" . director_segment_section($segments, $starting, $elapsed);
    }
    if ($starting !== null) {
        $s = $segments[$starting];
        $user .=
            "\n\n### Segment " . ($starting + 1) . " starts now (" . segments_clock($s['start']) . "):\n" .
            ($s['prompt'] !== '' ? $s['prompt'] : "(no prompt: continue with the script's segment " . ($starting + 1) . ')') .
            "\n\n## Your task\nWrite the remaining conversation from now on, following your instructions and the new segment:" .
            " start with what its prompt asks for, keep the script's course and each teammate's voice, and set every pause yourself." .
            " Answer in the output format only.";
    } else {
        $user .=
            "\n\n### " . $human . " (the human) just wrote:\n\"" . $humanText . '"' .
            "\n\n## Your task\nWrite the " . ($segments ? 'remaining' : 'entire remaining') . " conversation from now on, following your instructions: react to what" .
            " " . $human . " wrote, keep the script's course and each teammate's voice, and set every pause yourself." .
            " Answer in the output format only.";
    }

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/** The index of the segment a call is for (payload "segment", its number), or null for a participant message. */
function director_starting_segment(array $segments, array $payload): ?int
{
    $n = $payload['segment'] ?? null;
    if ($n === null || $n === '' || !is_numeric($n)) {
        return null;
    }
    $i = (int)$n - 1;
    return $i >= 1 && isset($segments[$i]) ? $i : null; // segment 1 starts with the chat; it is never "starting"
}

/**
 * The segment list in the prompt: every segment with its time span, prompt and
 * status, and how to plan around them. The director plans only up to the next
 * start, because the chat cuts there anyway, so nothing of a later segment
 * (a teammate coming back, say) shows up early.
 */
function director_segment_section(array $segments, ?int $starting, int $elapsed): string
{
    // The current segment: the one starting now, or the last one that has begun.
    $current = $starting ?? 0;
    if ($starting === null) {
        foreach ($segments as $i => $s) {
            if ($s['start'] <= $elapsed) {
                $current = $i;
            }
        }
    }
    $rows = [];
    foreach ($segments as $i => $s) {
        $span = isset($segments[$i + 1])
            ? segments_clock($s['start']) . ' to ' . segments_clock($segments[$i + 1]['start'])
            : 'from ' . segments_clock($s['start']);
        $status = $i < $current ? 'past'
            : ($i > $current ? 'starts in ' . max(0, $s['start'] - $elapsed) . 's'
                : ($i === $starting ? 'current, starts now' : 'current, started ' . max(0, $elapsed - $s['start']) . 's ago'));
        $prompt = $s['prompt'] !== '' ? preg_replace('/\s*\n\s*/', ' ', $s['prompt']) : '(no prompt)';
        $rows[] = '- Segment ' . ($i + 1) . ' · ' . $span . ' · ' . $status . ': ' . $prompt;
    }
    $next = $segments[$current + 1] ?? null;
    $plan = $next !== null
        ? 'Follow the current segment\'s prompt. Plan only up to the start of segment ' . ($current + 2) . ' (' . segments_clock($next['start']) .
          ', in ' . max(0, $next['start'] - $elapsed) . 's): anything planned beyond it is dropped, and you are asked again then.'
        : 'Follow the current segment\'s prompt. This is the last segment: plan to the end of the conversation.';
    return "### Segments: important events the researcher fixed in time. At the start of each segment the conversation is cut:" .
        " whatever was planned is dropped, and you are asked again for the new segment. Your instructions above still apply;" .
        " a segment's prompt says what changes in it.\n" .
        implode("\n", $rows) . "\n" . $plan;
}

/**
 * The fixed parts of a rewrite prompt, for the token estimate on the settings
 * page: the system prompt without the instructions, and the user message with
 * every list left empty.
 *
 * @return array{system:string, user:string}
 */
function director_prompt_scaffold(array $project): array
{
    $bare = $project;
    $bare['director_instructions'] = '';
    $messages = director_rewrite_messages($bare, ['elapsed_seconds' => 120, 'remaining_seconds' => 480, 'human_text' => '']);
    return ['system' => $messages[0]['content'], 'user' => $messages[1]['content']];
}

/**
 * Record one director call in director_events. Best effort: telemetry must
 * never break a chat, so every failure is swallowed. Safe to call after
 * db_close() — db() reconnects.
 *
 * $ev: mode (rewrite | segment | assist_plan | assist_field | assist_review), provider, model, effort,
 *      status (ok | error | aborted | client-error),
 *      first_token_ms, duration_ms, output_chars, output, error,
 *      message_client_id — the participant message (messages.client_id) that
 *      triggered the call, which lets the data page mark unanswered messages,
 *      or "segment-<number>" for the call at a segment start —
 *      and usage: the provider's token counts ['input', 'output', 'cached',
 *      'cache_write'] (see llm_extract_usage), or null when none were reported.
 */
function director_log_event(string $projectId, string $conversationId, array $ev): void
{
    try {
        $mode      = director_clamp($ev['mode'] ?? 'rewrite', 16);
        $provider  = director_clamp($ev['provider'] ?? '', 16);
        $model     = director_clamp($ev['model'] ?? '', 64);
        $effort    = director_clamp($ev['effort'] ?? '', 16);
        $status    = director_clamp($ev['status'] ?? 'ok', 16);
        $first     = isset($ev['first_token_ms']) ? (int)$ev['first_token_ms'] : null;
        $duration  = (int)($ev['duration_ms'] ?? 0);
        $chars     = (int)($ev['output_chars'] ?? 0);
        $output    = director_clamp($ev['output'] ?? '', 16000);
        $error     = director_clamp($ev['error'] ?? '', 255);
        $conv      = director_clamp($conversationId, 64);
        $msgClient = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($ev['message_client_id'] ?? '')), 0, 40);
        $msgClient = $msgClient !== '' ? $msgClient : null;
        $usage     = is_array($ev['usage'] ?? null) ? $ev['usage'] : null;
        $tokIn     = $usage ? (int)($usage['input'] ?? 0) : null;
        $tokOut    = $usage ? (int)($usage['output'] ?? 0) : null;
        $tokCached = $usage ? (int)($usage['cached'] ?? 0) : null;
        $tokWrite  = $usage ? (int)($usage['cache_write'] ?? 0) : null;

        $stmt = db()->prepare(
            'INSERT INTO director_events
               (project_id, conversation_id, message_client_id, mode, provider, model, effort, status,
                first_token_ms, duration_ms, output_chars, input_tokens, output_tokens, cached_tokens, cache_write_tokens,
                output_json, error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssssssiiiiiiiss', $projectId, $conv, $msgClient, $mode, $provider, $model, $effort, $status,
            $first, $duration, $chars, $tokIn, $tokOut, $tokCached, $tokWrite, $output, $error);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // telemetry only
    }
}
