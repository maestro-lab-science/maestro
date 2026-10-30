<?php
/**
 * aiTeam.php — the participant-facing team chat.
 *
 * This page is deliberately thin: it loads the study, validates that a
 * conversation script exists, renders the shell (header, optional instructions
 * above the chat, chat, composer, optional ready gate) and hands the
 * configuration to the browser runtime in assets/js/chat/ (ES modules, no build
 * step). All behavior lives there.
 *
 * Participants never receive API keys or the director instructions; the
 * browser only talks to api/director.php and api/save_message.php (and, when
 * RELAY_WS_URL is set, to the WebSocket relay with tokens director.php issued).
 *
 * The page is made to be embedded in a survey (an iframe in a Qualtrics
 * question, for example), and that must always stay possible: it allows
 * framing by any site (Content-Security-Policy: frame-ancestors *), it sets
 * no cookie and opens no PHP session, and the chat runtime uses no browser
 * storage, so the chat works inside a third-party frame even where such
 * cookies and storage are blocked. The two endpoints it talks to keep the
 * same rules; app/tests/embed.test.mjs checks them.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/team.php';
require_once __DIR__ . '/includes/segments.php';
require_once __DIR__ . '/includes/richtext.php';

header('X-Content-Type-Options: nosniff');
// Embedding in surveys is a core use case: every site may frame this page.
// Sent before anything else, so the notice pages below carry it too, and it
// takes precedence over an X-Frame-Options header a web server might add.
header('Content-Security-Policy: frame-ancestors *');

$project_id = request_project_id();
$project = null;
if ($project_id !== '') {
    try {
        $project = load_project($project_id);
    } catch (RuntimeException $e) {
        $project = null;
    }
}

/** Small full-page notice used for the two "cannot start" cases. */
function chat_notice(int $status, string $title, string $text): void
{
    http_response_code($status);
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Team Chat · <?= htmlspecialchars($title) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/chat.css') ?>">
  <?= icon_links() ?>
</head>
<body>
  <div class="notready">
    <div class="card">
      <h2 style="font-size:18px;"><?= htmlspecialchars($title) ?></h2>
      <p class="muted mt-1"><?= htmlspecialchars($text) ?></p>
    </div>
  </div>
</body>
</html>
    <?php
    exit;
}

if (!$project) {
    chat_notice(404, 'This chat link is not valid', 'The study could not be found. Please use the exact link you were given, or contact the study organizer.');
}

// A paused study takes no new participants (settings, Step 4). Test links
// (conversationID=TEST…) keep working, so researchers can try changes first.
$isTestLink = stripos((string)($_GET['conversationID'] ?? ''), 'TEST') === 0;
if ((int)($project['is_paused'] ?? 0) === 1 && !$isTestLink) {
    chat_notice(200, 'This study is not active right now', 'The research team has paused this study, so it cannot be started at the moment. Please try again later, or contact the research team if you were asked to take part now.');
}

// A chat needs a script to play. (The director alone cannot start a conversation.)
$script = json_decode((string)$project['conversation_script'], true);
$scriptMessages = is_array($script) ? ($script['messages'] ?? (array_is_list($script) ? $script : [])) : [];
if (!is_array($scriptMessages) || count($scriptMessages) === 0) {
    chat_notice(200, 'This chat is not ready yet', 'The study organizer has not added a sample conversation to this study. If you are the organizer, open the study settings and add a sample conversation in Step 3. The AI Assistant can draft the sample conversation.');
}
// Segments: the chat needs their start times only. The prompts are part of the
// study manipulation and stay on the server, like the director instructions.
if (is_array($script) && array_key_exists('segments', $script)) {
    $script['segments'] = array_map(fn($s) => ['start' => $s['start']], segments_of_script($script));
}

$show_ready_modal   = (int)$project['show_ready_modal'] === 1;
$ready_wait_seconds = max(0, (int)$project['ready_wait_seconds']);
$timer_enabled      = (int)$project['timer_enabled'] === 1;
$timer_seconds      = (int)$project['timer_duration_seconds'] > 0 ? (int)$project['timer_duration_seconds'] : 600;
$participant_instructions = ((int)$project['show_instructions']) ? (string)$project['participant_instructions'] : '';
// Instructions above the chat: formatted text, shown between the timer and
// the messages. With the option off or without any text this stays '', and
// the page is the plain chat. The text is cleaned again here, so only what
// includes/richtext.php writes is ever printed.
$chat_instructions = ((int)($project['show_chat_instructions'] ?? 0))
    ? richtext_render((string)($project['chat_instructions'] ?? '')) : '';

