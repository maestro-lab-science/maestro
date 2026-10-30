<?php
/**
 * assist.php — the AI Assistant of the settings page (Step 3).
 *
 * One assistant edits every box of Step 3: the texts of the conductor
 * instructions (Situation, AI actors for all actors and per actor, Dynamics,
 * Purpose, Rewrite rules, Output format) and the sample conversation. A
 * request of the researcher becomes a run of several model calls, which the
 * browser starts one after another (assets/js/settings-assistants.js), each
 * through api/director.php:
 *
 *   assist_plan    reads everything and the chat, and returns either an answer
 *                  ({"answer":…}) or a plan: the boxes that need a change, each
 *                  with a task ({"reply":…,"steps":[{target,title,task},…]})
 *   assist_field   one call per step of the plan: returns the complete new text
 *                  of that one box (or the whole sample conversation), or a skip
 *   assist_review  checks the result against the request and the plan: either
 *                  done ({"ok":true,"reply","changes"}) or up to a few fixes,
 *                  which run once as more assist_field steps, followed by a
 *                  final review that only summarizes
 *
 * Every call streams to the browser (data: {"r":…} reasoning deltas,
 * data: {"d":…} text deltas) and ends with data: {"final":{…}} and
 * data: [DONE]. Every call is one row in director_events (modes assist_plan,
 * assist_field, assist_review), so the costs appear with the study.
 *
 * Targets (the boxes): situation, team (all AI actors), actor:bot1 … (one AI
 * actor only), manip, goal, rewrite, tech, script (the sample conversation).
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/team.php';
require_once __DIR__ . '/director.php'; // director_log_event(): the assistant is logged too
require_once __DIR__ . '/director_sections.php';
require_once __DIR__ . '/segments.php';

// ---------------------------------------------------------------------------
// Script format helpers (shared by the prompts and the validators)
// ---------------------------------------------------------------------------

/** Bound client-supplied text so one request stays reasonable. */
function assist_clamp($value, int $max): string
{
    $s = (string)$value;
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

/**
 * A client-supplied chat as [['role' => user|assistant, 'content' => …], …]:
 * the last $keep messages, each clamped to $max characters, empty ones dropped.
 */
function assist_chat_from_payload($list, int $keep, int $max): array
{
    $chat = [];
    foreach (array_slice(array_values((array)$list), -$keep) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $text = trim(assist_clamp($m['content'] ?? '', $max));
        if ($text !== '') {
            $chat[] = ['role' => $role, 'content' => $text];
        }
    }
    return $chat;
}

/** Strip ```json fences and salvage the outermost JSON object from model output. */
function decode_model_json(string $raw): ?array
{
    $raw = trim($raw);
    if (str_starts_with($raw, '```')) {
        $raw = preg_replace('/^```[a-zA-Z]*\s*/', '', $raw);
        $raw = preg_replace('/\s*```\s*$/', '', $raw);
    }
    $parsed = json_decode($raw, true);
    if (is_array($parsed)) {
        return $parsed;
    }
    $start = strpos($raw, '{');
    $end   = strrpos($raw, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $parsed = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (is_array($parsed)) {
            return $parsed;
        }
    }
    return null;
}

/** The script JSON format, as shown to the model. $humanName: how the prompts name the participant. */
function script_format_spec(array $members, string $humanName = 'YELLOW'): string
{
    return "{\"messages\":[{\"id\":1,\"sender\":\"bot1\",\"content\":\"Hello everyone!\",\"typing_pct\":40,\"send_at\":5,\"is_empty\":false}, ...]}" .
        "\n- sender is one of the teammates: " . team_id_legend($members) . ". The human participant (" . $humanName . ") is never scripted." .
        "\n- send_at = ABSOLUTE seconds from conversation start when the message appears. Strictly increasing." .
        "\n- typing_pct = typing-indicator duration as a percentage of the gap since the previous message (25–105)." .
        "\n- id = sequential integers starting at 1." .
        "\n- Ghost typing (someone types, then stops without sending): {\"id\":N,\"sender\":\"bot2\",\"content\":\"\",\"typing_start\":N,\"typing_end\":N,\"is_empty\":true}." .
        "\n- Segments (optional): a conversation can be divided into segments that mark important events, hard cuts where the" .
        " conversation changes a lot (a teammate has to leave, new information arrives, the deadline moves). Then the JSON starts" .
        " with \"segments\", BEFORE \"messages\": {\"segments\":[{\"start\":0,\"prompt\":\"\"},{\"start\":300,\"prompt\":\"1 to 3 sentences: what" .
        " changes now\"}, ...],\"messages\":[...]} (segment 1 starts at 0, the others at increasing ABSOLUTE seconds), and every" .
        " message has \"segment\": the number (1, 2, …) of the segment it belongs to, with its send_at at or after that segment's" .
        " start and before the next one's. Keep the existing segments as they are unless the researcher asks to change them. To" .
        " remove all segments, write \"segments\":[] and no \"segment\" numbers. A conversation without segments that should not" .
        " get any has neither key.";
}

/**
 * The segments for an assistant's new script: the ones it returned (an empty
 * list removes them), or the current ones when it left the key out or
 * returned something unusable.
 */
function assist_script_segments(array $result, string $oldContent): array
{
    if (array_key_exists('segments', $result)) {
        $segments = segments_normalize($result['segments']);
        if (is_array($segments)) {
            return $segments;
        }
    }
    return segments_of_script(json_decode($oldContent, true));
}

/**
 * Give every message of a divided script its segment number: the stated one
 * when the message lies within that segment, otherwise the one its time
 * falls into. Without segments the numbers are removed.
 */
function assist_assign_segments(array $messages, array $segments): array
{
    foreach ($messages as &$m) {
        if (!$segments) {
            unset($m['segment']);
            continue;
        }
        $t = $m['is_empty'] ? $m['typing_start'] : $m['send_at'];
        $stated = isset($m['segment']) && is_int($m['segment']) ? $m['segment'] : 0;
        $fits = $stated >= 1 && isset($segments[$stated - 1]) && $segments[$stated - 1]['start'] <= $t;
        if (!$fits) {
            $stated = 1;
            foreach ($segments as $i => $s) {
                if ($s['start'] <= $t) {
                    $stated = $i + 1;
                }
            }
        }
        $m['segment'] = $stated;
    }
    unset($m);
    return $messages;
}

/**
 * Validate and normalize a model-produced message list into the app's script
 * format (clean senders, integer times, time-sorted, sequential ids).
 * Returns null when nothing usable remains.
 */
function normalize_script_messages($msgs, array $members): ?array
{
    if (!is_array($msgs) || count($msgs) === 0) {
        return null;
    }
    $clean = [];
    foreach ($msgs as $m) {
        if (!is_array($m)) {
            continue;
        }
        $sender = team_sender_id($members, $m['sender'] ?? '');
        if ($sender === null) {
            continue;
        }
        if (!empty($m['is_empty'])) {
            if (!is_numeric($m['typing_start'] ?? null) || !is_numeric($m['typing_end'] ?? null)) {
                continue;
            }
            $row = [
                'sender' => $sender, 'content' => '',
                'typing_start' => (int)$m['typing_start'], 'typing_end' => (int)$m['typing_end'],
                'is_empty' => true,
            ];
        } else {
            $text = $m['content'] ?? '';
            if (!is_numeric($m['send_at'] ?? null) || !is_string($text) || trim($text) === '') {
                continue;
            }
            $row = ['sender' => $sender, 'content' => $text, 'send_at' => (int)$m['send_at'], 'is_empty' => false];
            if (is_numeric($m['typing_pct'] ?? null)) {
                $row['typing_pct'] = (int)$m['typing_pct'];
            }
            if (is_numeric($m['typing_start'] ?? null)) {
                $row['typing_start'] = (int)$m['typing_start'];
            }
        }
        if (is_numeric($m['segment'] ?? null)) {
            $row['segment'] = (int)$m['segment']; // checked against the segments by assist_assign_segments()
        }
        $clean[] = $row;
    }
    if (count($clean) === 0) {
        return null;
    }
    usort($clean, function ($a, $b) {
        $ta = $a['is_empty'] ? $a['typing_start'] : $a['send_at'];
        $tb = $b['is_empty'] ? $b['typing_start'] : $b['send_at'];
        return $ta <=> $tb;
    });
    foreach ($clean as $i => &$m) {
        $m = array_merge(['id' => $i + 1], $m);
    }
    unset($m);
    return $clean;
}

// ---------------------------------------------------------------------------
// The boxes of Step 3 and the state the browser sends
// ---------------------------------------------------------------------------

/** The text boxes of the conductor instructions: target => [title, what the box is for]. */
const ASSIST_TEXT_BOXES = [
    'situation' => ['Situation', 'the task during the conversation and the background: the facts of the scenario, what happened before the chat, the opening, and what the AI actors know that the participant does not know'],
    'team'      => ['AI actors: all actors', 'instructions for all AI actors: how the actors write (tone, length, phrasing bans such as no em dashes), how the actors react to the participant, what the actors do when the participant is silent, how the chat ends, what the actors must never do (such as admitting to be AI), and what the actors have in common'],
    'manip'     => ['Dynamics', 'the social dynamic the AI actors create: the climate and how the actors treat the participant, for example when the participant admits a mistake. In a study with several conditions, this box is what differs between the conditions'],
    'goal'      => ['Purpose', 'the role of the conductor and its priorities in order, for example the intended social climate first and realism second'],
    'rewrite'   => ['Rewrite rules', 'what the conductor keeps and what the conductor changes when the conductor rewrites the remaining conversation after a participant message, for example how a short remark and a direct question differ'],
    'tech'      => ['Output format', 'rules for the output of the conductor, such as how long the replies are. The JSON format itself is added by the software and is never described here. An expert box: most studies leave it short or empty'],
];

/** Every target of this study, in the order the page shows the boxes: target => [title, purpose]. */
function assist_targets(array $members): array
{
    $out = ['situation' => ASSIST_TEXT_BOXES['situation'], 'team' => ASSIST_TEXT_BOXES['team']];
    foreach ($members as $m) {
        $out['actor:' . $m['id']] = ['AI actors: only ' . $m['name'],
            'what makes ' . $m['name'] . ' unique: role, personality and way of writing, and anything that applies to ' . $m['name'] . ' only'];
    }
    foreach (['manip', 'goal', 'rewrite', 'tech'] as $key) {
        $out[$key] = ASSIST_TEXT_BOXES[$key];
    }
    $out['script'] = ['Sample conversation', 'the scripted messages of the AI actors with their timing, optionally divided into segments with a short prompt each. The AI actors play the conversation line by line until the participant writes'];
    return $out;
}

/** How much of each box the assistant reads (characters); a longer box is not edited. */
const ASSIST_BOX_MAX    = 60000;
const ASSIST_ACTOR_MAX  = 20000;
const ASSIST_SCRIPT_MAX = 120000;

/**
 * The state of Step 3 as the browser sends it (possibly unsaved):
 * ['boxes' => [key => text], 'actors' => [botN => text], 'script' => JSON text].
 */
function assist_state_from_payload($raw, array $members): array
{
    $raw = is_array($raw) ? $raw : [];
    $boxes = [];
    foreach (array_keys(ASSIST_TEXT_BOXES) as $key) {
        $boxes[$key] = assist_clamp($raw['boxes'][$key] ?? '', ASSIST_BOX_MAX);
    }
    $actors = [];
    foreach ($members as $m) {
        $actors[$m['id']] = assist_clamp($raw['actors'][$m['id']] ?? '', ASSIST_ACTOR_MAX);
    }
    return ['boxes' => $boxes, 'actors' => $actors, 'script' => trim(assist_clamp($raw['script'] ?? '', ASSIST_SCRIPT_MAX))];
}

/**
 * True when the box of a step is longer than the assistant reads: its answer
 * would replace the box without the part that was cut off.
 */
function assist_target_too_long($raw, string $target): bool
{
    $raw = is_array($raw) ? $raw : [];
    if ($target === 'script') {
        [$text, $max] = [$raw['script'] ?? '', ASSIST_SCRIPT_MAX];
    } elseif (str_starts_with($target, 'actor:')) {
        [$text, $max] = [$raw['actors'][substr($target, 6)] ?? '', ASSIST_ACTOR_MAX];
    } else {
        [$text, $max] = [$raw['boxes'][$target] ?? '', ASSIST_BOX_MAX];
    }
    $text = (string)$text;
    return (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) > $max;
}

/** The current content of one target: a box text or the script JSON. */
function assist_target_content(array $state, string $target): string
{
    if ($target === 'script') {
        return $state['script'];
    }
    if (str_starts_with($target, 'actor:')) {
        return (string)($state['actors'][substr($target, 6)] ?? '');
    }
    return (string)($state['boxes'][$target] ?? '');
}

// ---------------------------------------------------------------------------
// Prompt sections
// ---------------------------------------------------------------------------

/** "a team of three simulated teammates (Blue, Green and Red)" — for the assistant's framing. */
function assist_team_phrase(array $members): string
{
    $names = array_map(fn($m) => $m['name'], $members);
    $n = count($names);
    $list = $n === 1 ? $names[0] : implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[$n - 1];
    return ($n === 1 ? 'one AI actor' : $n . ' AI actors') . ' (' . $list . ')';
}

/** What MAESTRO is, what each box is for, and what the assistant may and may not do. */
function assist_study_brief(array $members, string $humanName): string
{
    $targets = assist_targets($members);
    $list = [];
    foreach ($targets as $target => [$title, $purpose]) {
        $list[] = '- ' . $target . ' (' . $title . '): ' . $purpose . '.';
    }
    return "You are the AI Assistant of MAESTRO, a research tool for live team chats. One human participant (" . $humanName . ") chats with " .
        assist_team_phrase($members) . " — ids " . implode(', ', team_ids($members)) . " — who work on a task together." .
        " The chat follows a sample conversation that the researcher wrote in advance: the AI actors send its messages at the scripted times." .
        " Whenever the participant writes, a language model called the conductor rewrites every message that is still to come, so the AI actors respond to the participant" .
        " while the situation, the personalities and the social dynamic stay as the researcher defined them. The conductor receives the texts of the boxes below as one prompt," .
        " together with the sample conversation and the chat so far. (Older texts call the conductor \"the director\"; both words mean the same.)" .
        "\nThe sample conversation can be divided into segments: important events at fixed times (an AI actor leaves, new information arrives), each with a prompt of 1 to 3 sentences." .
        " At a segment start the conversation is cut and the conductor continues with that segment's prompt on top of the boxes, so events at fixed times belong in segments, not in the boxes." .
        "\n\n## The boxes of Step 3 (your targets)\n" . implode("\n", $list) .
        "\n\n## What you can and cannot do" .
        "\nYou CAN: change the boxes above, one box per step, add new AI actors (see the plan rules), and answer questions about the study." .
        "\nYou CANNOT: rename or remove AI actors, change colors or pictures (the researcher does that in the rows at the top of the AI actors card)," .
        " change the study context or the session flow (Step 1), the AI model or the API key (Step 2), the order of the boxes in the prompt (the Reorder button of the \"Full conductor prompt\" card)," .
        " or run the chat (the \"Test chat\" button at the top of the page). When the researcher asks for one of these, say where the researcher can do it instead of trying it inside a box.";
}

/** The researcher's study context and the instructions above the chat, as background ('' when both are empty). */
function assist_background_section(string $context, string $participantInfo): string
{
    $out = '';
    if (trim($context) !== '') {
        $out .= "\n\n## Study context (background from the researcher, Step 1)" .
            "\nBackground for you only: the conductor and the participants never see the study context, so anything the conductor must follow belongs in the boxes." .
            " Follow the preferences the context states, such as the language the AI actors write in:\n" . trim($context);
    }
    if (trim($participantInfo) !== '') {
        $out .= "\n\n## What the participant reads before the chat (Step 1)\n" . trim($participantInfo);
    }
    return $out;
}

/** Every box with its current text, and the sample conversation. */
function assist_state_section(array $state, array $members, string $heading = 'Current content of the boxes'): string
{
    $out = "\n\n## " . $heading;
    foreach (assist_targets($members) as $target => [$title]) {
        if ($target === 'script') {
            continue;
        }
        $text = trim(assist_target_content($state, $target));
        $out .= "\n\n### [" . $target . '] ' . $title . "\n" . ($text !== '' ? $text : '(empty)');
    }
    return $out . "\n\n### [script] Sample conversation (JSON)\n" . ($state['script'] !== '' ? $state['script'] : '(empty — no sample conversation yet)');
}

/** The plan of the run, as the field and review calls read it. */
function assist_plan_section(string $request, string $reply, array $steps): string
{
    $lines = [];
    foreach ($steps as $i => $s) {
        $lines[] = ($i + 1) . '. [' . $s['target'] . '] ' . $s['title'] . ' — ' . $s['task'];
    }
    return "\n\n## The researcher's request\n" . $request .
        "\n\n## The plan for this request\n" . ($reply !== '' ? $reply . "\n" : '') . implode("\n", $lines);
}

/** How replies to the researcher name things (plan reply, notes, review). */
const ASSIST_REPLY_RULES = "\nIn every text the researcher reads (reply, title, note, changes), name the boxes and the AI actors as the page does (\"Situation\", \"Green's box\", \"the sample conversation\"), never by an id such as bot1 or actor:bot2 (not even in brackets), and say \"conductor\", never \"director\". Write a time as the page shows it, minutes:seconds (1:20 for 80 seconds, never 0:80).";

/** Edit, do not reinvent: the researcher wrote or approved what is there. */
const ASSIST_KEEP_RULES = "\n\n## Keep what is there" .
    "\n- The researcher wrote or approved the current texts and the current sample conversation. Treat them as the base and make the SMALLEST change that does the job." .
    "\n- Keep the wording, the structure, the order, the headings and the list style. Change only the sentences (or messages) the task is about, and add new ones where they fit best." .
    "\n- Never rephrase, shorten, reorder or \"improve\" anything the task does not mention, even if you would write it differently." .
    "\n- Write a box or the sample conversation from scratch only if it is empty, or if the researcher explicitly asks for a rewrite or a completely new version.";

const ASSIST_WRITING_RULES = "\n\n## How to write box texts" .
    "\n- Write instructions for the conductor: clear, concrete sentences or short lists, no headings that only repeat the box title, no markdown tables." .
    "\n- Keep the language a box already uses (English for an empty box), whatever language the AI actors write in." .
    "\n- Put every rule into the one box where it belongs and never repeat a rule in two boxes; the boxes are read as one prompt." .
    "\n- Name the AI actors by their names, and the participant as \"the participant\".";

// ---------------------------------------------------------------------------
// The three system prompts
// ---------------------------------------------------------------------------

/** The ids a new AI actor would get, in order (the chat allows TEAM_MAX AI actors). */
function assist_free_actor_ids(array $members): array
{
    $used = array_map(fn($m) => $m['id'], $members);
    $free = [];
    for ($n = 1; $n <= TEAM_MAX && count($used) + count($free) < TEAM_MAX; $n++) {
        if (!in_array('bot' . $n, $used, true)) {
            $free[] = 'bot' . $n;
        }
    }
    return $free;
}

/** The plan rules for new AI actors: their ids, names and colors. */
function assist_new_actor_rules(array $members): string
{
    $free = assist_free_actor_ids($members);
    if (!$free) {
        return "\n- The chat already has the maximum of " . TEAM_MAX . " AI actors, so no AI actor can be added; say so in the reply when the researcher asks for one.";
    }
    $usedColors = array_map(fn($m) => strtolower((string)($m['color'] ?? '')), $members);
    $colors = array_values(array_filter(TEAM_PALETTE, fn($p) => !in_array(strtolower($p[1]), $usedColors, true)));
    return "\n- To ADD AI actors (only when the researcher asks for new AI actors, at most " . count($free) . " more), add \"new_actors\" to the plan, before \"steps\":" .
        " \"new_actors\":[{\"name\":\"a first name, unique, at most 24 characters\",\"color\":\"#rrggbb\"}]" .
        ($colors ? " (free colors: " . implode(', ', array_map(fn($p) => $p[1] . ' ' . strtolower($p[0]), array_slice($colors, 0, 5))) . ")" : '') . "." .
        " The new AI actors get the ids " . implode(', ', array_slice($free, 0, 3)) . " in this order. The software adds them to the team before the steps run, so plan a step for each new actor's own box (target actor:" . $free[0] . " for the first one)," .
        " and a step for the sample conversation when the new AI actor should write there (the sender is the new id). Mention a new AI actor in other boxes only where the other boxes really need it.";
}

/** assist_plan: answer, or plan which boxes change. The chat history follows as messages. */
function assist_system_prompt_plan(array $state, array $members, string $humanName, string $context, string $participantInfo): string
{
    return assist_study_brief($members, $humanName) .
        assist_background_section($context, $participantInfo) .
        assist_state_section($state, $members) .
        "\n\n## Your job now: answer or plan" .
        "\nRead the researcher's latest message (earlier messages are the history of this chat) and choose exactly one case:" .
        "\n1. A QUESTION, a review without changes (\"check\", \"what is missing\"), or a request too unclear to act on → respond with ONLY:" .
        "\n   {\"answer\":\"a short answer, a few sentences at most\"}" .
        "\n2. A request for CHANGES → respond with ONLY a plan, no markdown fences:" .
        "\n   {\"reply\":\"one short sentence to the researcher saying what you will do\",\"steps\":[{\"target\":\"situation\",\"title\":\"3 to 6 words\",\"task\":\"what exactly to change in this box and why, in 1 to 3 sentences\"}, …]}" .
        "\nRules for the plan:" .
        "\n- Think about the study as a whole first: a change often touches several boxes (a new personality for an AI actor changes that actor's box and maybe the sample conversation), but most requests need only one to three boxes." .
        "\n- Include ONLY boxes that really need a change. Leaving a box out is right whenever the box already fits; never add a step just to confirm a box." .
        "\n- One step per box at most, with the target written exactly as in the list of boxes. Every step is carried out by a colleague who sees all boxes but edits only that one box, so make each task specific." .
        "\n- Each task asks for the smallest change that does the job (\"add a sentence about …\", \"change the rule on … to …\") and says that the rest of the box stays as it is. Plan a rewrite of a whole box only if the box is empty or the researcher asks for a rewrite." .
        assist_new_actor_rules($members) .
        "\n- Order: the boxes of the conductor first, the sample conversation (script) last, so the conversation follows the final instructions." .
        "\n- At most 8 steps." .
        "\n- Something you cannot change is not a step: mention in the reply where the researcher can change it." .
        ASSIST_REPLY_RULES;
}

/** assist_field: the complete new content of one box, or a skip. */
function assist_system_prompt_field(array $state, array $members, string $humanName, string $context, string $participantInfo,
    string $request, string $planReply, array $steps, array $step): string
{
    $targets = assist_targets($members);
    [$title, $purpose] = $targets[$step['target']];
    $isScript = $step['target'] === 'script';
    $job = "\n\n## Your job now: edit ONE box" .
        "\nBox: [" . $step['target'] . '] ' . $title . ' — ' . $purpose . '.' .
        "\nTask from the plan: " . $step['task'] .
        "\nOnly this box changes in this step. The other boxes are context; boxes that come earlier in the plan already contain their changes." .
        " Keep content that belongs in another box out of this box.";
    if ($isScript) {
        $job .= "\n\n## Sample conversation format\n" . script_format_spec($members, $humanName) .
            "\n\n## How to answer" .
            "\nRespond with ONLY JSON, no markdown fences, in one of two shapes:" .
            "\n1. The complete new conversation, every message (never an excerpt), with \"segments\" first when the conversation is divided, and a note last:" .
            "\n   {\"segments\":[…],\"messages\":[{\"id\":1,\"sender\":\"bot1\",\"content\":\"...\",\"typing_pct\":40,\"send_at\":5,\"is_empty\":false}, …],\"note\":\"what you changed, at most 12 words\"}" .
            "\n   Keep every message the task does not touch exactly as it is (wording, sender, timing, order), change or insert only the messages the task is about, and keep the conversation consistent with the boxes (they are final now)." .
            "\n2. The conversation needs no change after all → {\"skip\":\"why, at most 12 words\"}";
    } else {
        if (str_starts_with($step['target'], 'actor:')) {
            $name = substr($title, strlen('AI actors: only '));
            $job .= "\nThe software puts the line \"Instructions for " . $name . " only:\" above this text," .
                " so start directly with the content: no name, no id and no heading at the start.";
        }
        $job .= ASSIST_WRITING_RULES .
            "\n\n## How to answer" .
            "\nRespond with ONLY JSON, no markdown fences, in one of two shapes:" .
            "\n1. {\"text\":\"the COMPLETE new text of this box\",\"note\":\"what you changed, at most 12 words\"}" .
            "\n   Always the whole box, never a fragment or a diff: keep every sentence the task does not touch word for word. An empty string empties the box." .
            "\n2. The box needs no change after all → {\"skip\":\"why, at most 12 words\"}" .
            "\nThese answer rules are for you only: never copy them or the JSON format into the box.";
    }
    $job .= ASSIST_KEEP_RULES . ASSIST_REPLY_RULES;
    return assist_study_brief($members, $humanName) .
        assist_background_section($context, $participantInfo) .
        assist_state_section($state, $members) .
        assist_plan_section($request, $planReply, $steps) .
        $job;
}

/**
 * assist_review: check the result. $results: [['target','title','status','note','diff'=>[lines]], …].
 * $final: the review after the fixes, which only summarizes.
 */
function assist_system_prompt_review(array $state, array $members, string $humanName, string $context, string $participantInfo,
    string $request, string $planReply, array $steps, array $results, bool $final): string
{
    $done = [];
    foreach ($results as $r) {
        $line = '- [' . $r['target'] . '] ' . $r['title'] . ': ' . $r['status'] . ($r['note'] !== '' ? ' — ' . $r['note'] : '');
        if ($r['diff']) {
            $line .= "\n  " . implode("\n  ", $r['diff']);
        }
        $done[] = $line;
    }
    $answer = $final
        ? "\nThis is the final check after the fixes: respond with ONLY {\"ok\":true,\"reply\":\"1 or 2 sentences to the researcher: what was done, and anything the researcher should still check or do\",\"changes\":[\"3 to 6 short bullets naming the concrete changes of the whole run, only what changed, never what stayed the same\"]}"
        : "\nRespond with ONLY JSON, no markdown fences, in one of two shapes:" .
          "\n1. Everything is fine → {\"ok\":true,\"reply\":\"1 or 2 sentences to the researcher: what was done\",\"changes\":[\"1 to 6 short bullets naming the concrete changes, only what changed, never what stayed the same\"]}" .
          "\n2. A real problem needs fixing → {\"ok\":false,\"reply\":\"one sentence on what you will fix\",\"fixes\":[{\"target\":\"…\",\"title\":\"3 to 6 words\",\"task\":\"what exactly to fix in this box\"}]}" .
          "\n   At most 4 fixes, one per box, only for real problems (the request is not fully done, two boxes contradict each other, a rule sits in the wrong box or appears twice, the sample conversation does not match the boxes, or a step rewrote or dropped text that the request did not touch: then the fix puts the removed lines of the diff back). Never for wording preferences.";
    return assist_study_brief($members, $humanName) .
        assist_background_section($context, $participantInfo) .
        assist_state_section($state, $members, 'Content of the boxes after the run') .
        assist_plan_section($request, $planReply, $steps) .
        "\n\n## What each step did (diffs: + added, - removed)\n" . ($done ? implode("\n", $done) : '(no step ran)') .
        "\n\n## Your job now: review" .
        "\nCheck whether the request is fully done and whether the boxes and the sample conversation fit together." .
        $answer . ASSIST_REPLY_RULES;
}

// ---------------------------------------------------------------------------
// Reading the answers
// ---------------------------------------------------------------------------

/** One step from the model: ['target','title','task'] or null when the target is unknown. */
function assist_clean_step($s, array $targets): ?array
{
    if (!is_array($s)) {
        return null;
    }
    $target = trim((string)($s['target'] ?? ''));
    if (preg_match('/^(bot(10|[1-9]))$/', $target)) {
        $target = 'actor:' . $target; // "bot2" for "actor:bot2"
    }
    if (!isset($targets[$target])) {
        return null;
    }
    $task = trim(assist_clamp($s['task'] ?? '', 800));
    $title = trim(assist_clamp($s['title'] ?? '', 60));
    return ['target' => $target, 'title' => $title !== '' ? $title : $targets[$target][0], 'task' => $task];
}

/**
 * A list of steps: known targets only, one step per target (tasks of a repeated
 * target are joined), the sample conversation last, at most $max steps.
 */
function assist_clean_steps($list, array $targets, int $max): array
{
    $byTarget = [];
    foreach ((array)$list as $s) {
        $step = assist_clean_step($s, $targets);
        if ($step === null) {
            continue;
        }
        if (isset($byTarget[$step['target']])) {
            $byTarget[$step['target']]['task'] = trim($byTarget[$step['target']]['task'] . ' ' . $step['task']);
        } else {
            $byTarget[$step['target']] = $step;
        }
    }
    $script = $byTarget['script'] ?? null;
    unset($byTarget['script']);
    $steps = array_values($byTarget);
    if ($script) {
        $steps[] = $script;
    }
    return array_slice($steps, 0, $max);
}

/**
 * The new AI actors of a plan: unique names, valid colors, and the next free
 * ids in order → [['id', 'name', 'color'], …]. $targets gains their boxes.
 */
function assist_clean_new_actors($list, array $everyone, array &$targets): array
{
    $free  = assist_free_actor_ids(team_bots($everyone));
    $names = array_map(fn($m) => mb_strtolower(trim((string)$m['name']), 'UTF-8'), $everyone); // the participant's name too
    $names[] = 'you'; // the participant sees their own messages as "You"
    $usedColors = array_map(fn($m) => strtolower((string)($m['color'] ?? '')), $everyone);
    $out = [];
    foreach (array_slice(array_values((array)$list), 0, count($free)) as $a) {
        if (!is_array($a)) {
            continue;
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string)($a['name'] ?? '')) ?? '');
        $name = mb_substr($name, 0, TEAM_NAME_MAX, 'UTF-8');
        $key  = mb_strtolower($name, 'UTF-8');
        if ($name === '' || in_array($key, $names, true)) {
            continue;
        }
        $color = strtolower(trim((string)($a['color'] ?? '')));
        if (!preg_match('/^#[0-9a-f]{6}$/', $color) || in_array($color, $usedColors, true)) {
            $color = '';
            foreach (TEAM_PALETTE as [, $c]) {
                if (!in_array(strtolower($c), $usedColors, true)) {
                    $color = strtolower($c);
                    break;
                }
            }
            $color = $color ?: TEAM_PALETTE[count($out) % count(TEAM_PALETTE)][1];
        }
        $id = $free[count($out)];
        $out[] = ['id' => $id, 'name' => $name, 'color' => $color];
        $names[] = $key;
        $usedColors[] = $color;
        $targets['actor:' . $id] = ['AI actors: only ' . $name, 'what makes ' . $name . ' unique'];
    }
    return $out;
}

