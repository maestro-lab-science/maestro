<?php
/**
 * api/save_project.php — store a project's settings (the Save of settings.php).
 *
 * POST JSON with every field of the settings page; one request writes all
 * settings at once. Validation happens here: the team (includes/team.php),
 * the sample conversation and its segments (includes/segments.php), the
 * formatted instructions above the chat (includes/richtext.php), and the
 * AI model block (provider, model, request format, effort, endpoint; the
 * lists come from includes/llm.php). An empty api_key keeps the stored key,
 * clear_api_key removes it, and the key is never echoed back.
 *
 * Access: the unlocked settings session, or the project password as a
 * fallback when the PHP session expired while the page stayed open.
 *
 * Draft copy of an example study (settings.php?example=ID): the first Save
 * sends { draft_from: example ID, password } with a project_id that does not
 * exist yet. The project is then created from the example (all settings
 * except its API key, name "Copy of …", the given password), unlocked in this
 * session, added to a signed-in account, and the settings of the page are
 * stored on it. The reply carries created: true and the password.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/llm.php';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/../includes/director_sections.php';
require_once __DIR__ . '/../includes/segments.php';
require_once __DIR__ . '/../includes/richtext.php';
require_once __DIR__ . '/../includes/examples.php';

header('X-Content-Type-Options: nosniff');

/**
 * A text for a TEXT column: at most $chars characters and 65,535 bytes (the
 * column's size; emoji take four bytes each), never cut inside a character.
 */
