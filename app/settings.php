<?php
/**
 * settings.php — set up one study in four steps (plus, in the top bar, the
 * study status, "View data", "Add collaborator" and "Duplicate"):
 *   1 Study setup   name, study context for the AI Assistant, session flow
 *                   (waiting screen, timer, instructions above the chat)
 *   2 AI model      provider, model, response speed, API key (with a test
 *                   whose time and outcome are remembered)
 *   3 Conversation  participants, sample conversation, director instructions
 *   4 Launch        readiness check with the study status (pause/continue),
 *                   costs (the estimate for the study and what was spent so
 *                   far), participant link or embed code for a survey
 *                   (both also as a test version)
 * "Add collaborator" opens a dialog with the link to these settings and the
 * study password.
 *
 * This file renders the page and injects the study, the price list and the
 * server defaults as window.SETTINGS. The page logic lives in
 * assets/js/settings.js (ES module) and assets/js/settings-assistants.js; the
 * styles in assets/css/settings.css.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/account.php';
require_once __DIR__ . '/includes/team.php';
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/director.php';
require_once __DIR__ . '/includes/director_sections.php';
require_once __DIR__ . '/includes/richtext.php';
require_once __DIR__ . '/includes/key_test.php';
require_once __DIR__ . '/includes/assistant_keys.php';
require_once __DIR__ . '/includes/examples.php';

header('X-Content-Type-Options: nosniff');

// ?example=ID opens an example study (includes/examples.php) as the visitor's
// own DRAFT COPY: every setting except the example's API key, under a new project ID
// with a new password and the name "Copy of …". Nothing is stored until the
// first Save, which creates the project (api/save_project.php, draft_from).
$draft = null;
$draft_source = sanitize_project_id((string)($_GET['example'] ?? ''));
if ($draft_source !== '') {
    try {
        $source = example_is_project($draft_source) ? load_project($draft_source) : null;
    } catch (RuntimeException $e) {
        $source = null;
    }
    if (!$source) {
        header('Location: projects.php');
        exit;
    }
    $project      = example_draft_project($source);
    $project_id   = $project['project_id'];
    $draft        = ['source' => $draft_source, 'source_name' => (string)$source['name'], 'password' => $project['draft_password']];
    $url_password = '';
    $account_user = account_boot();
    $account_pw   = '';
} else {
    $project_id = request_project_id();
    if ($project_id === '') {
        header('Location: projects.php');
        exit;
    }

    try {
        $project = load_project($project_id);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Error</title><pre style="font-family:ui-monospace,monospace;padding:2rem;">'
            . htmlspecialchars($e->getMessage()) . '</pre>';
        exit;
    }

    if (!$project) {
        header('Location: projects.php?missing=' . urlencode($project_id));
        exit;
    }

    // Bookmarkable links: a valid ?password=… authenticates and STAYS in the URL,
    // so researchers can reopen the same link without remembering the password.
    $url_password = '';
    $pwParam = (string)($_GET['password'] ?? '');
    if (password_attempt($pwParam, (string)$project['password_hash'], 'project:' . $project_id)) {
        $url_password = $pwParam;
        session_grant($project_id);
    }
    $account_user = account_boot();
    if (!session_authorized($project_id) && $account_user) {
        // Signed in and the study is kept in the account → unlock silently
        // with the stored credential.
        account_auto_unlock($account_user, $project);
    }
    if (!session_authorized($project_id)) {
        // Clean link without a session: ask for the password in place (with an
        // optional save-in-browser), instead of bouncing to the studies page.
        require_once __DIR__ . '/includes/unlock.php';
        render_unlock_page($project, 'settings.php?project=' . urlencode($project_id), $account_user);
    }
    // The credential stored in the account, so the share card can show it on any
    // signed-in device (this page is already password-protected).
    $account_pw = $account_user ? account_project_password((int)$account_user['id'], $project_id) : '';
}
// The original of an example study is never kept in an account, so its page
// shows a note instead of the account banners.
$is_example = !$draft && example_is_project($project_id);

// Base URL of this app (works for php -S and for subdirectory deployments).
$scheme  = request_is_https() ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir     = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseUrl = $scheme . '://' . $host . $dir . '/';

$timerMinutes = max(1, (int)round(((int)$project['timer_duration_seconds']) / 60));

// How many messages participants of this study typically write: the default
// for the cost estimate (8 until at least three real conversations exist).
$typical = ['value' => 8, 'conversations' => 0];
try {
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS c, AVG(n) AS avg_n FROM (
           SELECT COUNT(*) AS n FROM messages
           WHERE project_id = ? AND sender = 'human' AND conversation_id NOT LIKE 'TEST%'
           GROUP BY conversation_id) t"
    );
    $stmt->bind_param('s', $project_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && (int)$row['c'] >= 3) {
        $typical = ['value' => max(1, (int)round((float)$row['avg_n'])), 'conversations' => (int)$row['c']];
    }
} catch (Throwable $e) {
    // the estimate simply uses the default
}

// Prices for the browser: {provider: {model key: {input, cached, write, output}}}.
$prices = [];
foreach (pricing_table() as $provider => $rows) {
    foreach ($rows as $key => [$in, $cached, $write, $out]) {
        $prices[$provider][$key] = ['input' => $in, 'cached' => $cached, 'write' => $write, 'output' => $out];
    }
}

// API keys are prefilled into this (password-protected) settings page so they
// can be seen and edited. Participants never receive them — the chat talks to
// api/director.php, which resolves keys server-side.
$settingsConfig = [
    'projectId' => $project_id,
    'baseUrl'   => $baseUrl,
    'accountPw' => $account_pw, // credential stored in the signed-in account ('' when none)
    'signedIn'  => (bool)$account_user,
    // A draft copy of an example study (see above): { source, source_name, password }, or null.
    'draft'     => $draft,
    'project'   => [
        'name'                     => (string)$project['name'],
        'team'                     => team_all($project),
        'study_context'            => (string)($project['study_context'] ?? ''),
        'participant_instructions' => (string)$project['participant_instructions'],
        'director_instructions'    => (string)$project['director_instructions'],
        'director_sections'        => director_sections_of_project($project),
        'conversation_script'      => (string)$project['conversation_script'],
        'show_ready_modal'         => (int)$project['show_ready_modal'],
        'show_instructions'        => (int)$project['show_instructions'],
        'ready_wait_seconds'       => (int)$project['ready_wait_seconds'],
        'timer_enabled'            => (int)$project['timer_enabled'],
        'timer_minutes'            => $timerMinutes,
        'show_chat_instructions'   => (int)($project['show_chat_instructions'] ?? 0),
        'chat_instructions'        => richtext_render((string)($project['chat_instructions'] ?? '')),
        'api_provider'             => (string)$project['api_provider'],
        'model'                    => (string)$project['model'],
        'director_effort'          => (string)($project['director_effort'] ?? 'minimal'),
        'api_format'               => (string)($project['api_format'] ?? 'auto'),
        'api_endpoint'             => (string)$project['api_endpoint'],
        'api_key'                  => trim((string)$project['api_key']),
        // The AI Assistant's keys: where each key comes from, never a key (includes/assistant_keys.php).
        'assistant_keys'           => assistant_keys_status($draft ? array_merge($project, ['api_key' => '', 'assistant_keys' => null]) : $project),
        'is_paused'                => (int)($project['is_paused'] ?? 0) === 1,
        'paused_at'                => $project['paused_at'] ?? null,
    ],
    // Server-wide defaults from includes/config(.local).php. This admin page is
    // session-gated; participant pages never receive these values. Only the keys
    // of SERVER_KEY_PROVIDERS are sent (not the gateway's by default). A draft
    // copy gets them too, so its Step 2 field is prefilled like any new study's.
    'server' => [
        'keys'            => ['openai' => llm_server_key('openai'), 'anthropic' => llm_server_key('anthropic'), 'custom' => llm_server_key('custom')],
        'custom_endpoint' => llm_server_endpoint(),
        'default_model'   => ['openai' => OPENAI_MODEL, 'anthropic' => ANTHROPIC_MODEL, 'custom' => CUSTOM_LLM_MODEL],
        'assistant_model' => ['provider' => ASSISTANT_PROVIDER, 'model' => ASSISTANT_MODEL], // the AI Assistant's default (Step 3)
    ],
    'catalog'      => pricing_model_catalog(),
    'prices'       => $prices,
    'pricesAsOf'   => PRICING_AS_OF,
    'tokenFactors' => PRICING_TOKEN_FACTORS,
    'scaffold'     => director_prompt_scaffold($project),
    'palette'      => TEAM_PALETTE, // [name, color] suggestions for new teammates
    'teamMax'      => TEAM_MAX,
    'nameMax'      => TEAM_NAME_MAX,
    'typical'      => $typical,
    // The last API key test (api/test_key.php stores it without Save), or null.
    'keyTest'      => $draft ? null : key_test_load($project_id),
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Settings · <?= htmlspecialchars($project['name'] !== '' ? $project['name'] : $project_id) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/settings.css') ?>">
  <link rel="stylesheet" href="assets/fonts/font-awesome/css/all.min.css">
  <?= icon_links() ?>
</head>
<body<?= $draft ? ' class="draft"' : '' ?>>

<header class="topbar">
  <a class="btn btn-sm btn-ghost topbar-back" href="projects.php" title="Back to all studies">
    <i class="fas fa-arrow-left"></i>&nbsp;Studies
  </a>
  <a class="brand" href="projects.php">
    <img class="brand-logo" src="assets/img/maestro-logo.svg" alt="MAESTRO">
  </a>
  <div class="topbar-context">
    <span class="badge badge-accent" id="projectBadge"></span>
    <button class="status-pill" id="statusPill" type="button" title="Study status: you can pause or continue the study in Step 4">
      <span class="dot"></span><span id="statusPillText">Active</span>
    </button>
  </div>
  <div class="spacer"></div>
  <nav class="row topbar-actions">
    <a class="btn btn-sm btn-view-data" id="dataNavLink" href="data.php?project=<?= urlencode($project_id) ?><?= $url_password !== '' ? '&password=' . urlencode($url_password) : '' ?>" title="Open the recorded conversations, the costs and the exports"><i class="far fa-chart-bar"></i><span class="tb-label"> View data</span></a>
    <button class="btn btn-sm btn-ghost" id="collabBtn" type="button" title="Give a colleague access to this study" aria-haspopup="dialog"><i class="fas fa-user-plus"></i><span class="tb-label"> Add collaborator</span></button>
    <button class="btn btn-sm btn-ghost" id="duplicateBtn" type="button" title="Create a copy of this study with all settings and without data"><i class="far fa-copy"></i><span class="tb-label"> Duplicate</span></button>
    <a class="btn btn-sm btn-dark" id="openTestChat" href="#" target="_blank" rel="noopener">Test chat <i class="fas fa-external-link-alt"></i></a>
  </nav>
</header>

<main class="page">
  <?php if ($draft): ?>
  <!-- Draft copy of an example study: shown instead of the account banners until the first Save (JS swaps them). -->
  <div class="acct-banner draft-note" id="draftBanner">
    <i class="far fa-copy"></i>
    <span>This is a copy of the example study “<?= htmlspecialchars($draft['source_name']) ?>” for you to edit. You can change every
    setting. Nothing is stored until you click Save changes. The first save creates the study with a new ID and password,
    and after the first save you can test the API key and use the AI Assistant.</span>
  </div>
  <?php endif; ?>
  <?php if ($is_example): ?>
  <div class="acct-banner draft-note">
    <i class="far fa-lightbulb"></i>
    <span>This study is an example study. Example studies are not kept in accounts and do not appear in study lists.
    Visitors open the example studies from the studies page.</span>
  </div>
  <?php elseif ($account_user): ?>
  <!-- Account state: exactly one of the two is visible; JS swaps them in place. -->
  <div class="acct-banner in-account <?= $account_pw !== '' ? '' : 'hidden' ?>" id="bannerIn">
    <i class="fas fa-check-circle"></i>
    <span>This study has been added to your account. You can open the study at any time from the <a href="projects.php">studies page</a>.</span>
    <span class="flex-spacer"></span>
    <button class="acct-banner-link" id="bannerRemove" type="button">Remove from account</button>
  </div>
  <div class="acct-banner add-prompt <?= $account_pw !== '' ? 'hidden' : '' ?>" id="bannerAddWrap">
    <i class="far fa-user-circle"></i>
    <span>This study is not in your account yet. After you add the study, you can open the study at any time from the studies page without the password.</span>
    <span class="flex-spacer"></span>
    <span class="acct-banner-err" id="bannerErr"></span>
    <input type="password" id="bannerPw" class="hidden" placeholder="Study password" autocomplete="off">
    <button class="btn btn-sm btn-primary" id="bannerAdd" type="button">Add to my account</button>
  </div>
  <?php else: ?>
  <div class="acct-banner signup-prompt">
    <i class="far fa-user-circle"></i>
    <span>You are working without an account. Creating an account lets you manage your studies more easily: all your studies
    appear in one list on the studies page and open from any device without typing the study ID and password.</span>
    <span class="flex-spacer"></span>
    <button class="acct-banner-link" id="bannerSignin" type="button">Sign in</button>
    <button class="btn btn-sm btn-primary" id="bannerSignup" type="button">Create an account</button>
  </div>
  <?php endif; ?>

  <div class="page-head">
    <h1>Study settings</h1>
    <p>Set up your study in four steps. You can switch between the steps at any time. Changes apply to new chats as soon as you save the changes.</p>
  </div>

  <ol class="step-tabs" id="stepTabs" aria-label="Steps">
    <li><button type="button" data-step="1">
      <span class="step-num">1<span class="step-dot"></span></span>
      <span class="step-text"><span class="step-title">Study setup</span><span class="step-sub">Describe the study, plan the session</span></span>
    </button></li>
    <li><button type="button" data-step="2">
      <span class="step-num">2<span class="step-dot"></span></span>
      <span class="step-text"><span class="step-title">AI model</span><span class="step-sub">Choose the model and enter your key</span></span>
    </button></li>
    <li><button type="button" data-step="3">
      <span class="step-num">3<span class="step-dot"></span></span>
      <span class="step-text"><span class="step-title">Conversation</span><span class="step-sub">Set up the team and the conversation</span></span>
    </button></li>
    <li><button type="button" data-step="4">
      <span class="step-num">4<span class="step-dot"></span></span>
      <span class="step-text"><span class="step-title">Launch</span><span class="step-sub">Check, see the costs, share the link</span></span>
    </button></li>
  </ol>

  <!-- =====================================================================
       Step 1 · Study setup
       ===================================================================== -->
  <section class="step" id="stepPane1" data-step="1">
    <div class="step-intro">
      <h2>Step 1 · Study setup</h2>
      <p>Start with the basics of your study: a name, background information for the AI Assistant, and what participants see before and during the chat.</p>
    </div>

    <section class="card">
      <div class="card-head">
        <h3 class="card-title">Study</h3>
      </div>
      <div class="field">
        <label class="label" for="projectName">Study name</label>
        <input id="projectName" class="input" type="text" maxlength="80" placeholder="e.g. Psychological safety pilot, wave 1">
        <p class="hint">Only you and your collaborators see the study name. Participants never see the study name.</p>
      </div>
      <div class="field">
        <div class="field-row-head">
          <label class="label" style="margin-bottom:0;">Study ID</label>
          <button class="help-btn" type="button" data-help="helpProjectId" aria-label="What is the study ID?">?</button>
        </div>
        <div class="help-note" id="helpProjectId">The study ID identifies this study in all links and in the exported data. MAESTRO creates the study ID automatically, and the study ID cannot be changed.</div>
        <div class="input-group mt-1" style="max-width:380px;">
          <input class="input mono" type="text" readonly value="<?= htmlspecialchars($project_id) ?>">
          <button type="button" class="btn" data-copy="<?= htmlspecialchars($project_id) ?>">Copy</button>
        </div>
      </div>
    </section>

    <!-- Tip: one study per experimental condition, created with "Duplicate" in the top bar. -->
    <aside class="card tip-card">
      <span class="tip-card-icon"><i class="far fa-lightbulb"></i></span>
      <div>
        <h3 class="tip-card-title">Tip: Does your study require multiple experimental conditions?</h3>
        <p>If your study compares several conditions (e.g., a team with high vs. low psychological safety), set up the first
          condition completely and then click <span class="tip-card-btn"><i class="far fa-copy"></i> Duplicate</span> at the top right of
          the page. The copy keeps all settings of the first condition, so you only change what differs between the conditions.
          You can create as many conditions as your design needs. Each condition has its own link, which keeps the data of
          each condition clearly separated.</p>
      </div>
    </aside>

    <section class="card">
      <div class="card-head">
        <h3 class="card-title">Study context <button class="help-btn" type="button" data-help="helpContext" aria-label="More about the study context">?</button></h3>
        <p class="card-lead">Describe your study in a few sentences. The AI Assistant in Step 3 reads the study context when helping you
          write the sample conversation and the conductor instructions. The conductor (the model that adapts the conversation during the
          chat) and the participants do not see the study context.</p>
      </div>
      <div class="help-note" id="helpContext">
        <p>The more the AI Assistant knows about your study, the better the texts from the AI Assistant fit your research. Useful content is a one- to
          three-sentence summary of the research idea, the concepts and definitions you work with,
          important literature, and preferences about the conversation, for example that all teammates should write in Spanish.
          The AI Assistant takes such preferences into account when writing or changing texts for you.</p>
        <p>The conductor, which rewrites the conversation during the chat, does not see the study context. Anything the teammates must
          do during the chat, such as writing in Spanish, therefore has to be part of the conductor instructions or of the sample
          conversation in Step 3.</p>
      </div>
      <textarea id="studyContext" class="textarea" rows="5" maxlength="20000"
        placeholder="Example: We study how newcomers speak up in teams. In this condition, the teammates react warmly to every idea. Psychological safety means that team members feel safe to take interpersonal risks (Edmondson, 1999). All teammates write in Spanish."></textarea>
      <p class="count-line" id="contextCount"></p>
    </section>

    <section class="card">
      <div class="card-head">
        <h3 class="card-title">Session flow</h3>
        <p class="card-lead">Here you choose what participants see before and during the chat.</p>
      </div>

      <!-- Two groups of options: before the chat (waiting screen) and during the chat -->
      <h4 class="group-title"><i class="far fa-hourglass"></i> What participants see before the chat</h4>

      <div class="field">
        <label class="switch">
          <input type="checkbox" id="showReadyModal">
          <span class="track"></span>
          <span class="switch-label">Show a waiting screen before the chat</span>
        </label>
        <p class="switch-sub">Participants click “I'm ready” and see “Finding teammates…” for a moment, as if they were being matched with other people.</p>
      </div>
      <div class="toggle-panel" id="readyGatePanel">
        <div class="field">
          <div class="field-row-head">
            <label class="label" for="readyWaitSeconds" style="margin-bottom:0;">Waiting time (seconds)</label>
            <button class="help-btn" type="button" data-help="helpWait" aria-label="About the waiting time">?</button>
          </div>
          <div class="help-note" id="helpWait">A wait of 10 to 30 seconds makes the matching feel real without trying the participants' patience. The waiting time sets how long “Finding teammates…” is shown before the chat opens.</div>
          <input id="readyWaitSeconds" class="input mt-1" type="number" min="0" max="600" step="1" style="max-width:220px;">
        </div>
      </div>

      <hr class="divider">

      <div class="field">
        <label class="switch">
          <input type="checkbox" id="showInstructions">
          <span class="track"></span>
          <span class="switch-label">Show instructions on the waiting screen</span>
        </label>
        <p class="switch-sub">Participants see a short description of the task above the “I'm ready” button. The description is only shown when the waiting screen is switched on.</p>
      </div>
      <div class="toggle-panel" id="instructionsPanel">
        <div class="field">
          <textarea id="participantInstructions" class="textarea" rows="4" maxlength="20000"
            placeholder="Example: Together with your teammates, come up with a name for a new coworking space. The chat lasts about ten minutes."></textarea>
        </div>
      </div>

      <h4 class="group-title group-next"><i class="far fa-comments"></i> What participants see during the chat</h4>

      <div class="field">
        <label class="switch">
          <input type="checkbox" id="timerEnabled">
          <span class="track"></span>
          <span class="switch-label">Show a countdown timer above the chat</span>
        </label>
        <p class="switch-sub">Participants see how much time is left. The conductor is told the remaining time as well, so your instructions can refer to the remaining time, for example to wrap up near the end.</p>
      </div>
      <div class="toggle-panel" id="timerPanel">
        <div class="field">
          <div class="field-row-head">
            <label class="label" for="timerMinutes" style="margin-bottom:0;">Duration (minutes)</label>
            <button class="help-btn" type="button" data-help="helpTimer" aria-label="What happens when the timer ends">?</button>
          </div>
          <div class="help-note" id="helpTimer">When the timer reaches zero, the chat stays open and participants can keep writing. If the conversation should end at that point, you can start a new segment in the sample conversation (Step 3) shortly before the end, for example at 9:30 with the prompt “The team says goodbye in one or two short lines and then stops writing.”</div>
          <input id="timerMinutes" class="input mt-1" type="number" min="1" max="180" step="1" style="max-width:220px;">
        </div>
      </div>

      <hr class="divider">

      <div class="field">
        <label class="switch">
          <input type="checkbox" id="showChatInstructions">
          <span class="track"></span>
          <span class="switch-label">Show instructions above the chat</span>
        </label>
        <p class="switch-sub">Participants see a formatted text in a box between the timer and the chat. The box stays visible for the whole session, so participants can look up what they need to do at any time.</p>
      </div>
      <div class="toggle-panel" id="chatInstructionsPanel">
        <div class="field">
          <div class="field-row-head">
            <span class="label" style="margin-bottom:0;">Instructions for participants</span>
            <button class="help-btn" type="button" data-help="helpChatInstructions" aria-label="About the instructions above the chat">?</button>
          </div>
          <div class="help-note" id="helpChatInstructions">
            <p>You can select text and use the buttons to make the text bold, italic or underlined, to turn lines into a list, or to change the
              size and color of the text. The box in the chat looks like this editor and has the same width on a computer screen. Text that you paste
              from another program keeps its lists and its basic formatting.</p>
            <p>Bold, italic and underlined also have keyboard shortcuts: ⌘B, ⌘I and ⌘U on a Mac, and Ctrl+B, Ctrl+I and Ctrl+U on
              Windows. With text selected, a shortcut formats that text. Without a selection, the shortcut switches the formatting on or off
              for the text you type next.</p>
            <p>Short texts work best. The box takes up at most about a third of the window, so participants have to scroll inside
              the box when the text is longer. If you leave the text empty, no box is shown.</p>
            <p>Everything the AI teammates need to know about the task belongs in the conductor instructions in Step 3, because
              the AI teammates do not read this text.</p>
          </div>
          <div class="mt-1" id="chatInstructionsEditor"></div>
          <p class="hint">Participants see this text as it looks here. You can open the test chat after saving to check the text.</p>
        </div>
      </div>
    </section>

    <div class="step-nav">
      <span></span>
      <button class="btn btn-primary" type="button" data-goto="2">Continue to Step 2 <i class="fas fa-arrow-right"></i></button>
    </div>
  </section>

  <!-- =====================================================================
       Step 2 · AI model
       ===================================================================== -->
  <section class="step" id="stepPane2" data-step="2">
    <div class="step-intro">
      <h2>Step 2 · AI model</h2>
      <p>Choose the language model that plays your AI teammates. This model works as the conductor: each time the participant sends
        a message, the conductor rewrites the rest of the conversation. The model's speed decides how quickly the team reacts, and the
        model's price decides what each conversation costs.</p>
    </div>

    <section class="card ai-card">
      <div class="card-head">
        <h3 class="card-title">Provider and model</h3>
        <p class="card-lead">Pick the provider (the company that runs the model) and the model. Step 4 shows what your study costs
          with the model you pick.</p>
      </div>

      <div class="field">
        <div class="field-row-head">
          <label class="label" style="margin-bottom:0;">Provider</label>
          <button class="help-btn" type="button" data-help="helpProvider" aria-label="About providers">?</button>
        </div>
        <div class="help-note" id="helpProvider">OpenAI and Anthropic are the companies that make the models. You need an API key from the provider you choose (a key from your account there that lets this tool use the model), and that provider bills your account for every request. You can choose Custom if your university or company offers OpenAI models through its own gateway (a server that passes requests on to the model).</div>
        <div class="provider-cards mt-1" id="providerTabs" role="radiogroup" aria-label="Provider">
          <button type="button" class="provider-card" data-provider="openai" role="radio">
            <span class="pc-mark pc-openai"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z"/></svg></span>
            <span class="pc-text"><b>OpenAI</b><small>GPT models</small></span>
            <i class="fas fa-check-circle pc-check"></i>
          </button>
          <button type="button" class="provider-card" data-provider="anthropic" role="radio">
            <span class="pc-mark pc-anthropic"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.827 3.52h3.603L24 20h-3.603l-6.57-16.48zm-7.258 0h3.767L16.906 20h-3.674l-1.343-3.461H5.017l-1.344 3.46H0L6.57 3.522zm4.132 9.959L8.453 7.687 6.205 13.48H10.7z"/></svg></span>
            <span class="pc-text"><b>Anthropic</b><small>Claude models</small></span>
            <i class="fas fa-check-circle pc-check"></i>
          </button>
          <button type="button" class="provider-card" data-provider="custom" role="radio">
            <span class="pc-mark pc-custom"><i class="fas fa-university"></i></span>
            <span class="pc-text"><b>Custom</b><small>A gateway run by your institution</small></span>
            <i class="fas fa-check-circle pc-check"></i>
          </button>
        </div>
      </div>

      <div class="field model-field">
        <div class="field-row-head">
          <label class="label" for="modelSelect" style="margin-bottom:0;">Model</label>
          <button class="help-btn" type="button" data-help="helpModel" aria-label="How to choose a model">?</button>
        </div>
        <div class="help-note" id="helpModel">Smaller models answer faster and cost less. Larger models follow long and detailed instructions more reliably, but participants wait longer for the first reply. Try a few test conversations before you collect data, and keep the same model for the whole study so that all participants get a comparable experience.</div>
        <select id="modelSelect" class="select mono mt-1"></select>
        <input id="modelInput" class="input mono hidden mt-1" type="text" maxlength="64" placeholder="The model name as your gateway expects it">
        <div class="model-chips" id="modelChips"></div>
        <p class="inline-note hidden" id="alwaysThinksNote"><i class="fas fa-info-circle"></i><span>This model always thinks a little before it writes, even at the fastest setting, so the team reacts somewhat more slowly than with models that can skip thinking.</span></p>
      </div>

      <div class="field">
        <div class="field-row-head">
          <label class="label" style="margin-bottom:0;">Response speed</label>
          <button class="help-btn" type="button" data-help="helpEffort" aria-label="About thinking">?</button>
        </div>
        <div class="help-note" id="helpEffort">Many models can think before they write. Thinking can improve the replies, but every second of thinking is a second in which the chat stays silent, and the thinking is billed like written text (as output tokens, the billing units for text the model produces). The fastest setting is a good default; a slower setting is only worth trying if the replies are clearly poor.</div>
        <div class="seg-control mt-1" id="effortSeg" role="radiogroup" aria-label="Response speed"></div>
        <select id="directorEffortSelect" class="hidden" aria-hidden="true" tabindex="-1"></select>
        <p class="hint">This sets how long the model may think before the team reacts to a message. The fastest setting is recommended.</p>
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <h3 class="card-title">API key <button class="help-btn" type="button" data-help="helpKey" aria-label="About API keys">?</button></h3>
        <p class="card-lead">The key connects this tool to your account with the provider. The key is stored on the server with this study,
          and participants do not see the key. The Test button checks that the key and the model work.</p>
      </div>
      <div class="help-note" id="helpKey">
        <p>OpenAI keys start with <code>sk-</code> and are created at platform.openai.com under API keys. Anthropic keys start with
          <code>sk-ant-</code> and are created at console.anthropic.com under API keys. The account also needs credit (a prepaid balance
          or an active payment method), otherwise requests fail even with a valid key.</p>
        <p>The test sends one very short request to the selected model. The test costs a tiny fraction of a cent and uses the key in the
          field, even if you have not saved the key yet.</p>
        <p>The time and the result of the last test are stored with the study right away, without pressing Save. A test that
          is more than a week old is shown in yellow here and in the readiness check of Step 4, as a reminder to test again
          before you collect data.</p>
      </div>
      <div class="key-row">
        <input id="apiKeyInput" class="input mono" type="text" maxlength="512" autocomplete="off" spellcheck="false" aria-label="API key">
        <button class="btn" id="testKeyBtn" type="button"><i class="fas fa-plug"></i> Test</button>
      </div>
      <p class="hint" id="apiKeyHint"></p>
      <div class="test-note" id="keyTestNote" role="status">
        <i class="fas fa-check-circle"></i>
        <span><span id="keyTestText"></span><span class="test-detail" id="keyTestDetail"></span></span>
      </div>

      <div class="field hidden mt-3" id="endpointField">
        <div class="field-row-head">
          <label class="label" for="endpointInput" style="margin-bottom:0;">Endpoint URL</label>
          <button class="help-btn" type="button" data-help="helpEndpoint" aria-label="About the endpoint">?</button>
        </div>
        <div class="help-note" id="helpEndpoint">This is the base address of your gateway (the server that passes requests on to the model), for example https://gateway.example.org. The part of the address for the chosen request format is added automatically. A complete address that ends in /chat/completions is used as entered.</div>
        <input id="endpointInput" class="input mono mt-1" type="text" maxlength="255" placeholder="https://…">
      </div>

      <div class="field mt-3" id="apiFormatField">
        <div class="field-row-head">
          <label class="label" for="apiFormatSelect" style="margin-bottom:0;">Request format</label>
          <button class="help-btn" type="button" data-help="helpFormat" aria-label="About the request format">?</button>
        </div>
        <div class="help-note" id="helpFormat">This decides in which of OpenAI's two request formats messages are sent to the model. Automatic works for OpenAI itself. Some gateways send the whole answer at once instead of word by word when the older format, Chat Completions, is used, which makes the team react late. The newer format, the Responses API, avoids the delay, and the university gateway currently needs the Responses API.</div>
        <select id="apiFormatSelect" class="select mt-1">
          <option value="auto">Automatic (recommended)</option>
          <option value="chat">Chat Completions</option>
          <option value="responses">Responses API</option>
        </select>
      </div>
    </section>

    <div class="step-nav">
      <button class="btn" type="button" data-goto="1"><i class="fas fa-arrow-left"></i> Back</button>
      <button class="btn btn-primary" type="button" data-goto="3">Continue to Step 3 <i class="fas fa-arrow-right"></i></button>
    </div>
  </section>

  <!-- =====================================================================
       Step 3 · Conversation
       ===================================================================== -->
  <section class="step" id="stepPane3" data-step="3">
    <div class="step-intro dir-intro">
      <div class="row-between">
        <h2>Step 3 · Conversation</h2>
        <div class="row" style="flex-shrink:0;">
          <button class="btn btn-primary" id="dirAssistantToggleBtn" type="button"><i class="fas fa-magic"></i> AI Assistant</button>
        </div>
      </div>
      <p>In this step you set up the conversation your participant will have. The participant joins a group chat with several
        <b>AI actors</b>, LLM-based teammates with their own personalities and communication styles, to work on a task in a
        <b>situation</b> you describe. The way the AI actors treat the participant creates the <b>social dynamic</b> of your study, and the
        dynamic is where your conditions differ. The conversation follows a <b>sample conversation</b> that you write in advance: a script
        of the actors' messages with their timing, which the AI actors play line by line. A fixed script sounds robotic the moment the
        participant says something unexpected, so an orchestrating language model, the <b>conductor</b>, sits above the actors. Whenever
        the participant writes a message, the conductor rewrites the messages that are still to come, so that the actors respond to the
        participant while the situation, the personalities and the dynamic stay as you defined. The cards below guide you through these
        pieces one by one, and the conductor reads the cards as one prompt.</p>
    </div>

    <!-- Conductor instructions -->
    <?php
    // The text boxes of the conductor instructions (the sections of includes/director_sections.php)
    // with their suggested instructions: one click appends the sentence as a new paragraph.
    // "{name}" is replaced with the name of the first AI actor. The boxes are grouped into
    // four cards with a fixed display order; the order in the prompt is changed in the
    // "Full prompt" card.
    $dirFields = [
        'situation' => ['title' => 'Situation', 'question' => 'What is the task during the conversation, and what is the background?',
            'placeholder' => 'Describe the task the team works on, the facts of the scenario, what happened before the chat, and what the AI actors know that the participant does not know.',
            'pills' => [
                ['Meet for the first time', 'The team meets for the first time in this chat, so the AI actors introduce themselves briefly.'],
                ['Continue an earlier meeting', 'The team already met before this chat, and the AI actors refer to that meeting as shared history.'],
                ['Know more than the participant', 'The AI actors know details about the task that the participant does not know, and the AI actors mention these details only when the details become relevant.'],
                ['Reach a decision together', 'The team must reach one shared decision before the chat ends.'],
            ]],
        'team' => ['title' => 'AI actors', 'question' => 'Who are the AI actors, and how do the actors behave?',
            'placeholder' => 'Describe how the AI actors write, how the actors react to the participant, how the chat ends, and what the actors have in common.',
            'pills' => [
                ['Write short messages', 'Each message has one or two sentences, like a quick chat between colleagues.'],
                ['Text casually', 'The AI actors write like people texting: lowercase is fine, a missing comma is fine, and nobody writes bullet points or headings.'],
                ['Never admit being AI', 'If the participant asks whether the AI actors are AI, the AI actors deny this once, briefly, and return to the topic.'],
                ['React to the participant', 'The AI actors pick up what the participant writes, address the participant by name, and never ignore a direct question.'],
                ['Fill the silence', 'If the participant stays silent, the AI actors continue among themselves after about half a minute and come back to the participant with a short question.'],
                ['Let {name} keep time', '{name} keeps an eye on the clock and reminds the team how much time is left.'],
                ['End the chat on time', 'When the time is over, the AI actors say goodbye in one or two short lines and stop writing.'],
                ['Keep distinct voices', 'Every AI actor keeps a recognizable way of writing that differs from the other AI actors.'],
            ]],
        'manip' => ['title' => 'Dynamics', 'question' => 'Which social dynamic should the AI actors create?',
            'placeholder' => 'Describe the climate the AI actors create and how the actors treat the participant, for example how the actors react when the participant admits a mistake. In a study with several conditions, this is what differs between the conditions.',
            'pills' => [
                ['Welcome mistakes', 'When the participant admits a mistake, the AI actors thank the participant and build on the point.'],
                ['Brush off mistakes', 'When the participant admits a mistake, the AI actors move on without a comment and do not pick up the point.'],
                ['Give the participant the lead', 'The AI actors ask the participant to decide and follow the decisions of the participant.'],
                ['Keep the lead', 'The AI actors decide among themselves and treat the suggestions of the participant as optional.'],
                ['State clear expectations', 'At the start, one AI actor states clearly what the team expects from the participant, and the AI actors refer back to these expectations.'],
                ['Let two AI actors disagree', 'Two AI actors hold opposing views on the task and argue these views in front of the participant.'],
            ]],
        'goal' => ['title' => 'Purpose', 'question' => 'What should the conductor achieve?',
            'placeholder' => 'Describe the role of the conductor and the priorities in order. Example: Above all, the participant should feel safe to speak up. Second, the chat must feel like a real group chat between peers.',
            'pills' => [
                ['Make the climate the top priority', 'The number one priority is the social climate between the group members as the participant experiences it; every other rule serves this priority.'],
                ['Keep realism second', 'The second priority is realism: the chat must read like a real small group chat between peers, not like a polished assistant.'],
                ['Keep the task in focus', 'Steer the conversation back to the task when the participant drifts away from the task.'],
                ['Stay close to the sample conversation', 'Keep the topic, the pace and the talking pattern of the sample conversation, and change only what the messages of the participant make necessary.'],
            ]],
        'rewrite' => ['title' => 'Rewrite rules', 'question' => 'What should the conductor keep in mind when it rewrites the conversation?',
            'placeholder' => 'Describe what the conductor keeps and what the conductor changes when the participant writes, for example how a short remark and a direct question differ.',
            'pills' => [
                ['Change only what is necessary', 'The conductor changes only what the message of the participant makes necessary and keeps the rest of the plan.'],
                ['React within a few messages', 'After a relevant message of the participant, one AI actor reacts within the next two to four messages, and then the group continues the discussion among the AI actors.'],
                ['Treat filler lightly', 'If the participant only writes filler such as "ok" or "hm", one AI actor reacts briefly and the other AI actors continue as planned.'],
                ['Never ignore a meaningful message', 'The conductor never ignores a meaningful message of the participant, but the conversation never becomes every AI actor answering the participant in turn.'],
            ]],
        'tech' => ['title' => 'Output format', 'question' => 'How should the conductor format the output?', 'fold' => true,
            'placeholder' => 'Write rules for the output of the conductor, for example how long the replies are. The JSON format itself is added automatically.',
            'pills' => [
                ['Output only actor messages', 'Only output messages of the AI actors, never a message for the participant.'],
                ['Write the whole remaining conversation', 'Always output the entire remaining conversation, from the first reaction to the last message the team will say.'],
                ['Keep the output minimal', 'Keep the output minimal: no explanations, no IDs, no markdown.'],
                ['Prefer shorter replies', 'Default to shorter, less complete replies when deciding between realism and completeness.'],
            ]],
    ];
    // The four cards, in the order the page shows them. The colors mark the rows in the prompt map.
    $dirCards = [
        'situation' => ['title' => 'Situation', 'question' => 'What is the task during the conversation, and what is the background?', 'fields' => ['situation']],
        'actors'    => ['title' => 'AI actors', 'question' => 'Who are the AI actors, and how do the actors behave?', 'fields' => ['team']],
        'dynamics'  => ['title' => 'Dynamics', 'question' => 'Which social dynamic should the AI actors create?', 'fields' => ['manip']],
        'conductor' => ['title' => 'Conductor', 'question' => 'How should the conductor run the chat?', 'fields' => ['goal', 'rewrite', 'tech'],
            'lead' => 'The conductor is the language model that keeps the conversation coherent. <b>Whenever the participant writes a message, the conductor rewrites the messages the AI actors have not sent yet</b>, after reading that message, the conversation so far, the sample conversation and the texts on this page. The actors then respond to the participant <b>while the situation, the personalities and the dynamic stay as you defined</b>. The conductor stays invisible to the participant and never writes in the chat itself. The boxes below tell the conductor what to achieve and what to keep in mind when the conductor rewrites the conversation.'],
    ];
    $h = fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="script-layout" id="dirLayout">
      <div class="script-main" id="dirMain">
        <button class="undo-chip hidden" id="undoDirector" type="button" disabled aria-hidden="true"><i class="fas fa-undo"></i> Undo <span class="count">0</span></button>

          <div class="dir-sections" id="dirSections">
            <?php $n = 0; foreach ($dirCards as $cardId => $card): $n++; $single = count($card['fields']) === 1; ?>
            <section class="card dir-sec" data-card="<?= $cardId ?>">
              <header class="dir-sec-head">
                <span class="dir-num" aria-hidden="true"><?= $n ?></span>
                <h4 class="dir-sec-title"><?= $h($card['title']) ?>: <span class="dir-sec-q"><?= $h($card['question']) ?></span></h4>
                <button class="undo-chip dir-undo" type="button" data-card="<?= $cardId ?>" disabled title="Undo the last change in this card"><i class="fas fa-undo"></i> Undo <span class="count">0</span></button>
              </header>
              <?php if (!empty($card['lead'])): ?><p class="dir-lead"><?= $card['lead'] /* own text with <b> */ ?></p><?php endif; ?>
              <div class="dir-sec-body">
                <?php foreach ($card['fields'] as $key): $sec = $dirFields[$key]; $fold = !empty($sec['fold']); ?>
                <div class="dir-field" data-key="<?= $key ?>"<?= $fold ? ' data-fold="1"' : '' ?>>
                  <?php if (!$single): ?>
                  <div class="dir-field-head">
                    <h5 class="dir-field-title"><?= $h($sec['title']) ?>: <span class="dir-sec-q"><?= $h($sec['question']) ?></span></h5>
                    <?php if ($fold): ?>
                    <span class="dir-tag">Expert</span>
                    <button class="link-btn dir-fold" type="button" data-key="<?= $key ?>" aria-expanded="false" aria-controls="dirBody_<?= $key ?>">Show</button>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                  <div class="dir-field-body" id="dirBody_<?= $key ?>">
                    <?php if ($key === 'team'): ?>
                    <div class="dir-team">
                      <div id="teamRows"></div>
                      <button class="add-row" id="addMemberBtn" type="button"><i class="fas fa-plus"></i> Add an AI actor</button>
                      <input type="file" id="avatarFile" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden">
                    </div>
                    <div class="dir-shared">
                    <span class="dir-sub"><i class="fas fa-users"></i> Instructions for all AI actors</span>
                    <?php endif; ?>
                    <textarea class="textarea dir-ta" data-key="<?= $key ?>" rows="3" aria-label="<?= $h($sec['title']) ?>"
                      placeholder="<?= $h($sec['placeholder']) ?>"></textarea>
                    <?php if ($sec['pills']): ?>
                    <div class="dir-pills">
                      <span class="dir-pills-label"><i class="far fa-lightbulb"></i> Suggested instructions<?= $key === 'manip' ? ' from the three published studies' : '' ?></span>
                      <div class="dir-pill-row">
                        <?php foreach ($sec['pills'] as [$label, $text]): ?>
                        <button class="dir-pill" type="button" data-text="<?= $h($text) ?>" data-label="<?= $h($label) ?>" title="<?= $h($text) ?>"><i class="fas fa-plus"></i><span><?= $h($label) ?></span></button>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($key === 'team'): ?></div><?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </section>
            <?php endforeach; ?>
          </div>

    <!-- Sample conversation: the last numbered card -->
    <section class="card dir-static" id="scriptCard">
      <header class="dir-sec-head">
        <span class="dir-num" id="scriptNum" aria-hidden="true"><?= count($dirCards) + 1 ?></span>
        <h4 class="dir-sec-title">Sample conversation: <span class="dir-sec-q">What should a typical conversation look like?</span></h4>
        <button class="help-btn" type="button" data-help="helpScript" aria-label="How to edit the sample conversation">?</button>
      </header>
      <div class="help-note" id="helpScript">
        <p>Each row is one message. The number in seconds is the pause after the previous message, and the percentage says for how
          much of that pause the typing indicator is shown. The ghost button makes a teammate type for a moment and stop without
          sending anything. You can drag rows to change their order, or switch to JSON (the conversation as structured text) to paste a
          complete conversation.</p>
        <p>The AI Assistant can write a new conversation or change the existing conversation for you, including the segments. The
          participant's messages are not part of the sample conversation.</p>
      </div>

      <div class="script-layout" id="scriptLayout">
        <div class="script-main" id="scriptMain">
          <div class="script-toolbar">
            <div class="tabs">
              <button id="tabVisual" class="active" type="button">Visual editor</button>
              <button id="tabJson" type="button">JSON</button>
            </div>
            <button class="undo-chip" id="undoScript" type="button" disabled><i class="fas fa-undo"></i> Undo <span class="count">0</span></button>
            <button class="btn btn-sm hidden" id="validateBtn" type="button">Validate</button>
            <span class="script-stats" id="scriptStats"></span>
          </div>

          <div id="scriptError"></div>
          <div id="scriptRows"></div>
          <div class="script-add hidden" id="scriptAdd">
            <button class="add-row" id="addRowBtn" type="button"><i class="fas fa-plus"></i> Add message</button>
            <button class="add-row add-segment" id="addSegmentBtn" type="button"><i class="fas fa-cut"></i> Start a new segment</button>
          </div>
          <p class="seg-note hidden" id="segNote"><i class="fas fa-cut"></i> Segments cut the conversation at a fixed time, for example when an AI actor has to leave.
            <button class="link-btn" type="button" data-help="helpSegments" aria-expanded="false" aria-controls="helpSegments">Learn more about segments</button></p>
          <div class="help-note" id="helpSegments">
            <p>A segment starts at a fixed time, counted from the start of the chat after the waiting screen. At that moment the
              conversation is cut: whatever the team had planned is dropped, and the conductor continues with the segment's
              prompt (a short text that only says what changes, in 1 to 3 sentences). The conductor instructions below still apply.</p>
            <p>Until the participant writes for the first time, the sample conversation simply plays as written. In each segment
              the first message's pause counts from the segment's start, so changes in one segment do not move the next segment.
              Messages that would come after the next segment's start are cut and shown crossed out.</p>
            <p>To divide the conversation, you can use the scissors on the message where a new segment should begin, or add a segment
              at the end. At each segment start the conductor rewrites the conversation once more, which adds a little to the costs
              of every conversation.</p>
          </div>
          <div id="scriptJsonWrap" class="hidden">
            <textarea id="scriptJson" class="textarea mono" rows="18" placeholder='{"messages":[…]}'></textarea>
          </div>
        </div>

      </div>
    </section>

    <!-- The full prompt: every block the conductor receives, in the prompt order, with the parts the software adds;
         "Reorder" (expert) collapses the blocks so the movable ones can be rearranged -->
    <section class="card dir-prompt-card" id="dirPromptCard">
      <div class="dir-foot">
        <div>
          <h4 class="dir-sec-title">Full conductor prompt</h4>
          <p class="count-line" id="directorCount"></p>
        </div>
        <button class="link-btn" id="dirShowPrompt" type="button" aria-expanded="false" aria-controls="dirFull">Show the full prompt</button>
      </div>
      <p class="dir-lead">Whenever the human participant sends a message, the conductor receives the texts above together with extra
        information from the running chat, such as the conversation so far, the messages that were still planned and the message the
        participant just sent, and adapts the script in real time. Click "Show the full prompt" to see and audit the complete prompt the
        conductor receives.</p>
      <div class="dir-full hidden" id="dirFull">
        <p class="dir-lead dir-full-lead">The colored blocks are your text boxes from the cards above. The grey blocks are the parts the software
          adds: the output format the chat needs, the conversation so far, the sample conversation, the messages that were still planned, and
          the message the participant just sent. A placeholder in curly brackets stands for a part that changes with every rewrite.
          The Reorder button lets you change the order of the colored blocks.</p>
        <div class="dir-full-bar">
          <span class="dir-full-note" id="dirOrderNote"></span>
          <span class="row">
            <button class="link-btn hidden" id="dirResetOrder" type="button">Restore the default order</button>
            <button class="btn btn-sm" id="dirMapBtn" type="button" aria-pressed="false"><i class="fas fa-sort"></i> Reorder</button>
          </span>
        </div>
        <div id="dirMapRows"></div>
        <div id="dirFixedSystem"></div>
        <div id="dirFixedUser"></div>
      </div>
    </section>
      </div>

    </div>

    <div class="step-nav">
      <button class="btn" type="button" data-goto="2"><i class="fas fa-arrow-left"></i> Back</button>
      <button class="btn btn-primary" type="button" data-goto="4">Continue to Step 4 <i class="fas fa-arrow-right"></i></button>
    </div>

    <!-- The AI Assistant: one panel beside all of Step 3 (its top next to the "AI Assistant" button), sticky while the page scrolls
         (assets/js/settings-assistants.js) -->
    <aside class="assistant-panel" id="assistantPanel" aria-label="AI Assistant">
      <div class="assistant-inner">
        <header class="a-head">
          <div class="a-title">
            <span class="a-logo"><i class="fas fa-magic"></i></span>
            <div>
              <div class="a-title-name">AI Assistant</div>
              <div class="a-title-sub">edits the cards of Step 3</div>
            </div>
          </div>
          <div class="row" style="gap:2px;">
            <button class="btn btn-sm btn-ghost" id="assistantClearBtn" type="button" title="Clear the chat with the assistant"><i class="far fa-trash-alt"></i></button>
            <button class="btn btn-sm btn-ghost" id="assistantCloseBtn" type="button" title="Close"><i class="fas fa-times"></i></button>
          </div>
        </header>

        <div class="assistant-chat scroll-slim" id="assistantChat"></div>
        <div class="a-suggest" id="assistantChips"></div>

        <div class="a-composer">
          <div class="a-inputrow">
            <textarea id="assistantInput" rows="1" maxlength="4000" placeholder="Ask for changes to Step 3…" autocomplete="off" aria-label="Message to the AI Assistant (Enter sends, Shift+Enter starts a new line)"></textarea>
            <button class="icon-btn send-btn" id="assistantSendBtn" type="button" title="Send"><i class="fas fa-paper-plane"></i></button>
          </div>
          <div class="a-status">
            <div class="a-selects">
              <select id="assistantModel" class="a-tag-select mono" title="Model (click to change)"></select>
              <select id="assistantEffort" class="a-tag-select" title="How long the assistant may think (click to change)"></select>
            </div>
            <div class="a-status-row">
              <span class="a-side" id="assistantCtxSide" title="Share of the model's context window (its working memory) used by the last request">
                <span class="a-donut" id="assistantDonut"></span>
                <span id="assistantCtxText">context –</span>
              </span>
              <button class="a-key" id="assistantKeyBtn" type="button"><i class="fas fa-exclamation"></i> API key</button>
            </div>
          </div>
        </div>
      </div>
    </aside>
  </section>

  <!-- The AI Assistant at hand while scrolling: shown on Step 3 once the "AI Assistant" button of the
       header has scrolled away and the panel is closed (outside the step pane, whose animation would move it) -->
  <button class="ai-fab" id="assistantFab" type="button" hidden><i class="fas fa-magic"></i><span>AI Assistant</span></button>

  <!-- =====================================================================
       Step 4 · Launch
       ===================================================================== -->
  <section class="step" id="stepPane4" data-step="4">
    <div class="step-intro">
      <h2>Step 4 · Launch</h2>
      <p>Check that everything is ready, see what the study will cost, and share the link with your participants. You can pause
        the study here at any time.</p>
    </div>

    <!-- Readiness check, with the study status (pause / continue) as its last part -->
    <section class="card" id="readyCard">
      <div class="card-head">
        <h3 class="card-title">Readiness check</h3>
        <p class="card-lead">These points are checked against the settings on this page, including unsaved changes.</p>
      </div>
      <ul class="ready-list" id="readyList"></ul>

      <div class="card-part" id="statusSection">
        <h4 class="part-title">Study status <button class="help-btn" type="button" data-help="helpStatus" aria-label="About pausing">?</button></h4>
        <div class="help-note" id="helpStatus">
          <p>Pausing the study stops new participants from starting, for example between two waves or once you have enough data.
            When the study is active, everyone with the participant link can take part.</p>
          <p>When the study is paused, the participant link shows a message that the study is not active, instead of the chat.
            Participants who are already in a chat can finish the chat. Test links keep working, so you can try changes before you
            continue the study.</p>
          <p>Pausing and continuing take effect immediately. You do not need to press Save for pausing or continuing.</p>
        </div>
        <div class="status-box" id="statusBox">
          <span class="status-dot"></span>
          <span class="status-text"><b id="statusTitle"></b><span id="statusSub"></span></span>
          <button class="btn" id="pauseBtn" type="button"></button>
        </div>
      </div>
    </section>

    <!-- Costs: the estimate for the whole study and what was spent so far. The
         calculation behind both appears with "Show more". -->
    <section class="card" id="costCard">
      <div class="card-head">
        <h3 class="card-title">Costs <button class="help-btn" type="button" data-help="helpEstimate" aria-label="How the costs are calculated">?</button></h3>
        <p class="card-lead">The provider of the AI model charges for every conversation. Enter the number of participants and messages
          you expect, and the estimate shows what the study will cost.</p>
      </div>
      <div class="help-note" id="helpEstimate">
        <p>The estimate uses the conductor instructions and the sample conversation from Step 3 as they are now, including unsaved
          changes, and the model from Step 2. The count of tokens (the billing units of text) is usually within 5 percent of the
          provider's own count.</p>
        <p>Early in a conversation the conductor rewrites almost the whole sample conversation, so the output is large. Later there
          is less left to write, but the chat so far is longer. Providers bill the text the model writes (output tokens) at a higher
          rate than the text it reads (input tokens).</p>
        <p>For OpenAI models the costs include the prompt cache: OpenAI stores the beginning of each prompt (the text sent to the
          model) for a while. From the second message of a conversation on, the part that stays the same (mainly your conductor
          instructions) is read from
          the cache at a much lower price. The rest of the prompt is written to the cache, and with the newest models writing to the
          cache costs 1.25 times the normal input price. Anthropic models are billed without a cache.</p>
        <p>“Spent so far” counts every request this study has sent to a model: the conductor's rewrites in all conversations,
          including your test conversations, and the AI Assistant in Step 3.</p>
      </div>

      <p class="cost-model">
        <span class="cm-label">Model</span>
        <span class="cm-name mono" id="costModelName"></span>
        <button class="link-btn" type="button" data-goto="2">Change model</button>
      </p>

      <div class="cost-fields">
        <label class="cost-field" for="estMessages">
          <span class="cf-icon cf-violet"><i class="fas fa-comment-dots"></i></span>
          <span class="cf-body">
            <span class="cf-label">Messages per participant</span>
            <input id="estMessages" class="cf-input" type="number" inputmode="numeric" min="1" max="200" step="1">
            <span class="cf-hint" id="estMessagesHint">Estimate how many messages each participant will write in the chat.</span>
          </span>
        </label>
        <label class="cost-field" for="estParticipants">
          <span class="cf-icon cf-blue"><i class="fas fa-users"></i></span>
          <span class="cf-body">
            <span class="cf-label">Number of participants</span>
            <input id="estParticipants" class="cf-input" type="number" inputmode="numeric" min="1" max="100000" step="1">
            <span class="cf-hint">Estimate how many people will take part in your study.</span>
          </span>
        </label>
      </div>

      <!-- Three results in a row of their own: per participant, the whole study, spent so far.
           (Without the texts of Step 3 the first is hidden and the second asks for them.) -->
      <div class="cost-results" id="costResults">
        <div class="cost-stat cs-per" id="costPerStat">
          <span class="cs-label" id="costPerLabel">Estimated cost per participant</span>
          <span class="cs-value" id="costPer"></span>
          <span class="cs-sub" id="costPerSub"></span>
        </div>
        <div class="cost-stat cs-estimate" id="costEstimateStat">
          <span class="cs-label" id="costStudyLabel">Estimated cost of the study</span>
          <span class="cs-value" id="costStudy"></span>
          <span class="cs-sub" id="costStudySub"></span>
          <button class="btn btn-sm hidden" id="costGoStep3" type="button" data-goto="3">Go to Step 3</button>
        </div>
        <div class="cost-stat cs-spent">
          <span class="cs-label">Spent so far</span>
          <span class="cs-value" id="costSpent"></span>
          <span class="cs-sub" id="costSpentSub"></span>
        </div>
      </div>
      <p class="cost-note"><i class="fas fa-info-circle"></i><span id="costNoteText"></span></p>
      <p class="inline-note hidden" id="estimateThinkingNote"><i class="fas fa-info-circle"></i><span id="estimateThinkingText"></span></p>

      <div class="cost-more hidden" id="costMore">
        <div class="card-part" id="estimateDetails">
          <h4 class="part-title">How the estimate is calculated</h4>
          <p class="part-lead">Each message of a participant triggers one rewrite. The conductor reads your instructions, the sample
            conversation and the chat so far (input tokens) and writes the rest of the conversation (output tokens).</p>
          <div class="source-tiles" id="sourceTiles"></div>
          <div id="estimateBody"></div>
          <p class="cost-foot" id="estimateFoot"></p>
        </div>
        <div class="card-part">
          <h4 class="part-title">What has been spent so far</h4>
          <div id="usageBody"></div>
          <p class="cost-foot" id="usageFoot"></p>
        </div>
      </div>

      <div class="show-more-row">
        <button class="show-more-btn" id="costMoreBtn" type="button" aria-expanded="false" aria-controls="costMore">
          <span id="costMoreLabel">Show more</span> <i class="fas fa-chevron-down"></i>
        </button>
      </div>
    </section>

    <!-- One field, two formats: the link itself, or the code that shows the chat
         inside a survey page (an iframe). The switches below apply to both. -->
    <section class="card" id="linkCard">
      <div class="card-head link-head">
        <h3 class="card-title">Sharing <span class="badge badge-test hidden" id="testBadge">TEST</span></h3>
        <div class="tabs link-format" role="radiogroup" aria-label="How to share the chat">
          <button type="button" role="radio" data-format="link" aria-checked="true" class="active"><i class="fas fa-link"></i> Link</button>
          <button type="button" role="radio" data-format="embed" aria-checked="false"><i class="fas fa-code"></i> Embed code</button>
        </div>
      </div>
      <div class="field">
        <div class="input-group">
          <textarea id="shareField" class="textarea mono" readonly rows="1" spellcheck="false" aria-label="Link"></textarea>
          <button class="btn" type="button" id="linkCopy">Copy</button>
          <a class="btn btn-primary hidden" id="openTestLink" href="#" target="_blank" rel="noopener">Open</a>
        </div>
        <!-- What to do with the link or the code; set by refreshLinks() -->
        <p class="link-hint" id="linkHint"></p>
        <p class="inline-note hidden" id="testLinkNote"><i class="fas fa-flask"></i><span id="testLinkNoteText"></span></p>

        <div class="link-options">
          <div class="link-option">
            <div class="row" style="gap:4px;">
              <label class="switch" style="gap:8px;">
                <input type="checkbox" id="testToggle">
                <span class="track"></span>
                <span class="switch-label" style="font-weight:500;">Test link</span>
              </label>
              <button class="help-btn" type="button" data-help="helpTestLink" aria-label="About the test link">?</button>
            </div>
            <p class="switch-sub">Switch on the test link to try the chat yourself.</p>
            <div class="help-note" id="helpTestLink">The test link opens the chat as participants see the chat. Test conversations
              get an ID that starts with TEST, so you can recognize test conversations and delete them later on the data page. The test link
              also works when the study is paused. Because test conversations always get a TEST ID, a test link cannot carry a conversation ID
              from Qualtrics.</div>
          </div>
          <div class="link-option" id="pipedOption">
            <div class="row" style="gap:4px;">
              <label class="switch" style="gap:8px;">
                <input type="checkbox" id="pipedToggle">
                <span class="track"></span>
                <span class="switch-label" style="font-weight:500;" id="pipedLabel">Add a conversation ID to the link</span>
              </label>
              <button class="help-btn" type="button" data-help="helpPiped" aria-label="About the conversation ID">?</button>
            </div>
            <p class="switch-sub" id="pipedSub">If you leave the conversation ID off, every conversation gets a random ID.</p>
            <p class="switch-sub hidden" id="pipedOffNote">This option is not available for a test link.</p>
            <div class="help-note" id="helpPiped">
              <p>A conversation ID lets you link each chat to other data about the same participant, for example the participant's answers in your
                survey. The chat data and the survey data contain the same identifier, so you can match the chat data to the survey data.</p>
              <p>The ID is added to the end of the link (also to the link inside the embed code) as <code>conversationID=</code> followed
                by what you enter. Usually you enter a variable of your survey tool, such as piped text in Qualtrics. The survey tool
                replaces the variable with the participant's value, for example the response ID, before the chat opens.</p>
              <p>If there is no conversation ID, or the survey tool does not fill in the variable (for example when the link is opened
                outside the survey), the chat gives the conversation a random ID.</p>
            </div>
            <!-- The ID itself: any text, or a placeholder filled in from one of the templates -->
            <div class="piped-fields hidden" id="pipedFields">
              <input id="pipedValue" class="input mono" spellcheck="false" autocomplete="off"
                placeholder="Type a survey variable or choose a template" aria-label="Conversation ID">
              <div class="id-templates">
                <span class="id-templates-title" id="idTemplatesTitle">Choose a template:</span>
                <ul class="id-list" aria-labelledby="idTemplatesTitle">
                  <li><button type="button" class="id-tag" data-value="${e://Field/conversationID}" title="${e://Field/conversationID}">Qualtrics embedded data</button></li>
                  <li><button type="button" class="id-tag" data-value="${e://Field/ResponseID}" title="${e://Field/ResponseID}">Qualtrics response ID</button></li>
                  <li><button type="button" class="id-tag" data-value="${q://QID1/ChoiceTextEntryValue}" title="${q://QID1/ChoiceTextEntryValue}">Qualtrics question</button></li>
                  <li><button type="button" class="id-tag" data-value="{{%PROLIFIC_PID%}}" title="{{%PROLIFIC_PID%}}">Prolific ID</button></li>
                  <li><button type="button" class="id-tag" data-value="{SAVEDID}" title="{SAVEDID}">LimeSurvey ID</button></li>
                </ul>
              </div>
              <p class="id-hint"><i class="fas fa-info-circle"></i><span id="pipedHint"></span></p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- The way to the data page, easier to find than "View data" in the top bar,
         with a short count of what has been recorded (from the same list the costs use) -->
    <section class="data-card" id="dataCard">
      <i class="far fa-chart-bar data-card-icon"></i>
      <div class="data-card-text">
        <h3 class="data-card-title">Collected data</h3>
        <p class="data-stats" id="dataStats">Loading the number of conversations.</p>
      </div>
      <!-- The link covers the whole card (::after), so a click anywhere opens the data -->
      <a class="btn data-card-btn" id="dataCardLink" href="data.php?project=<?= urlencode($project_id) ?><?= $url_password !== '' ? '&password=' . urlencode($url_password) : '' ?>">View data</a>
    </section>

    <div class="step-nav">
      <button class="btn" type="button" data-goto="3"><i class="fas fa-arrow-left"></i> Back</button>
      <span></span>
    </div>
  </section>
