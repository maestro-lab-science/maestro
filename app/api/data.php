<?php
/**
 * api/data.php — data + exports for the data page (data.php).
 *
 * Auth: unlocked session (via Projects page) OR the study's share link ?key=…
 * (data_share_key() in includes/db.php).
 * The two deleting actions need the unlocked session (POST); the share key is
 * a read-only credential.
 *
 * Data:
 *   ?action=list&project=…                        → conversations with stats (fast)
 *   ?action=usage&project=…                       → tokens and estimated costs per
 *                                                   conversation (+ study totals)
 *   ?action=messages&project=…&conversation_id=…  → one transcript
 * Deleting (POST):
 *   ?action=delete_conversation  { conversation_id }      → one conversation and its calls
 *   ?action=wipe_project_data    { confirm_project_id }   → every conversation and call
 * Exports:
 *   ?action=download_csv&conversation_id=…        → one conversation (CSV)
 *   ?action=download_pdf&conversation_id=…        → one conversation, rendered (PDF)
 *   ?action=download_all_csv                      → every message of the project (CSV)
 *   ?action=download_all_pdf                      → every conversation rendered (one PDF)
 *   ?action=download_overview_csv                 → one row per participant (CSV)
 *   ?action=download_zip                          → complete archive: per-conversation
 *                                                   CSV+PDF, all messages, overview,
 *                                                   project configuration (no secrets)
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pdf.php';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/../includes/usage.php';
require_once __DIR__ . '/../includes/richtext.php';

header('X-Content-Type-Options: nosniff');

$project_id = request_project_id();
if ($project_id === '') {
    json_fail('project parameter required');
}

try {
    $project = load_project($project_id);
} catch (RuntimeException $e) {
    json_fail($e->getMessage(), 500);
}
if (!$project) {
    json_fail('Unknown study', 404);
}
if (!data_authorized($project)) {
    json_fail('Not authorized for this study', 403);
}
$has_session = session_authorized($project_id); // deleting needs the unlocked study
// The costs and the exports take a while in large studies; without the session lock,
// the page's other requests (the list, a transcript) do not wait for them.
session_write_close();

$action = (string)($_GET['action'] ?? '');
$conversation_id = sanitize_conversation_id((string)($_GET['conversation_id'] ?? ''));

// =====================================================================================
// Shared helpers
// =====================================================================================

/**
 * Flatten a value into a CSV-safe single-line cell. Text that a spreadsheet
 * would run as a formula (it starts with = + - or @, e.g. a participant's
 * "=HYPERLINK(…)" or "-I disagree") gets a leading apostrophe, so Excel shows
 * it as text; plain numbers such as "-5" stay as they are.
 */
function csv_cell(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = preg_replace('/\r\n?|\n/u', ' ', $s) ?? '';
    $s = str_replace("\t", ' ', $s);
    $s = preg_replace('/ {2,}/', ' ', $s) ?? '';
    $s = trim($s);
    if (preg_match('/^[=+\-@]/', $s) && !preg_match('/^[+-]?\d+(\.\d+)?$/', $s)) {
        $s = "'" . $s;
    }
    return $s;
}

function csv_row($out, array $cells): void
{
    fputcsv($out, array_map(fn($c) => csv_cell((string)$c), $cells), ',', '"', '');
}

/** Open php://output as a download with BOM (Excel-friendly UTF-8). */
function csv_download(string $filename)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    return $out;
}

function pdf_download(string $filename, string $bytes): void
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

function safe_name(string $s): string
{
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', $s);
}