/**
 * assist_plan → ['type' => 'answer', 'answer'] | ['type' => 'plan', 'reply', 'new_actors', 'steps'] | ['type' => 'error'].
 * Steps may name the boxes of the new AI actors (their ids follow the current team).
 */
function assist_parse_plan(string $content, array $targets, array $everyone = []): array
{
    $parsed = decode_model_json($content);
    if (!is_array($parsed)) {
        $t = trim($content);
        return $t !== '' && !str_starts_with($t, '{') && !str_starts_with($t, '```')
            ? ['type' => 'answer', 'answer' => assist_clamp($t, 3000)] // plain prose
            : ['type' => 'error'];
    }
    $reply = trim(assist_clamp($parsed['reply'] ?? '', 600));
    $newActors = assist_clean_new_actors($parsed['new_actors'] ?? [], $everyone, $targets);
    $steps = assist_clean_steps($parsed['steps'] ?? [], $targets, 8);
    if ($steps || $newActors) {
        return ['type' => 'plan', 'reply' => $reply, 'new_actors' => $newActors, 'steps' => $steps];
    }
    $answer = trim(assist_clamp($parsed['answer'] ?? '', 3000));
    if ($answer !== '' || $reply !== '') {
        return ['type' => 'answer', 'answer' => $answer !== '' ? $answer : $reply];
    }
    return ['type' => 'error'];
}