// Everyone in the chat: ids, names, colors and pictures for the header, the
// ready gate and every bubble (the participant is "human", shown as "You").
$everyone  = team_all($project);
$members   = team_bots($everyone);
$roles     = team_roles_map($everyone);
$teamNames = implode(', ', array_map(fn($m) => $m['name'], $members)) . ' and you';
/** The avatar row: the teammates, then the participant. */
$avatarStack = implode('', array_map(fn($m) => team_avatar_html($roles[$m['id']]), $members))
    . team_avatar_html($roles['human'], 'avatar avatar-human');

$chatConfig = [
    'projectId'          => $project_id,
    'script'             => $script,
    'team'               => $roles,
    'hasDirector'        => trim((string)$project['director_instructions']) !== '',
    'readyModal'         => $show_ready_modal,
    'readyWaitMs'        => $ready_wait_seconds * 1000,
    'timerEnabled'       => $timer_enabled,
    'timerDurationMs'    => $timer_seconds * 1000,
    'lockInputAtTimerEnd' => false,   // study-design choice; see assets/js/chat/session.js
    'maxMessages'        => 200,      // safety cap on bubbles per session
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Team Chat</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
  <link rel="stylesheet" href="assets/fonts/font-awesome/css/all.min.css">
  <link rel="stylesheet" href="<?= asset_url('assets/css/chat.css') ?>">
  <?= icon_links() ?>
</head>
<body>

<header class="chat-top">
  <div class="team-info">
    <div class="team-stack"><?= $avatarStack ?></div>
    <div class="team-text">
      <div class="team-name">Team Chat</div>
      <div class="team-sub" title="<?= htmlspecialchars($teamNames) ?>"><span class="online-dot"></span> <?= htmlspecialchars($teamNames) ?></div>
    </div>
  </div>
  <div class="timer-pill hidden" id="timerPill"><i class="far fa-clock"></i> <span id="timerText">--:--</span></div>
</header>
<div class="timer-track hidden" id="timerTrack"><div class="timer-fill" id="timerFill"></div></div>
<?php if ($chat_instructions !== ''): ?>
<section class="chat-instructions" id="chatInstructions" aria-label="Instructions">
  <div class="chat-instructions-box richtext scroll-slim"><?= $chat_instructions ?></div>
</section>
<?php endif; ?>

<main class="chat-scroll scroll-slim" id="chatScroll">
  <div class="chat-col" id="chatBox">
    <div class="chat-divider">Today</div>
  </div>
</main>

<div class="composer-wrap">
  <div class="composer">
    <input id="userInput" type="text" placeholder="Type a message…" autocomplete="off" maxlength="2000">
    <button id="sendBtn" type="button" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
  </div>
  <p class="composer-note hidden" id="composerNote"></p>
</div>

<div id="debugPanel"></div>

<?php if ($show_ready_modal): ?>
<div class="modal-backdrop open" id="readyModal">
  <div class="modal ready-card">
    <div id="readyContent">
      <div class="ready-team"><?= $avatarStack ?></div>
      <h2>Join a team</h2>
      <p class="modal-sub" style="margin-bottom:0;">Click the button below when you are ready, and we will match you with a team.</p>
      <?php if (trim($participant_instructions) !== ''): ?>
        <div class="ready-instructions"><?= htmlspecialchars($participant_instructions) ?></div>
      <?php endif; ?>
      <button class="btn btn-primary btn-lg btn-block mt-3" id="readyBtn" type="button">I'm ready</button>
    </div>
    <div id="findingContent" class="finding">
      <span class="spinner"></span>
      <h2 style="font-size:16px;">Finding teammates…</h2>
      <p class="modal-sub" style="margin-bottom:0;">Finding teammates can take up to a few minutes. Please keep this window open so that the connection does not break.</p>
    </div>
  </div>
</div>
<?php endif; ?>

<script>window.CHAT_CONFIG = <?= json_encode($chatConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.AIT_RELAY_URL = <?= json_encode(RELAY_WS_URL, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script src="<?= asset_url('assets/js/stream.js') ?>"></script>
<script type="module" src="<?= asset_url('assets/js/chat/main.js') ?>"></script>
</body>
</html>
