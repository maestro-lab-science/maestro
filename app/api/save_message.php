<?php
/**
 * api/save_message.php — persist what the participant saw, and what went wrong.
 *
 * Accepts one message or a batch:
 *   { project_id, conversation_id,
 *     messages:        [ { client_id, sender, shown_at, message_text }, … ],
 *     director_events: [ { message_client_id, status, error, segment? }, … ] }  (optional)
 *   (legacy single form: { project_id, conversation_id, sender, shown_at, message_text })
 *
 * client_id is generated in the browser once per message. Together with the
 * unique key on (project_id, conversation_id, client_id) it makes every save
 * idempotent: the browser may retry as often as it needs to after a flaky
 * connection and never creates a duplicate row. The endpoint also accepts
 * navigator.sendBeacon() bodies (same JSON, sent when the page is closing).
 *
 * director_events are the browser's reports of rewrites that never reached the
 * relay (network failure, timeout). The relay records every request it does
 * see itself (includes/director.php), so together the log is complete and the
 * data page can show which participant messages got no reaction.
 *
 * Reply: { ok: true, saved: n, duplicates: m, rejected: k, reported: r }
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/director.php';

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST required', 405);
}

$payload = read_json_body(false); // also sendBeacon() bodies; this endpoint uses no cookies

$project_id      = sanitize_project_id((string)($payload['project_id'] ?? ''));
$conversation_id = sanitize_conversation_id((string)($payload['conversation_id'] ?? ''));
if ($project_id === '') {
    json_fail('project_id required');
}
if ($conversation_id === '') {
    json_fail('conversation_id required');
}

$items  = is_array($payload['messages'] ?? null) ? $payload['messages'] : [$payload];
$events = is_array($payload['director_events'] ?? null) ? $payload['director_events'] : [];

$saved = $duplicates = $rejected = $reported = 0;
try {
    // Only into studies that exist: the endpoint is public, and the chat's save
    // queue drops what is refused with 404 instead of retrying it forever.
    $check = db()->prepare('SELECT 1 FROM projects WHERE project_id = ? LIMIT 1');
    $check->bind_param('s', $project_id);
    $check->execute();
    $exists = $check->get_result()->fetch_row() !== null;
    $check->close();
    if (!$exists) {
        json_fail('Unknown study', 404);
    }

    $stmt = db()->prepare(
        'INSERT IGNORE INTO messages (project_id, conversation_id, client_id, sender, shown_at, message_text)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach (array_slice($items, 0, 200) as $m) {
        if (!is_array($m)) {
            $rejected++;
            continue;
        }
        $sender = (string)($m['sender'] ?? '');
        $text   = (string)($m['message_text'] ?? '');
        if ($sender !== 'human' && !preg_match('/^bot(10|[1-9])$/', $sender)) {
            $rejected++;
            continue;
        }
        $clientId = trim((string)($m['client_id'] ?? ''));
        $clientId = $clientId !== '' ? substr(preg_replace('/[^A-Za-z0-9_-]/', '', $clientId), 0, 40) : null;
        // Stored in UTC, whatever the server's time zone (the chat sends toISOString()).
        $dt = date_create((string)($m['shown_at'] ?? '')) ?: new DateTime('now');
        $shownAt = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');

        $stmt->bind_param('ssssss', $project_id, $conversation_id, $clientId, $sender, $shownAt, $text);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $saved++;
        } else {
            $duplicates++; // already stored by an earlier attempt
        }
    }
    $stmt->close();

    // Browsers may only report failures; everything the relay saw, it logged itself.
    // A report is stored once per message: the browser retries a whole batch
    // when the reply was lost, and the same failure must not count twice.
    $seen = db()->prepare(
        'SELECT 1 FROM director_events
         WHERE project_id = ? AND conversation_id = ? AND message_client_id = ? AND status = \'client-error\' LIMIT 1'
    );
    foreach (array_slice($events, 0, 20) as $ev) {
        if (!is_array($ev) || (string)($ev['status'] ?? '') !== 'client-error') {
            continue;
        }
        // The call at a segment start has no participant message: it is recorded as "segment-<number>".
        $segment = isset($ev['segment']) && is_int($ev['segment']) && $ev['segment'] >= 2 && $ev['segment'] <= 100 ? $ev['segment'] : null;
        $msgClient = $segment === null
            ? substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($ev['message_client_id'] ?? '')), 0, 40)
            : 'segment-' . $segment;
        if ($msgClient !== '') {
            $seen->bind_param('sss', $project_id, $conversation_id, $msgClient);
            $seen->execute();
            $already = (bool)$seen->get_result()->fetch_row();
            if ($already) {
                continue; // reported by an earlier attempt of the same batch
            }
        }
        director_log_event($project_id, $conversation_id, [
            'mode' => $segment === null ? 'rewrite' : 'segment', 'status' => 'client-error',
            'error' => (string)($ev['error'] ?? ''),
            'message_client_id' => $msgClient,
        ]);
        $reported++;
    }
    $seen->close();

    json_out(['ok' => true, 'saved' => $saved, 'duplicates' => $duplicates, 'rejected' => $rejected, 'reported' => $reported]);
} catch (mysqli_sql_exception $e) { // before RuntimeException: it is a subclass of it
    json_fail('Insert failed', 500);
} catch (RuntimeException $e) {
    json_fail($e->getMessage(), 500);
}