</main>

<div class="savebar" id="savebar">
  <span class="status" id="saveStatus">All changes saved</span>
  <button class="btn btn-primary" id="saveBtn" type="button" disabled>Save changes</button>
</div>

<?php if (!$account_user && !$is_example): ?>
<!-- Sign up or sign in without leaving the settings: saves the open changes,
     creates the account (or signs in), adds this study to it and reloads here -->
<div class="modal-backdrop" id="acctModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="acctTitle">
    <h2 id="acctTitle">Keep this study in an account</h2>
    <p class="modal-sub">Creating an account lets you manage your studies more easily: all your studies appear in one list on the
      studies page and open from any device without typing the study ID and password. An account is optional; without an account, every
      study still opens with its own link and password.</p>
    <div class="tabs acct-tabs" role="tablist">
      <button id="acctTabRegister" class="active" type="button" role="tab">Create account</button>
      <button id="acctTabLogin" type="button" role="tab">Sign in</button>
    </div>
    <form id="acctForm" novalidate>
      <div class="field">
        <label class="label" for="acctEmail">Email</label>
        <input id="acctEmail" class="input" type="email" autocomplete="username" required>
      </div>
      <div class="field">
        <label class="label" for="acctPassword">Account password</label>
        <input id="acctPassword" class="input" type="password" autocomplete="new-password" required>
        <p class="hint" id="acctPwHint">At least 8 characters. There is no password recovery, so please store the password safely.</p>
      </div>
      <!-- Only when this tab does not know the study password: the account stores it -->
      <div class="field hidden" id="acctStudyPwField">
        <label class="label" for="acctStudyPw">Password of this study</label>
        <input id="acctStudyPw" class="input" type="password" autocomplete="off">
        <p class="hint">The account stores the study password, so you do not need to enter the study password again.</p>
      </div>
      <p class="hint" id="acctError" style="color: var(--danger);" role="alert"></p>
      <div class="row mt-2 modal-actions" style="justify-content: flex-end;">
        <button class="btn" id="acctCancel" type="button">Cancel</button>
        <button class="btn btn-primary" id="acctSubmit" type="submit">Create account and save</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($draft): ?>
