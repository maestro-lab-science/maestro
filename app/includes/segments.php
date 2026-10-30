<?php
/**
 * segments.php — conversation segments: important events at fixed times.
 *
 * A sample conversation can be divided into segments. Each segment starts at a
 * fixed time (seconds since the chat started, after the waiting screen) and
 * marks an important event, a hard cut where the conversation changes a lot:
 * a teammate has to leave, new information arrives, the deadline moves. Its
 * prompt (1 to 3 sentences) says what changes; the director instructions
 * still apply.
 *
 * Why this exists: the director plans only when the participant writes, and
 * it plans in pauses ("+Ns after the previous line"), so it cannot keep a
 * moment such as "Blue leaves at 5:00" by itself. (Found 2026-09-24: every
 * reaction it added pushed the rest of its plan later, and a quiet participant
 * never gave it a chance to correct that.) At a segment start the chat cuts:
 * whatever was planned is dropped and the director is asked again for the new
 * segment (includes/director.php, assets/js/chat/session.js). Before the
 * participant has written anything, the sample conversation plays on as
 * written, segments included.
 *
 * Stored inside the sample conversation (projects.conversation_script):
 *   {"segments":[{"start":0,"prompt":""},{"start":300,"prompt":"Blue has to …"}],
 *    "messages":[{…,"send_at":12,"segment":1}, …, {…,"send_at":308,"segment":2}, …]}
 * Segment 1 always starts at 0:00; the others follow in time order. A
 * message's "segment" (1-based) says which segment it belongs to; a message
 * that comes at or after the start of the next segment is cut. Without
 * "segment" fields, messages belong to segments by their time. The chat page
 * receives the start times only; the prompts stay on the server, like the
 * director instructions.
 *
 * @author Marc Becker
 */

const SEGMENTS_MAX       = 20;
const SEGMENT_PROMPT_MAX = 400;      // 1 to 3 sentences
const SEGMENT_START_MAX  = 180 * 60; // the longest session timer

/**
 * Validate the "segments" list of a sample conversation. Returns the list
 * ([{start, prompt}], segment 1 at 0:00 first) or an error message for the
 * researcher. A list without a segment after the first counts as no segments.
 *
 * @return array<int, array{start:int, prompt:string}>|string
 */
function segments_normalize($raw)
{
    if ($raw === null || $raw === []) {
        return [];
    }
    if (!is_array($raw) || !array_is_list($raw)) {
        return 'The segments of the sample conversation could not be read.';
    }
    if (count($raw) > SEGMENTS_MAX) {
        return 'A sample conversation can have at most ' . SEGMENTS_MAX . ' segments.';
    }
    $out = [];
    foreach ($raw as $i => $s) {
        $start  = is_array($s) && is_numeric($s['start'] ?? null) ? (int)round((float)$s['start']) : -1;
        $prompt = is_array($s) ? trim((string)($s['prompt'] ?? '')) : '';
        $n = $i + 1;
        if ($i === 0 && $start !== 0) {
            return 'Segment 1 has to start at 0:00.';
        }
        if ($start < 0 || $start > SEGMENT_START_MAX) {
            return 'Segment ' . $n . ' needs a start time between 0:01 and ' . intdiv(SEGMENT_START_MAX, 60) . ':00.';
        }
        if ($i > 0 && $start <= $out[$i - 1]['start']) {
            return 'Segment ' . $n . ' has to start after segment ' . $i . ' (' . segments_clock($out[$i - 1]['start']) . ').';
        }
        if ((function_exists('mb_strlen') ? mb_strlen($prompt, 'UTF-8') : strlen($prompt)) > SEGMENT_PROMPT_MAX) {
            return 'The prompt of segment ' . $n . ' is too long. Keep the prompt to 1 to 3 sentences (at most ' . SEGMENT_PROMPT_MAX . ' characters).';
        }
        $out[] = ['start' => $start, 'prompt' => $prompt];
    }
    return count($out) > 1 ? $out : [];
}

/**
 * The segments of a decoded sample conversation. Tolerant: anything that does
 * not validate counts as no segments rather than breaking a chat.
 *
 * @return array<int, array{start:int, prompt:string}>
 */
function segments_of_script($script): array
{
    if (!is_array($script) || !isset($script['segments'])) {
        return [];
    }
    $segments = segments_normalize($script['segments']);
    return is_array($segments) ? $segments : [];
}

/** The segments of a project's stored sample conversation. */
function segments_of_project(array $project): array
{
    return segments_of_script(json_decode((string)($project['conversation_script'] ?? ''), true));
}

/** 300 → "5:00" */
function segments_clock(int $seconds): string
{
    return intdiv($seconds, 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}
