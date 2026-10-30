<?php
/**
 * usage.php — tokens and estimated costs of a study, per conversation and in total.
 *
 * Every model call is a row in director_events: the rewrites during a chat
 * (mode "rewrite" after a participant message, "segment" at a segment start; linked to
 * a conversation) and the AI Assistant of the settings page (modes "assist_plan",
 * "assist_field", "assist_review"; until 2026-10 "assist" and "assist_dir"; no conversation). Since 2026-09-24 each row
 * carries the token counts the provider reported. Rows without counts are
 * estimated from their texts and flagged:
 *   - calls recorded before token counting existed,
 *   - calls cancelled before the provider's final event (the participant wrote
 *     again), which the provider still bills.
 * Costs use the list prices in pricing.php; custom gateways get tokens only.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/team.php';
require_once __DIR__ . '/director.php';
require_once __DIR__ . '/pricing.php';

/** All model calls of a project (every mode), oldest first. */
function usage_fetch_events(string $projectId): array
{
    $stmt = db()->prepare(
        'SELECT conversation_id, message_client_id, mode, provider, model, status, output_chars, output_json, input_tokens,
                output_tokens, cached_tokens, cache_write_tokens, created_at
         FROM director_events WHERE project_id = ? ORDER BY id ASC'
    );
    $stmt->bind_param('s', $projectId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** An empty usage bucket. */
function usage_bucket(): array
{
    return ['calls' => 0, 'input' => 0, 'output' => 0, 'cached' => 0, 'cache_write' => 0,
            'cost' => 0.0, 'priced_calls' => 0, 'unpriced_calls' => 0, 'estimated_calls' => 0];
}

/** Add one call's usage to a bucket. */
function usage_add(array &$bucket, array $u): void
{
    $bucket['calls']++;
    foreach (['input', 'output', 'cached', 'cache_write'] as $k) {
        $bucket[$k] += $u[$k];
    }
    if ($u['cost'] !== null) {
        $bucket['cost'] += $u['cost'];
        $bucket['priced_calls']++;
    } elseif ($u['input'] + $u['output'] > 0) {
        $bucket['unpriced_calls']++;
    }
    if ($u['estimated']) {
        $bucket['estimated_calls']++;
    }
}

/**
 * Usage of a whole project.
 *
 * $grouped: the project's messages per conversation (api/data.php
 * fetch_all_grouped) — only needed to estimate rewrites without token counts.
 *
 * @return array{as_of:string, conversations:array<string,array>, assistants:array,
 *               all:array, real:array, total:array, models:string[]}
 *   conversations: per conversation id a bucket (+ 'rewrites'); all = every
 *   conversation, real = without TEST conversations, total = all + assistants.
 */
function usage_summary(array $project, array $events, array $grouped): array
{
    $est = usage_estimator($project, $grouped);
    $out = ['as_of' => PRICING_AS_OF, 'conversations' => [], 'assistants' => usage_bucket(),
            'all' => usage_bucket(), 'real' => usage_bucket(), 'total' => usage_bucket(), 'models' => []];
    foreach ($events as $ev) {
        $u = usage_of_event($ev, $est);
        if ($u === null) {
            continue; // never reached a provider (client-error)
        }
        $label = ['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'custom' => 'custom gateway'][$ev['provider']] ?? $ev['provider'];
        $out['models'][$ev['model'] . ' (' . $label . ')'] = true;
        usage_add($out['total'], $u);
        $cid = (string)$ev['conversation_id'];
        if (!in_array($ev['mode'], ['rewrite', 'segment'], true) || $cid === '') {
            usage_add($out['assistants'], $u);
            continue;
        }
        $out['conversations'][$cid] ??= usage_bucket();
        usage_add($out['conversations'][$cid], $u);
        usage_add($out['all'], $u);
        if (!str_starts_with($cid, 'TEST')) {
            usage_add($out['real'], $u);
        }
    }
    $out['models'] = array_keys($out['models']);
    return $out;
}

/**
 * Tokens and cost of one call: ['input', 'output', 'cached', 'cache_write',
 * 'cost' (USD or null without a price), 'estimated' (bool)], or null for a
 * call that never reached the provider.
 */
function usage_of_event(array $ev, callable $estimate): ?array
{
    if ($ev['status'] === 'client-error') {
        return null;
    }
    if ($ev['input_tokens'] !== null) {
        $u = ['input' => (int)$ev['input_tokens'], 'output' => (int)$ev['output_tokens'],
              'cached' => (int)$ev['cached_tokens'], 'cache_write' => (int)$ev['cache_write_tokens'], 'estimated' => false];
    } elseif ($ev['status'] === 'error' && (int)$ev['output_chars'] === 0) {
        return ['input' => 0, 'output' => 0, 'cached' => 0, 'cache_write' => 0, 'cost' => 0.0, 'estimated' => false];
    } else {
        $u = $estimate($ev) + ['cached' => 0, 'cache_write' => 0, 'estimated' => true];
    }
    $price = pricing_for((string)$ev['provider'], (string)$ev['model']);
    $u['cost'] = $price ? pricing_cost($price, $u['input'], $u['output'], $u['cached'], $u['cache_write']) : null;
    return $u;
}

/**
 * A function that estimates ['input', 'output'] for a call without token
 * counts, from the project's current texts and the conversation's messages up
 * to the participant message that triggered the call (the prompt is rebuilt
 * the way the chat builds it). Calls are matched to their message through
 * message_client_id, or else by order (the n-th rewrite answers the n-th
 * participant message): messages carry the browser's UTC time and the call log
 * the database's local time, so the two clocks must not be compared.
 * Call it for the events of a project in their stored order.
 */
function usage_estimator(array $project, array $grouped): callable
{
    $raw = fn(string $t) => pricing_estimate_tokens($t, 'raw'); // unscaled, see pricing_estimate_tokens
    $scaffold = director_prompt_scaffold($project);
    $context  = trim((string)($project['study_context'] ?? ''));
    // The director's prompt never contains the study context; the assistants' prompts do.
    $system   = $raw((string)$project['director_instructions']) + $raw($scaffold['system']);
    $userBase = $raw($scaffold['user']) + 8;

    // The script as the prompt lists it: one "  +Ns NAME (typing Ns): text" line each
    // (about 11 tokens besides the text, measured on real scripts).
    $script   = json_decode((string)$project['conversation_script'], true);
    $lines    = is_array($script) ? (is_array($script['messages'] ?? null) ? $script['messages'] : (array_is_list($script) ? $script : [])) : [];
    $scriptTokens = 0;
    $scriptEnd = 1;
    foreach ($lines as $l) {
        if (is_array($l)) {
            $scriptTokens += $raw((string)($l['content'] ?? '')) + 11;
            $scriptEnd = max($scriptEnd, (int)($l['send_at'] ?? 0), (int)($l['typing_end'] ?? 0));
        }
    }
    $assistBase = $raw((string)$project['director_instructions']) + $raw((string)$project['conversation_script'])
        + $raw($context) + 1500;
    $segments = segments_of_project($project);
    $rewritesSeen = []; // conversation id => rewrites estimated so far
    // conversation id => the tokens of its messages added up, message by message: each
    // message is counted once, not again for every later call (large studies took seconds)
    $saidUpTo = [];

    return function (array $ev) use ($raw, $grouped, $system, $userBase, $scriptTokens, $scriptEnd, $assistBase, $segments, &$rewritesSeen, &$saidUpTo): array {
        $family = pricing_token_family((string)$ev['provider'], (string)$ev['model']);
        $factor = PRICING_TOKEN_FACTORS[$family] ?? 1.0;
        // Output: the text the model returned (stored up to 16k characters).
        $json = (string)($ev['output_json'] ?? '');
        $chars = (int)$ev['output_chars'];
        $output = $json !== '' ? $raw($json) * max(1.0, $chars / max(1, strlen($json))) : $chars / 3.3;
        if (!in_array($ev['mode'], ['rewrite', 'segment'], true)) {
            return ['input' => (int)round($assistBase * $factor), 'output' => (int)round(($output + 300) * $factor)];
        }
        $cid = (string)$ev['conversation_id'];
        $messages = $grouped[$cid] ?? [];
        $upTo = null;
        if ($ev['mode'] === 'segment') {
            // The call at a segment start: the messages shown until then ("segment-<number>").
            $at = $segments[(int)substr((string)($ev['message_client_id'] ?? ''), 8) - 1]['start'] ?? null;
            $start = $messages ? strtotime(substr((string)$messages[0]['shown_at'], 0, 19)) : 0;
            foreach ($messages as $i => $m) {
                if ($at === null || strtotime(substr((string)$m['shown_at'], 0, 19)) - $start <= $at) {
                    $upTo = $i;
                }
            }
        } else {
            // The participant message this call answered.
            $nth = $rewritesSeen[$cid] = ($rewritesSeen[$cid] ?? 0) + 1;
            $humans = 0;
            foreach ($messages as $i => $m) {
                if ($m['sender'] !== 'human') {
                    continue;
                }
                $humans++;
                $linked = (string)($ev['message_client_id'] ?? '') !== '' && (string)$m['client_id'] === (string)$ev['message_client_id'];
                if ($linked || ($humans === $nth && $upTo === null)) {
                    $upTo = $i;
                    if ($linked) {
                        break;
                    }
                }
            }
        }
        $upTo ??= count($messages) - 1;
        // Input: system prompt, the whole script, what was said until then, and the rest of the plan.
        if (!isset($saidUpTo[$cid])) {
            $sum = 0;
            $saidUpTo[$cid] = [];
            foreach ($messages as $m) {
                $sum += $raw((string)$m['message_text']) + 7;
                $saidUpTo[$cid][] = $sum;
            }
        }
        $said = $upTo >= 0 ? $saidUpTo[$cid][$upTo] : 0; // the prompt carries every message so far
        $elapsed = $messages ? max(0, strtotime(substr((string)$messages[max(0, $upTo)]['shown_at'], 0, 19)) - strtotime(substr((string)$messages[0]['shown_at'], 0, 19))) : 0;
        $plan = $scriptTokens * max(0.0, 1 - $elapsed / $scriptEnd);
        return ['input' => (int)round(($system + $userBase + $scriptTokens + $said + $plan) * $factor),
                'output' => (int)round($output * $factor)];
    };
}

/** A bucket for JSON: rounded cost, and null cost when nothing could be priced. */
function usage_public(array $b): array
{
    $b['cost'] = $b['priced_calls'] > 0 ? round($b['cost'], 6) : null;
    return $b;
}