<!-- Draft copy: credentials of the study that the first Save created -->
<div class="modal-backdrop" id="draftSavedModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="draftSavedTitle">
    <h2 id="draftSavedTitle">Your copy has been saved as a new study.</h2>
    <p class="modal-sub">Save these credentials now. You need the study ID and password to reopen the study and its data.</p>
    <div class="cred-box">
      <div class="cred-row"><span class="cred-label">Name</span><span class="cred-value cred-text" id="draftCredName"></span></div>
      <div class="cred-row"><span class="cred-label">Study ID</span><span class="cred-value" id="draftCredId"></span></div>
      <div class="cred-row"><span class="cred-label">Password</span><span class="cred-value" id="draftCredPass"></span></div>
    </div>
    <?php if ($account_user): ?>
    <p class="acct-note"><i class="fas fa-check-circle"></i>
      The study was also added to your account (<?= htmlspecialchars($account_user['email']) ?>), so you can reopen the study
      from the studies page without the password.</p>
    <?php else: ?>
    <p class="acct-note"><i class="far fa-user-circle"></i>
      If you create an account later, you can add this study to the account with the study ID and the password.</p>
    <?php endif; ?>
    <div class="row mt-3 cred-actions">
      <div class="row">
        <button class="btn" id="draftCredDownload" type="button">Download .txt</button>
        <button class="btn" id="draftCredCopy" type="button">Copy credentials</button>
      </div>
      <button class="btn btn-primary" id="draftCredClose" type="button">Continue</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- API keys of the AI Assistant (Step 3): stored with the study, apart from the key of Step 2 (api/assistant_keys.php) -->