/** assist_field for a text box → ['status' => done|skip|error, 'text', 'note'] */
function assist_parse_text_field(string $content): array
{
    $parsed = decode_model_json($content);
    if (is_array($parsed)) {
        if (isset($parsed['text']) && is_string($parsed['text'])) {
            return ['status' => 'done', 'text' => director_section_clamp(trim($parsed['text'])), 'note' => trim(assist_clamp($parsed['note'] ?? '', 160))];
        }
        if (isset($parsed['skip'])) {
            return ['status' => 'skip', 'note' => trim(assist_clamp(is_string($parsed['skip']) ? $parsed['skip'] : '', 160))];
        }
    }
    return ['status' => 'error', 'note' => ''];
}

/** assist_field for the sample conversation → ['status' => done|skip|error, 'script', 'message_count', 'note'] */
function assist_parse_script_field(string $content, string $oldScript, array $members): array
{
    $parsed = decode_model_json($content);
    if (!is_array($parsed)) {
        return ['status' => 'error', 'note' => ''];
    }
    if (isset($parsed['skip']) && !isset($parsed['messages'])) {
        return ['status' => 'skip', 'note' => trim(assist_clamp(is_string($parsed['skip']) ? $parsed['skip'] : '', 160))];
    }
    $clean = normalize_script_messages($parsed['messages'] ?? (array_is_list($parsed) ? $parsed : null), $members);
    if ($clean === null) {
        return ['status' => 'error', 'note' => ''];
    }
    $segments = assist_script_segments(array_is_list($parsed) ? [] : $parsed, $oldScript);
    $clean = assist_assign_segments($clean, $segments);
    return [
        'status'        => 'done',
        'script'        => $segments ? ['segments' => $segments, 'messages' => $clean] : ['messages' => $clean],
        'message_count' => count(array_filter($clean, fn($m) => !$m['is_empty'])),
        'note'          => trim(assist_clamp($parsed['note'] ?? '', 160)),
    ];
}