function fetch_conversation(string $project_id, string $conversation_id): array
{
    $stmt = db()->prepare(
        'SELECT client_id, sender, shown_at, message_text FROM messages
         WHERE project_id = ? AND conversation_id = ?
         ORDER BY shown_at ASC, id ASC'
    );
    $stmt->bind_param('ss', $project_id, $conversation_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** All messages of the project grouped per conversation, in delivery order. */
function fetch_all_grouped(string $project_id): array
{
    $stmt = db()->prepare(
        'SELECT conversation_id, client_id, sender, shown_at, message_text FROM messages
         WHERE project_id = ? ORDER BY conversation_id ASC, shown_at ASC, id ASC'
    );
    $stmt->bind_param('s', $project_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $grouped = [];
    while ($row = $res->fetch_assoc()) {
        $grouped[$row['conversation_id']][] = $row;
    }
    $stmt->close();

    // Order conversations by their first message time (oldest first — reading order).
    uasort($grouped, fn($a, $b) => strcmp((string)$a[0]['shown_at'], (string)$b[0]['shown_at']));
    return $grouped;
}

// ---- Director events: what happened to each participant message ------------------
//
// Every rewrite the relay ran is in director_events, linked to the participant
// message that triggered it (message_client_id = messages.client_id). A message
// is "unanswered" when no call for it succeeded — the team just continued with
// the script. Superseded calls (status "aborted": the participant wrote again
// before the reply arrived) are not failures.

/** Director calls during chats (participant messages and segment starts) of the project (or of one conversation), oldest first. */
function fetch_director_events(string $project_id, ?string $conversation_id = null): array
{
    $sql = 'SELECT conversation_id, message_client_id, mode, status, provider, model, effort, first_token_ms, duration_ms,
                   input_tokens, output_tokens, cached_tokens, cache_write_tokens, error, created_at
            FROM director_events WHERE project_id = ? AND mode IN (\'rewrite\', \'segment\')';
    if ($conversation_id !== null) {
        $sql .= ' AND conversation_id = ?';
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = db()->prepare($sql);
    if ($conversation_id !== null) {
        $stmt->bind_param('ss', $project_id, $conversation_id);
    } else {
        $stmt->bind_param('s', $project_id);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Per conversation: ['calls' => n, 'failed' => n, 'by_message' => [client_id => ['status' => ok|failed|superseded, 'error' => …]],
 * 'segments_failed' => n]. A message (or segment start, "segment-<number>") with several calls (retries) counts as ok
 * when any call succeeded. A segment start whose call failed left the team silent until the participant wrote.
 */
function director_summary(array $events): array
{
    $out = [];
    $starts = []; // conversation id => segment id => status
    foreach ($events as $ev) {
        $cid = (string)$ev['conversation_id'];
        $out[$cid] ??= ['calls' => 0, 'failed' => 0, 'by_message' => [], 'segments_failed' => 0];
        $out[$cid]['calls']++;
        $mid = (string)($ev['message_client_id'] ?? '');
        if ($mid === '') {
            continue;
        }
        $status = (string)$ev['status'];
        $kind = $status === 'ok' ? 'ok' : ($status === 'aborted' ? 'superseded' : 'failed');
        if (($ev['mode'] ?? 'rewrite') === 'segment') {
            $cur = $starts[$cid][$mid] ?? null;
            if ($cur === null || $kind === 'ok' || ($kind === 'failed' && $cur === 'superseded')) {
                $starts[$cid][$mid] = $kind;
            }
            continue;
        }
        $cur = $out[$cid]['by_message'][$mid] ?? null;
        if ($cur === null || $kind === 'ok' || ($kind === 'failed' && $cur['status'] === 'superseded')) {
            $out[$cid]['by_message'][$mid] = ['status' => $kind, 'error' => $kind === 'failed' ? (string)$ev['error'] : ''];
        }
    }
    foreach ($out as $cid => &$c) {
        $c['failed'] = count(array_filter($c['by_message'], fn($m) => $m['status'] === 'failed'));
        $c['segments_failed'] = count(array_filter($starts[$cid] ?? [], fn($k) => $k === 'failed'));
    }
    unset($c);
    return $out;
}

/** The note shown under an unanswered participant message (data page, PDF). */
function director_note(array $entry): string
{
    return 'The team did not react to this message, because the conductor failed' . ($entry['error'] !== '' ? ' (' . $entry['error'] . ')' : '') . '. The sample conversation continued as written.';
}

/** Notes for the PDF: [client_id => text] for one conversation. */
function director_notes_for(array $summary, string $cid): array
{
    $notes = [];
    foreach (($summary[$cid]['by_message'] ?? []) as $mid => $entry) {
        if ($entry['status'] === 'failed') {
            $notes[$mid] = director_note($entry);
        }
    }
    return $notes;
}

/**
 * Tokens and estimated costs of the project (includes/usage.php). The
 * messages are only needed to estimate calls without token counts, so they
 * are loaded only then (or passed in when the caller has them already).
 */
function project_usage(array $project, ?array $grouped = null): array
{
    $events = usage_fetch_events((string)$project['project_id']);
    $needsText = false;
    foreach ($events as $ev) {
        if ($ev['input_tokens'] === null && $ev['status'] !== 'client-error') {
            $needsText = true;
            break;
        }
    }
    if ($grouped === null) {
        $grouped = $needsText ? fetch_all_grouped((string)$project['project_id']) : [];
    }
    return usage_summary($project, $events, $grouped);
}

/** The usage cells of a conversation for the CSV exports. */
function usage_cells(array $usage, string $cid): array
{
    $b = $usage['conversations'][$cid] ?? null;
    if ($b === null) {
        return [0, 0, '', ''];
    }
    return [$b['input'], $b['output'], $b['priced_calls'] > 0 ? number_format($b['cost'], 6, '.', '') : '', $b['estimated_calls'] > 0 ? 'yes' : 'no'];
}

// ---- CSV writers (shared between direct downloads and the ZIP archive) ------------

/** director_status / director_error cells for one message row. */
function director_cells(array $summary, string $cid, array $r): array
{
    if (($r['sender'] ?? '') !== 'human') {
        return ['', ''];
    }
    $entry = $summary[$cid]['by_message'][(string)($r['client_id'] ?? '')] ?? null;
    if ($entry === null) {
        return ['', ''];
    }
    return [$entry['status'], $entry['error']];
}

function write_conversation_csv($fh, string $project_id, string $cid, array $rows, array $summary = []): void
{
    csv_row($fh, ['project_id', 'conversation_id', 'sender', 'shown_at', 'message_text', 'director_status', 'director_error']);
    foreach ($rows as $r) {
        csv_row($fh, array_merge([$project_id, $cid, $r['sender'], $r['shown_at'], $r['message_text']], director_cells($summary, $cid, $r)));
    }
}

function write_all_messages_csv($fh, string $project_id, array $grouped, array $summary = []): void
{
    csv_row($fh, ['project_id', 'conversation_id', 'sender', 'shown_at', 'message_text', 'director_status', 'director_error']);
    foreach ($grouped as $cid => $rows) {
        foreach ($rows as $r) {
            csv_row($fh, array_merge([$project_id, $cid, $r['sender'], $r['shown_at'], $r['message_text']], director_cells($summary, (string)$cid, $r)));
        }
    }
}

/**
 * Every director call of the project — the raw telemetry (archive only), with
 * the tokens the provider reported (empty when none were reported) and the
 * estimated cost at list prices.
 */
function write_director_events_csv($fh, string $project_id, array $events): void
{
    csv_row($fh, ['project_id', 'conversation_id', 'message_client_id', 'status', 'provider', 'model', 'effort', 'first_token_ms', 'duration_ms',
        'input_tokens', 'output_tokens', 'cached_tokens', 'estimated_cost_usd', 'error', 'created_at', 'mode']);
    foreach ($events as $ev) {
        $cost = '';
        if (($ev['input_tokens'] ?? null) !== null && ($price = pricing_for((string)$ev['provider'], (string)$ev['model'])) !== null) {
            $cost = number_format(pricing_cost($price, (int)$ev['input_tokens'], (int)$ev['output_tokens'], (int)$ev['cached_tokens'], (int)$ev['cache_write_tokens']), 6, '.', '');
        }
        csv_row($fh, [$project_id, $ev['conversation_id'], $ev['message_client_id'] ?? '', $ev['status'], $ev['provider'], $ev['model'], $ev['effort'],
            $ev['first_token_ms'] ?? '', $ev['duration_ms'], $ev['input_tokens'] ?? '', $ev['output_tokens'] ?? '', $ev['cached_tokens'] ?? '',
            $cost, $ev['error'], $ev['created_at'], $ev['mode'] ?? 'rewrite']);
    }
}

/**
 * One row per participant. Optional text columns (both joined with "||"):
 *   $humanCol      — human_messages: only what the participant typed
 *   $transcriptCol — full_transcript: every message prefixed with its sender
 *                    (can produce very large cells; Excel caps a cell at 32,767 chars)
 */
function write_overview_csv($fh, string $project_id, array $grouped, bool $humanCol = true, bool $transcriptCol = false, array $summary = [], array $usage = []): void
{
    $header = [
        'project_id', 'participant_id', 'start_time', 'end_time', 'duration_seconds',
        'message_count', 'human_message_count', 'bot_message_count', 'director_calls', 'unanswered_messages',
        'input_tokens', 'output_tokens', 'estimated_cost_usd', 'tokens_estimated',
    ];
    if ($humanCol) {
        $header[] = 'human_messages';
    }
    if ($transcriptCol) {
        $header[] = 'full_transcript';
    }
    csv_row($fh, $header);

    foreach ($grouped as $pid => $msgs) {
        $count = count($msgs);
        $start = $end = '';
        $durationSec = '';
        try {
            $startDt = new DateTime((string)$msgs[0]['shown_at']);
            $endDt   = new DateTime((string)$msgs[$count - 1]['shown_at']);
            $durationSec = number_format((float)$endDt->format('U.u') - (float)$startDt->format('U.u'), 1, '.', '');
            $start = $startDt->format('Y-m-d H:i:s');
            $end   = $endDt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            // leave formatted fields empty on parse failure
        }
        $humanTexts = [];
        $transcript = [];
        $humanCount = 0;
        foreach ($msgs as $m) {
            $clean = csv_cell((string)$m['message_text']);
            if ($m['sender'] === 'human') {
                $humanCount++;
                if ($clean !== '') {
                    $humanTexts[] = $clean;
                }
            }
            if ($transcriptCol && $clean !== '') {
                $transcript[] = $m['sender'] . ': ' . $clean;
            }
        }
        $row = array_merge([
            $project_id, $pid, $start, $end, $durationSec,
            $count, $humanCount, $count - $humanCount,
            $summary[$pid]['calls'] ?? 0, $summary[$pid]['failed'] ?? 0,
        ], usage_cells($usage, (string)$pid));
        if ($humanCol) {
            $row[] = implode(' || ', $humanTexts);
        }
        if ($transcriptCol) {
            $row[] = implode(' || ', $transcript);
        }
        csv_row($fh, $row);
    }
}

/** Render a CSV into a string via php://temp (for ZIP entries). */
function csv_to_string(callable $writer): string
{
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, "\xEF\xBB\xBF");
    $writer($fh);
    rewind($fh);
    $s = stream_get_contents($fh);
    fclose($fh);
    return $s === false ? '' : $s;
}

/**
 * The instructions shown above the chat as a small page of its own, so the
 * formatted text can be opened in a browser and looks as it does in the chat.
 */
function chat_instructions_document(array $project): string
{
    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>Instructions above the chat</title>\n"
        . "<style>\n"
        . "  body { font: 15px/1.5 -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Arial, sans-serif; color: " . RICHTEXT_DEFAULT_COLOR . "; max-width: 710px; margin: 24px auto; padding: 0 16px; }\n"
        . "  p { margin: 0; }\n  ul, ol { margin: 4px 0; padding-left: 26px; }\n  li { margin: 2px 0; }\n"
        . "</style>\n</head>\n<body>\n"
        . richtext_render((string)($project['chat_instructions'] ?? ''))
        . "\n</body>\n</html>\n";
}

/** Project configuration as an array — everything a researcher needs, no secrets. */
function project_configuration(array $project, array $grouped): array
{
    $messageCount = 0;
    foreach ($grouped as $rows) {
        $messageCount += count($rows);
    }
    $script = json_decode((string)$project['conversation_script'], true);
    return [
        'export' => [
            'tool'        => 'MAESTRO',
            'type'        => 'full study archive',
            'exported_at' => date('c'),
        ],
        'project' => [
            'project_id' => (string)$project['project_id'],
            'name'       => (string)$project['name'],
            'created_at' => (string)$project['created_at'],
            'updated_at' => (string)$project['updated_at'],
        ],
        'team' => team_all($project), // everyone in the chat; "human" is the participant
        'study_context' => (string)($project['study_context'] ?? ''),
        'ai' => [
            'provider'         => (string)$project['api_provider'],
            'model'            => (string)$project['model'],
            'api_format'       => (string)($project['api_format'] ?? 'auto'),
            'director_effort'  => (string)($project['director_effort'] ?? ''),
            'api_endpoint'     => (string)$project['api_endpoint'],
            'api_key'          => '(omitted from export)',
        ],
        'session_flow' => [
            'show_ready_modal'       => (bool)$project['show_ready_modal'],
            'ready_wait_seconds'     => (int)$project['ready_wait_seconds'],
            'show_instructions'      => (bool)$project['show_instructions'],
            'timer_enabled'          => (bool)$project['timer_enabled'],
            'timer_duration_seconds' => (int)$project['timer_duration_seconds'],
            'show_chat_instructions' => (bool)($project['show_chat_instructions'] ?? 0),
        ],
        'participant_instructions' => (string)$project['participant_instructions'], // plain text, on the waiting screen
        'chat_instructions'        => richtext_render((string)($project['chat_instructions'] ?? '')), // formatted (HTML), above the chat
        'director_instructions'    => (string)$project['director_instructions'],
        'director_sections'        => json_decode((string)($project['director_sections'] ?? ''), true), // the same text as sections (null for older studies)
        'conversation_script'      => is_array($script) ? $script : (string)$project['conversation_script'],
        'data' => [
            'conversation_count' => count($grouped),
            'message_count'      => $messageCount,
        ],
    ];
}

// =====================================================================================
// Data actions
// =====================================================================================

if ($action === 'list') {
    $stmt = db()->prepare(
        'SELECT conversation_id,
                MIN(shown_at) AS first_at,
                MAX(shown_at) AS last_at,
                COUNT(*) AS message_count,
                SUM(sender = \'human\') AS human_count
         FROM messages
         WHERE project_id = ?
         GROUP BY conversation_id
         ORDER BY first_at DESC'
    );
    $stmt->bind_param('s', $project_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $summary = director_summary(fetch_director_events($project_id));
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $cid = (string)$row['conversation_id'];
        $rows[] = [
            'conversation_id' => $cid,
            'first_at'        => (string)$row['first_at'],
            'last_at'         => (string)$row['last_at'],
            'message_count'   => (int)$row['message_count'],
            'human_count'     => (int)$row['human_count'],
            'director_calls'  => $summary[$cid]['calls'] ?? 0,
            'director_failed' => $summary[$cid]['failed'] ?? 0,
            'segments_failed' => $summary[$cid]['segments_failed'] ?? 0,
            'is_test'         => str_starts_with($cid, 'TEST'),
        ];
    }
    $stmt->close();
    json_out([
        'ok' => true,
        'project_id' => $project_id,
        'project_name' => (string)$project['name'],
        'conversations' => $rows,
    ]);
}

// The tokens and costs come apart from the list: estimating the calls without token
// counts takes a while in large studies, and the pages show the list in the meantime.
if ($action === 'usage') {
    $usage = project_usage($project);
    json_out([
        'ok' => true,
        'per_conversation' => (object)array_map('usage_public', $usage['conversations']), // {} when empty
        'usage' => [
            'as_of'      => $usage['as_of'],
            'models'     => $usage['models'],
            'all'        => usage_public($usage['all']),
            'real'       => usage_public($usage['real']),
            'assistants' => usage_public($usage['assistants']),
            'total'      => usage_public($usage['total']),
        ],
    ]);
}

if ($action === 'messages') {
    if ($conversation_id === '') {
        json_fail('conversation_id required');
    }
    $messages = array_map(fn($r) => [
        'client_id'    => (string)($r['client_id'] ?? ''),
        'sender'       => (string)$r['sender'],
        'shown_at'     => (string)$r['shown_at'],
        'message_text' => (string)$r['message_text'],
    ], fetch_conversation($project_id, $conversation_id));
    $summary = director_summary(fetch_director_events($project_id, $conversation_id));
    json_out([
        'ok' => true,
        'conversation_id' => $conversation_id,
        'messages' => $messages,
        'director' => $summary[$conversation_id] ?? ['calls' => 0, 'failed' => 0, 'by_message' => new stdClass(), 'segments_failed' => 0],
    ]);
}

// =====================================================================================
// Delete one conversation (destructive, cannot be undone)
// =====================================================================================
// POST + owner session required: the shareable data key (?key=…) is a
// read-only credential and must not be able to destroy data.
if ($action === 'delete_conversation') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_fail('POST required', 405);
    }
    if (!$has_session) {
        json_fail('Deleting requires the unlocked study. Open the study from the Studies page first.', 403);
    }
    $body = read_json_body();
    $cid = sanitize_conversation_id((string)($body['conversation_id'] ?? ''));
    if ($cid === '') {
        json_fail('conversation_id required');
    }
    $stmt = db()->prepare('DELETE FROM messages WHERE project_id = ? AND conversation_id = ?');
    $stmt->bind_param('ss', $project_id, $cid);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
    if ($deleted === 0) {
        json_fail('Conversation not found', 404);
    }
    $stmt = db()->prepare('DELETE FROM director_events WHERE project_id = ? AND conversation_id = ?');
    $stmt->bind_param('ss', $project_id, $cid);
    $stmt->execute();
    $stmt->close();
    json_out(['ok' => true, 'conversation_id' => $cid, 'deleted_messages' => $deleted]);
}

// =====================================================================================
// Delete ALL recorded data of the study (destructive, cannot be undone)
// =====================================================================================
// Removes every conversation (messages) and all director telemetry of the
// project. The project itself — settings, script, team, instructions — stays.
// Guards: POST, owner session, and the exact project id retyped in the body
// (the client asks for it; re-checking here keeps the API itself safe).
if ($action === 'wipe_project_data') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_fail('POST required', 405);
    }
    if (!$has_session) {
        json_fail('Deleting requires the unlocked study. Open the study from the Studies page first.', 403);
    }
    $body = read_json_body();
    if (trim((string)($body['confirm_project_id'] ?? '')) !== $project_id) {
        json_fail('Confirmation text does not match the study ID. Nothing was deleted.', 400);
    }

    $stmt = db()->prepare('DELETE FROM messages WHERE project_id = ?');
    $stmt->bind_param('s', $project_id);
    $stmt->execute();
    $deletedMessages = $stmt->affected_rows;
    $stmt->close();

    $stmt = db()->prepare('DELETE FROM director_events WHERE project_id = ?');
    $stmt->bind_param('s', $project_id);
    $stmt->execute();
    $deletedEvents = $stmt->affected_rows;
    $stmt->close();

    json_out([
        'ok' => true,
        'project_id' => $project_id,
        'deleted_messages' => $deletedMessages,
        'deleted_events' => $deletedEvents,
    ]);
}

// =====================================================================================
// Single-conversation exports
// =====================================================================================

if ($action === 'download_csv') {
    if ($conversation_id === '') {
        json_fail('conversation_id required');
    }
    $rows = fetch_conversation($project_id, $conversation_id);
    $summary = director_summary(fetch_director_events($project_id, $conversation_id));
    $out = csv_download('project_' . safe_name($project_id) . '_conversation_' . safe_name($conversation_id) . '_' . date('Ymd_His') . '.csv');
    write_conversation_csv($out, $project_id, $conversation_id, $rows, $summary);
    fclose($out);
    exit;
}

if ($action === 'download_pdf') {
    if ($conversation_id === '') {
        json_fail('conversation_id required');
    }
    $rows = fetch_conversation($project_id, $conversation_id);
    if (!$rows) {
        json_fail('No messages in this conversation', 404);
    }
    $summary = director_summary(fetch_director_events($project_id, $conversation_id));
    $bytes = render_transcript_pdf($project, [
        ['conversation_id' => $conversation_id, 'messages' => $rows, 'notes' => director_notes_for($summary, $conversation_id)],
    ]);
    pdf_download('conversation_' . safe_name($conversation_id) . '_' . date('Ymd_His') . '.pdf', $bytes);
}

// =====================================================================================
// Whole-project exports
// =====================================================================================

if ($action === 'download_all_csv') {
    $grouped = fetch_all_grouped($project_id);
    $summary = director_summary(fetch_director_events($project_id));
    $out = csv_download('project_' . safe_name($project_id) . '_all_conversations_' . date('Ymd_His') . '.csv');
    write_all_messages_csv($out, $project_id, $grouped, $summary);
    fclose($out);
    exit;
}

if ($action === 'download_all_pdf') {
    $grouped = fetch_all_grouped($project_id);
    if (!$grouped) {
        json_fail('No conversations recorded yet', 404);
    }
    $summary = director_summary(fetch_director_events($project_id));
    $conversations = [];
    foreach ($grouped as $cid => $rows) {
        $conversations[] = ['conversation_id' => (string)$cid, 'messages' => $rows, 'notes' => director_notes_for($summary, (string)$cid)];
    }
    $bytes = render_transcript_pdf($project, $conversations);
    pdf_download('project_' . safe_name($project_id) . '_transcripts_' . date('Ymd_His') . '.pdf', $bytes);
}

if ($action === 'download_overview_csv') {
    $humanCol      = ($_GET['include_human'] ?? '1') !== '0';
    $transcriptCol = ($_GET['include_transcript'] ?? '0') === '1';
    $grouped = fetch_all_grouped($project_id);
    $summary = director_summary(fetch_director_events($project_id));
    $usage   = project_usage($project, $grouped);
    $out = csv_download('project_' . safe_name($project_id) . '_overview_' . date('Ymd_His') . '.csv');
    write_overview_csv($out, $project_id, $grouped, $humanCol, $transcriptCol, $summary, $usage);
    fclose($out);
    exit;
}

if ($action === 'download_zip') {
    if (!class_exists('ZipArchive')) {
        json_fail('The PHP zip extension is not available on this server', 500);
    }
    $grouped = fetch_all_grouped($project_id);
    $events  = fetch_director_events($project_id);
    $summary = director_summary($events);
    $usage   = project_usage($project, $grouped);

    $tmp = tempnam(sys_get_temp_dir(), 'aitzip');
    // The temporary archive goes in every case: also after an error, a
    // timeout, or a download the researcher cancelled.
    register_shutdown_function(fn() => is_file($tmp) && @unlink($tmp));
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        json_fail('Could not create the ZIP archive', 500);
    }

    $stamp = date('Ymd_His');
    $rootLabel = 'project_' . safe_name($project_id) . '_' . $stamp;

    // 1. Configuration (secrets stripped) + standalone instruction files.
    $config = project_configuration($project, $grouped);
    $zip->addFromString("{$rootLabel}/configuration/project_configuration.json",
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->addFromString("{$rootLabel}/configuration/director_instructions.txt",
        (string)$project['director_instructions']);
    $zip->addFromString("{$rootLabel}/configuration/participant_instructions.txt",
        (string)$project['participant_instructions']);
    $zip->addFromString("{$rootLabel}/configuration/chat_instructions.html", chat_instructions_document($project));
    $zip->addFromString("{$rootLabel}/configuration/study_context.txt",
        (string)($project['study_context'] ?? ''));
    $scriptPretty = json_decode((string)$project['conversation_script'], true);
    $zip->addFromString("{$rootLabel}/configuration/conversation_script.json",
        $scriptPretty !== null
            ? json_encode($scriptPretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string)$project['conversation_script']);

    // 2. Project-level tables.
    $zip->addFromString("{$rootLabel}/participant_overview.csv",
        csv_to_string(fn($fh) => write_overview_csv($fh, $project_id, $grouped, true, false, $summary, $usage)));
    $zip->addFromString("{$rootLabel}/all_messages.csv",
        csv_to_string(fn($fh) => write_all_messages_csv($fh, $project_id, $grouped, $summary)));
    $zip->addFromString("{$rootLabel}/director_events.csv",
        csv_to_string(fn($fh) => write_director_events_csv($fh, $project_id, $events)));

    // 3. Every conversation as CSV + rendered PDF.
    // Sanitized ids can collide ("a b" and "a/b"; "P01" and "p01" on a
    // case-insensitive disk): a name already taken gets a number until it is free.
    $usedNames = [];
    foreach ($grouped as $cid => $rows) {
        $base = safe_name((string)$cid);
        for ($n = 2, $name = $base; isset($usedNames[strtolower($name)]); $n++) {
            $name = $base . '_' . $n;
        }
        $usedNames[strtolower($name)] = true;
        $base = $name;
        $zip->addFromString("{$rootLabel}/conversations/{$base}.csv",
            csv_to_string(fn($fh) => write_conversation_csv($fh, $project_id, (string)$cid, $rows, $summary)));
        $zip->addFromString("{$rootLabel}/conversations/{$base}.pdf",
            render_transcript_pdf($project, [['conversation_id' => (string)$cid, 'messages' => $rows, 'notes' => director_notes_for($summary, (string)$cid)]]));
    }

    if ($zip->close() !== true) {
        json_fail('Could not write the ZIP archive (is the temporary directory of the server full?)', 500);
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $rootLabel . '.zip"');
    header('Content-Length: ' . (string)filesize($tmp));
    readfile($tmp);
    exit;
}

json_fail('Unsupported action');