<div class="modal-backdrop" id="akeyModal" aria-hidden="true">
  <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="akeyTitle">
    <div class="modal-head">
      <h2 id="akeyTitle">API keys for the AI Assistant</h2>
      <button class="modal-close" id="akeyClose" type="button" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <p class="modal-sub">To use the AI Assistant, an API key from OpenAI or Anthropic is needed. With the key, the assistant communicates
      with the servers of the AI provider, and the provider charges your account for the generated text.</p>
    <?php foreach (['openai' => ['OpenAI', 'pc-openai', 'GPT models', 'sk-…'], 'anthropic' => ['Anthropic', 'pc-anthropic', 'Claude models', 'sk-ant-…']] as $akP => [$akName, $akClass, $akSub, $akHint]): ?>
    <div class="akey-row" data-provider="<?= $akP ?>">
      <span class="pc-mark <?= $akClass ?>"><?= $akP === 'openai' ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z"/></svg>' : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.827 3.52h3.603L24 20h-3.603l-6.57-16.48zm-7.258 0h3.767L16.906 20h-3.674l-1.343-3.461H5.017l-1.344 3.46H0L6.57 3.522zm4.132 9.959L8.453 7.687 6.205 13.48H10.7z"/></svg>' ?></span>
      <div class="akey-main">
        <div class="akey-top">
          <span class="akey-name"><b><?= $akName ?></b> <small><?= $akSub ?></small></span>
          <span class="akey-state"></span>
        </div>
        <form class="akey-form" autocomplete="off">
          <input class="input mono akey-input" type="password" maxlength="512" spellcheck="false" autocomplete="off"
            placeholder="Paste an <?= $akName ?> key (<?= $akHint ?>)" aria-label="<?= $akName ?> key for the AI Assistant">
          <button class="btn btn-sm btn-primary akey-save" type="submit">Save</button>
        </form>
        <div class="akey-links">
          <button class="link-btn akey-import" type="button"><i class="fas fa-file-import"></i> Import the key from the study (Step 2)</button>
          <button class="link-btn akey-remove hidden" type="button">Remove the key</button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <p class="akey-foot"><i class="fas fa-lock"></i><span>The keys are stored with this study on the server and are never shown again.
      Without a key of its own, the AI Assistant uses the key from Step 2, or the default key of the server if there is a default key.</span></p>
    <div class="modal-actions">
      <button class="btn btn-primary" id="akeyDone" type="button">Done</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="duplicateModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="duplicateTitle">
    <h2 id="duplicateTitle">Duplicate this study?</h2>
    <p class="modal-sub">The copy gets every setting of this study: the study context, the participants, the sample conversation,
      the conductor instructions, the session flow and the AI model with its API key. The copy gets its own links, the same password,
      and none of the recorded data.</p>
    <p class="inline-note hidden" id="duplicateDirtyNote"><i class="fas fa-info-circle"></i><span>You have unsaved changes. The changes
      are saved first, so that the copy includes every change.</span></p>
    <div class="modal-actions">
      <button class="btn" id="duplicateCancel" type="button">Cancel</button>
      <button class="btn btn-primary" id="duplicateConfirm" type="button"><i class="far fa-copy"></i> Create the copy</button>
    </div>
  </div>