/** assist_review → ['ok' => bool, 'reply', 'changes' => […], 'fixes' => [steps]] or null when unusable. */
function assist_parse_review(string $content, array $targets, bool $final): ?array
{
    $parsed = decode_model_json($content);
    if (!is_array($parsed)) {
        return null;
    }
    $changes = [];
    foreach (array_slice((array)($parsed['changes'] ?? []), 0, 8) as $c) {
        if (is_string($c) && trim($c) !== '') {
            $changes[] = assist_clamp(trim($c), 200);
        }
    }
    $fixes = $final ? [] : assist_clean_steps($parsed['fixes'] ?? [], $targets, 4);
    $ok = $final || empty($parsed['ok']) === false || !$fixes;
    return ['ok' => $ok, 'reply' => trim(assist_clamp($parsed['reply'] ?? '', 800)), 'changes' => $changes, 'fixes' => $ok ? [] : $fixes];
}

// ---------------------------------------------------------------------------
// Diffs (keep call 2 small and grounded in what actually changed)
// ---------------------------------------------------------------------------

/** Compact mechanical diff between the old script (raw JSON) and the new messages (and segments). */
function script_diff_lines(string $oldRaw, array $new, array $newSegments = []): array
{
    $old = [];
    $p = json_decode($oldRaw, true);
    if (is_array($p)) {
        $old = is_array($p['messages'] ?? null) ? $p['messages'] : (array_is_list($p) ? $p : []);
    }
    $segmentText = function (array $segments): string {
        return $segments ? implode(', ', array_map(fn($s) => segments_clock($s['start']) . ($s['prompt'] !== '' ? ' "' . assist_clamp($s['prompt'], 60) . '"' : ''), $segments)) : 'none';
    };
    $oldSegments = segments_of_script($p);
    $real = fn(array $list) => array_values(array_filter($list, fn($m) => is_array($m) && empty($m['is_empty'])));
    $lastAt = function (array $list): int {
        $t = 0;
        foreach ($list as $m) {
            if (is_array($m)) {
                $t = max($t, (int)($m['send_at'] ?? 0), (int)($m['typing_end'] ?? 0));
            }
        }
        return $t;
    };
    $oldReal = $real($old);
    $newReal = $real($new);

    $lines = [];
    $lines[] = 'Message count: ' . count($oldReal) . ' → ' . count($newReal);
    $lines[] = 'Ghost-typing events: ' . (count($old) - count($oldReal)) . ' → ' . (count($new) - count($newReal));
    $lines[] = 'Total duration: ' . $lastAt($old) . 's → ' . $lastAt($new) . 's';
    if ($oldSegments !== $newSegments) {
        $lines[] = 'Segments: ' . $segmentText($oldSegments) . ' → ' . $segmentText($newSegments);
    }

    $n = min(count($oldReal), count($newReal));
    $textChanged = $timingChanged = $senderChanged = 0;
    for ($i = 0; $i < $n; $i++) {
        $o = $oldReal[$i];
        $m = $newReal[$i];
        if ((string)($o['content'] ?? '') !== (string)($m['content'] ?? '')) {
            $textChanged++;
            if (count($lines) < 32) {
                $lines[] = 'msg ' . ($i + 1) . ': "' . assist_clamp((string)($o['content'] ?? ''), 70) . '" → "' . assist_clamp((string)($m['content'] ?? ''), 70) . '"';
            }
        }
        if ((int)($o['send_at'] ?? 0) !== (int)($m['send_at'] ?? 0)) {
            $timingChanged++;
        }
        if (($o['sender'] ?? '') !== ($m['sender'] ?? '')) {
            $senderChanged++;
        }
    }
    $lines[] = 'Texts changed: ' . $textChanged . ' · timings changed: ' . $timingChanged . ' · senders changed: ' . $senderChanged;
    return $lines;
}