function save_text_fit(string $text, int $chars): string
{
    if (!function_exists('mb_substr')) {
        return substr($text, 0, min($chars, 65535));
    }
    return mb_strcut(mb_substr($text, 0, $chars, 'UTF-8'), 0, 65535, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST required', 405);
}

$payload    = read_json_body();
$project_id = sanitize_project_id((string)($payload['project_id'] ?? ''));

if ($project_id === '') {
    json_fail('Invalid study ID');
}

try {
    $project = load_project($project_id);
    $created = false;
    $draft_from = sanitize_project_id((string)($payload['draft_from'] ?? ''));
    if ($draft_from !== '' && !$project) {
        // The first Save of a draft copy: create the project from the example.
        $source = example_is_project($draft_from) ? load_project($draft_from) : null;
        if (!$source) {
            json_fail('Unknown example study', 404);
        }
        $draftPw = trim((string)($payload['password'] ?? ''));
        if (strlen($draftPw) < 8 || strlen($draftPw) > 255) {
            json_fail('The copy needs a password of 8 to 255 characters.');
        }
        $project = example_store_copy($source, $project_id, $draftPw);
        $created = true;
        session_grant($project_id);
        // Until the copy is saved once without errors, every Save of this page
        // answers as the first one (see below), so the page learns the credentials.
        $_SESSION['draft_unconfirmed'][$project_id] = true;
        require_once __DIR__ . '/../includes/account.php';
        $user = account_boot();
        if ($user) {
            account_link_project((int)$user['id'], $project_id, $draftPw);
        }
    }
    if (!$project) {
        json_fail('Unknown study', 404);
    }
    // A draft whose first Save created the study but then failed validation:
    // this retry is still the first successful Save for the page.
    if (!$created && $draft_from !== '') {
        session_boot();
        if (!empty($_SESSION['draft_unconfirmed'][$project_id])) {
            $draftPw = trim((string)($payload['password'] ?? ''));
            $created = password_verify($draftPw, (string)$project['password_hash']);
        }
    }

    // Session unlock (set by api/auth.php), with password as a fallback so the
    // page keeps working if the PHP session expired while editing.
    $authorized = session_authorized($project_id);
    if (!$authorized) {
        $password = trim((string)($payload['password'] ?? ''));
        if (password_attempt($password, (string)$project['password_hash'], 'project:' . $project_id)) {
            session_grant($project_id);
            $authorized = true;
        }
    }
    if (!$authorized) {
        json_fail('Session expired. Please reopen the study from the Studies page.', 403);
    }

    $name = trim((string)($payload['name'] ?? ''));
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);

    $participant_instructions = save_text_fit((string)($payload['participant_instructions'] ?? ''), 20000);
    $director_instructions    = (string)($payload['director_instructions'] ?? '');
    $conversation_script      = trim((string)($payload['conversation_script'] ?? ''));

    // Study context: background for the settings-page assistants only (never
    // sent to the director or shown to participants). Absent → keep what is
    // stored (older clients).
    $study_context = array_key_exists('study_context', $payload)
        ? trim((string)$payload['study_context'])
        : (string)($project['study_context'] ?? '');
    $study_context = save_text_fit($study_context, 20000);

    // ---- Team ----------------------------------------------------------------
    // Everyone in the chat: the AI teammates and the participant ("human").
    // Absent → keep what is stored (older clients); present → validated, and the
    // script may only use teammates that exist in it.
    $everyone = team_all($project);
    if (array_key_exists('team', $payload)) {
        $everyone = team_normalize_all($payload['team']);
        if (!is_array($everyone)) {
            json_fail($everyone);
        }
    }
    $members   = team_bots($everyone);
    $team_json = json_encode($everyone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // ---- Conductor instructions ------------------------------------------------
    // The settings page sends the sections; the stored prompt text is assembled
    // from them here (see includes/director_sections.php). Without sections
    // (older clients) the plain text is stored as sent.
    $director_sections_json = null;
    $sections = array_key_exists('director_sections', $payload) ? director_sections_normalize($payload['director_sections']) : null;
    if ($sections !== null) {
        $director_instructions  = director_assemble($sections, $everyone);
        $director_sections_json = director_sections_encode($sections);
    }

    if ($conversation_script !== '') {
        $parsed = json_decode($conversation_script, true);
        if (!is_array($parsed)) {
            json_fail('The sample conversation is not valid JSON.');
        }
        $scriptMessages = is_array($parsed['messages'] ?? null) ? $parsed['messages'] : (array_is_list($parsed) ? $parsed : []);
        $unknown = [];
        foreach ($scriptMessages as $m) {
            $sender = is_array($m) ? (string)($m['sender'] ?? '') : '';
            if ($sender !== '' && team_sender_id($members, $sender) === null) {
                $unknown[$sender] = true;
            }
        }
        if ($unknown) {
            json_fail('The sample conversation has lines from ' . implode(', ', array_keys($unknown))
                . (count($unknown) === 1 ? ', who is not an AI teammate' : ', who are not AI teammates')
                . '. The participant is never scripted, so reassign those lines to a teammate or delete them.');
        }
        // Segments (includes/segments.php): start times in order, short prompts.
        if (is_array($parsed) && array_key_exists('segments', $parsed)) {
            $segments = segments_normalize($parsed['segments']);
            if (!is_array($segments)) {
                json_fail($segments);
            }
        }
    }

    $show_ready_modal = (int)!empty($payload['show_ready_modal']);
    $show_instructions = (int)!empty($payload['show_instructions']);
    $ready_wait_seconds = max(0, min(600, (int)($payload['ready_wait_seconds'] ?? 22)));
    $timer_enabled = (int)!empty($payload['timer_enabled']);
    $timer_duration_seconds = max(30, min(180 * 60, (int)($payload['timer_duration_seconds'] ?? 600)));

    // Instructions above the chat: formatted text from the editor in Step 1.
    // It is rebuilt here from the few kinds of formatting the editor offers
    // (includes/richtext.php), so whatever a request contains, only that is
    // stored. Text without anything to read is stored as ''. Absent → keep
    // what is stored (older clients).
    $show_chat_instructions = array_key_exists('show_chat_instructions', $payload)
        ? (int)!empty($payload['show_chat_instructions'])
        : (int)($project['show_chat_instructions'] ?? 0);
    $chat_instructions = (string)($project['chat_instructions'] ?? '');
    if (array_key_exists('chat_instructions', $payload)) {
        $raw = is_string($payload['chat_instructions']) ? $payload['chat_instructions'] : '';
        $tooLong = 'The instructions above the chat are too long. Please shorten them to at most '
            . number_format(RICHTEXT_MAX_CHARS) . ' characters, including the formatting.';
        if (strlen($raw) > RICHTEXT_MAX_INPUT) {
            json_fail($tooLong);
        }
        $chat_instructions = richtext_render($raw);
        if (richtext_length($chat_instructions) > RICHTEXT_MAX_CHARS) {
            json_fail($tooLong);
        }
    }

    // ---- AI model block ------------------------------------------------------
    // The accepted values are the ones includes/llm.php understands.
    $api_provider = (string)($payload['api_provider'] ?? 'openai');
    if (!in_array($api_provider, LLM_PROVIDERS, true)) {
        json_fail('Invalid provider');
    }
    $model = trim((string)($payload['model'] ?? ''));
    $model = substr($model, 0, 64);

    // How requests are shaped: automatic, chat completions, or the Responses API.
    $api_format = (string)($payload['api_format'] ?? 'auto');
    if (!in_array($api_format, LLM_FORMATS, true)) {
        $api_format = 'auto';
    }

    // The live director's thinking effort (speed — its thinking time is dead air
    // in the chat). The settings-page assistants choose theirs per request in
    // their panel (effort_override); the reasoning_effort column is only the
    // server-side fallback and is not edited here.
    $director_effort = (string)($payload['director_effort'] ?? 'minimal');
    if (!in_array($director_effort, LLM_EFFORTS, true)) {
        $director_effort = 'minimal';
    }

    $api_endpoint = trim((string)($payload['api_endpoint'] ?? ''));
    $api_endpoint = substr($api_endpoint, 0, 255);
    if ($api_endpoint !== '' && !preg_match('#^https?://#i', $api_endpoint)) {
        json_fail('The endpoint URL must start with https:// (or http:// for local testing)');
    }

    // API key: never echoed back. Empty string = keep the stored key;
    // clear_api_key = remove it; any other value = replace it.
    $api_key = preg_replace('/\s+/', '', (string)($payload['api_key'] ?? ''));
    $api_key = substr($api_key, 0, 512);
    $clear_api_key = !empty($payload['clear_api_key']);

    $sql = 'UPDATE projects
               SET name = ?, participant_instructions = ?, show_instructions = ?,
                   director_instructions = ?, director_sections = ?, conversation_script = ?, study_context = ?, team_json = ?,
                   show_ready_modal = ?, ready_wait_seconds = ?, timer_enabled = ?, timer_duration_seconds = ?,
                   show_chat_instructions = ?, chat_instructions = ?,
                   api_provider = ?, model = ?, api_format = ?, director_effort = ?, api_endpoint = ?';
    $types = 'ssisssssiiiiissssss';
    $params = [
        $name, $participant_instructions, $show_instructions,
        $director_instructions, $director_sections_json, $conversation_script, $study_context, $team_json,
        $show_ready_modal, $ready_wait_seconds, $timer_enabled, $timer_duration_seconds,
        $show_chat_instructions, $chat_instructions,
        $api_provider, $model, $api_format, $director_effort, $api_endpoint,
    ];
    if ($clear_api_key) {
        $sql .= ", api_key = ''";
    } elseif ($api_key !== '') {
        $sql .= ', api_key = ?';
        $types .= 's';
        $params[] = $api_key;
    }
    $sql .= ' WHERE project_id = ? LIMIT 1';
    $types .= 's';
    $params[] = $project_id;

    $stmt = db()->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    $reply = ['ok' => true, 'project_id' => $project_id, 'has_api_key' => !$clear_api_key && ($api_key !== '' || trim((string)$project['api_key']) !== '')];
    if ($created) {
        $reply['created']  = true;
        $reply['name']     = $name;
        $reply['password'] = $draftPw;
        unset($_SESSION['draft_unconfirmed'][$project_id]);
    }
    json_out($reply);
} catch (mysqli_sql_exception $e) { // before RuntimeException: it is a subclass of it
    json_fail('Database error: ' . $e->getMessage(), 500);
} catch (RuntimeException $e) {
    json_fail($e->getMessage(), 500);
}