</div>

<!-- "Add collaborator" (top bar): the link to these settings and the study password -->
<div class="modal-backdrop" id="collabModal" aria-hidden="true">
  <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="collabTitle">
    <div class="modal-head">
      <h2 id="collabTitle">Add a collaborator</h2>
      <button class="modal-close" id="collabClose" type="button" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <p class="modal-sub">You can give a colleague access by sending the colleague the link and the study password. A collaborator gets the
      same access as you: all settings, including the sample conversation and the conductor instructions, and all data. Share the link and
      the password only with people you trust with the whole study.</p>

    <div class="field">
      <label class="label" for="settingsLink">Link to this study</label>
      <div class="input-group">
        <input id="settingsLink" class="input mono" readonly>
        <button class="btn" type="button" data-copy-from="settingsLink">Copy</button>
      </div>
    </div>
    <div class="field">
      <label class="label" for="settingsPw">Study password</label>
      <div class="input-group" style="max-width: 380px;">
        <input id="settingsPw" class="input mono" readonly>
        <button class="btn" id="settingsPwCopy" type="button" data-copy-from="settingsPw">Copy</button>
      </div>
      <p class="hint hidden" id="settingsPwHint">This browser does not know the study password, so the password cannot be shown here.
        Use the password you received when the study was created.</p>
      <label class="switch mt-2" style="gap:8px;">
        <input type="checkbox" id="embedPwToggle">
        <span class="track"></span>
        <span class="switch-label" style="font-weight:500;">Include the password in the link</span>
      </label>
      <div class="safety-note safe" id="settingsLinkNote">
        <i class="fas fa-shield-alt"></i>
        <span id="settingsLinkNoteText"></span>
      </div>
    </div>

    <div class="collab-how">
      <h3>How your collaborator opens the study</h3>
      <div class="how-row">
        <span class="how-icon"><i class="fas fa-link"></i></span>
        <p><b>If your colleague has no account:</b> <span id="collabHowGuest"></span></p>
      </div>
      <div class="how-row">
        <span class="how-icon"><i class="far fa-user-circle"></i></span>
        <p><b>If your colleague has an account:</b> <span id="collabHowAccount"></span></p>
      </div>
      <div class="how-row">
        <span class="how-icon"><i class="fas fa-user-friends"></i></span>
        <p><b>When you work together:</b> You both edit the same study. Avoid editing the settings at the same time, because
          whoever saves last replaces the changes of the other person.</p>
      </div>
    </div>

    <div class="modal-actions">
      <button class="btn btn-primary" id="collabDone" type="button">Done</button>
    </div>
  </div>
</div>

<script>
window.SETTINGS = <?= json_encode($settingsConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.AIT_RELAY_URL = <?= json_encode(RELAY_WS_URL, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
</script>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script src="<?= asset_url('assets/js/stream.js') ?>"></script>
<script type="module" src="<?= asset_url('assets/js/settings.js') ?>"></script>
</body>
</html>