/** Compact line diff between two instruction texts. */
function instructions_diff_lines(string $oldText, string $newText): array
{
    $split = fn(string $t) => array_values(array_filter(array_map('trim', preg_split('/\r\n?|\n/', $t)), fn($l) => $l !== ''));
    $old = $split($oldText);
    $new = $split($newText);
    $removed = array_slice(array_values(array_diff($old, $new)), 0, 30);
    $added   = array_slice(array_values(array_diff($new, $old)), 0, 30);

    $lines = ['Length: ' . count($old) . ' lines → ' . count($new) . ' lines'];
    foreach ($added as $l) {
        $lines[] = '+ ' . assist_clamp($l, 160);
    }
    foreach ($removed as $l) {
        $lines[] = '- ' . assist_clamp($l, 160);
    }
    if (count($lines) === 1) {
        $lines[] = '(no line-level differences — possibly whitespace or ordering changes only)';
    }
    return $lines;
}

/**
 * Text for the researcher with the ids replaced by names: "actor:bot4", "[bot4]"
 * and "Lena (bot4)" become "Lena". Models sometimes keep the ids despite the prompt.
 * $everyone: the team, $extra: AI actors the plan adds ([['id','name'], …]).
 */
function assist_readable(string $text, array $everyone, array $extra = []): string
{
    $names = [];
    foreach (array_merge($everyone, $extra) as $m) {
        $names[(string)($m['id'] ?? '')] = trim((string)($m['name'] ?? ''));
    }
    $id = '(?:actor:)?(bot(?:10|[1-9]))\b';
    $text = preg_replace('/\s*[\[(]\s*' . $id . '\s*[\])]/', '', $text);       // "Lena (bot4)", "Lena's [actor:bot4]"
    $text = preg_replace('/\b' . $id . '\s*\(([^)]{1,40})\)/', '$2', $text);     // "actor:bot4 (Lena)"
    return preg_replace_callback('/\b' . $id . '/', fn($m) => ($names[$m[1]] ?? '') !== '' ? $names[$m[1]] : $m[0], $text);
}

// ---------------------------------------------------------------------------
// Running one call of a run
// ---------------------------------------------------------------------------

/**
 * Run one call of the assistant and stream it to the browser. Never returns.
 *
 * $job: mode (assist_plan | assist_field | assist_review), cfg, system,
 * messages (the chat after the system prompt), project_id, targets, and per
 * mode: target and old (assist_field: the box text or script JSON before the
 * step), members (the AI actors, for the script), final (assist_review).
 */
function assist_job_run(array $job): void
{
    $cfg  = $job['cfg'];
    $mode = (string)$job['mode'];
    LlmSse::begin();

    $messages = array_merge([['role' => 'system', 'content' => $job['system']]], $job['messages']);
    if ($cfg['provider'] === 'custom') {
        // Gateways expose no reasoning stream: buffered call, connection kept warm.
        $res = llm_call(llm_build_request($cfg, $messages, ['stream' => false]), LLM_ASSIST_S, [LlmSse::class, 'keepalive']);
        if ($res['ok']) {
            LlmSse::send(['d' => $res['content']]); // the box still fills live in the browser
        }
    } else {
        $res = llm_stream(llm_build_request($cfg, $messages, ['stream' => true, 'reasoning_summary' => true]), [
            'text'      => fn(string $d) => LlmSse::send(['d' => $d]),
            'reasoning' => fn(string $r) => LlmSse::send(['r' => $r]),
        ], ['timeout' => LLM_ASSIST_S]); // a whole sample conversation takes minutes to write
    }
    $usage = ['total' => $res['usage']['input'] + $res['usage']['output']] + $res['usage'];

    $finish = function (array $final) use ($res, $cfg, $mode, $job, $usage): void {
        LlmSse::send(['final' => $final + ['usage' => $usage, 'provider' => $cfg['provider'], 'model' => $cfg['model'], 'effort' => $cfg['effort']]]);
        LlmSse::done();
        if (($job['project_id'] ?? '') !== '') {
            director_log_event((string)$job['project_id'], '', [
                'mode' => $mode, 'provider' => $cfg['provider'], 'model' => $cfg['model'], 'effort' => $cfg['effort'],
                'status' => $res['ok'] ? 'ok' : (LlmSse::aborted() ? 'aborted' : 'error'),
                'first_token_ms' => $res['first_token_ms'] ?? null, 'duration_ms' => $res['duration_ms'],
                'output_chars' => strlen($res['content']), 'output' => $mode === 'assist_field' ? '' : $res['content'],
                'error' => !$res['ok'] && LlmSse::aborted() ? LLM_CANCELLED : $res['error'],
                'usage' => $res['usage_reported'] ? $res['usage'] : null,
            ]);
        }
        exit;
    };
    if (!$res['ok']) {
        $finish(['ok' => false, 'error' => $res['error']]);
    }
    $content  = $res['content'];
    $targets  = $job['targets'];
    $everyone = $job['everyone'] ?? [];

    if ($mode === 'assist_plan') {
        $plan = assist_parse_plan($content, $targets, $everyone);
        if ($plan['type'] === 'error') {
            $finish(['ok' => false, 'error' => 'The model did not return a usable plan, so nothing was changed. Please rephrase and try again.']);
        }
        if ($plan['type'] === 'plan') {
            $plan['reply'] = assist_readable($plan['reply'], $everyone, $plan['new_actors']);
            foreach ($plan['steps'] as &$st) {
                $st['title'] = assist_readable($st['title'], $everyone, $plan['new_actors']);
            }
            unset($st);
        } else {
            $plan['answer'] = assist_readable($plan['answer'], $everyone);
        }
        $finish(['ok' => true] + $plan);
    }

    if ($mode === 'assist_review') {
        $review = assist_parse_review($content, $targets, !empty($job['final']));
        if ($review === null) {
            // The edits are applied already; a failed review only loses the summary.
            $finish(['ok' => true, 'review_ok' => true, 'reply' => 'The changes were applied, but the review could not be read. Please look over the boxes. Undo restores the previous version.', 'changes' => [], 'fixes' => [], 'issue' => true]);
        }
        $finish(['ok' => true, 'review_ok' => $review['ok'], 'reply' => assist_readable($review['reply'], $everyone),
            'changes' => array_map(fn($c) => assist_readable($c, $everyone), $review['changes']), 'fixes' => $review['fixes'], 'issue' => false]);
    }

    // assist_field
    $target = (string)$job['target'];
    $old    = (string)$job['old'];
    if ($target === 'script') {
        $r = assist_parse_script_field($content, $old, $job['members']);
        if ($r['status'] !== 'done') {
            $finish(['ok' => true, 'target' => $target, 'status' => $r['status'], 'note' => $r['note'] ?: ($r['status'] === 'error' ? 'The model did not return a usable conversation.' : ''), 'diff' => []]);
        }
        $finish(['ok' => true, 'target' => $target, 'status' => 'done', 'script' => $r['script'], 'message_count' => $r['message_count'],
            'note' => assist_readable($r['note'], $everyone), 'diff' => script_diff_lines($old, $r['script']['messages'], $r['script']['segments'] ?? [])]);
    }
    $r = assist_parse_text_field($content);
    if ($r['status'] === 'done' && trim($r['text']) === trim($old)) {
        $r = ['status' => 'skip', 'note' => 'No change was needed'];
    }
    if ($r['status'] !== 'done') {
        $finish(['ok' => true, 'target' => $target, 'status' => $r['status'], 'note' => $r['note'] ?: ($r['status'] === 'error' ? 'The model did not return a usable text.' : ''), 'diff' => []]);
    }
    $finish(['ok' => true, 'target' => $target, 'status' => 'done', 'text' => $r['text'], 'note' => assist_readable($r['note'], $everyone), 'diff' => instructions_diff_lines($old, $r['text'])]);
}
