<?php
/**
 * data.php — the data of a project: browse the recorded conversations, their
 * tokens and estimated costs, and export everything. (Formerly review.php,
 * which now forwards here so that old shared links keep working.)
 *
 * The page renders the shell and a small inline module; the data comes from
 * api/data.php, and the costs dialog shares its parts with the settings page
 * (assets/js/spending.js, assets/js/format.js).
 *
 * Access: unlocked session (Studies page) or a share link with ?key=….
 * Deleting data needs the unlocked session; the share key is read-only.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/account.php';
require_once __DIR__ . '/includes/team.php';

header('X-Content-Type-Options: nosniff');

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
$account_user = account_boot();
if (!session_authorized($project_id) && $account_user) {
    // Signed in with the study in the account → unlock silently.
    account_auto_unlock($account_user, $project);
}
if (!data_authorized($project)) {
    // Clean link without a session or share key: password popup in place.
    require_once __DIR__ . '/includes/unlock.php';
    render_unlock_page($project, 'data.php?project=' . urlencode($project_id), $account_user);
}

$share_key = (string)($_GET['key'] ?? '');
$has_session = session_authorized($project_id);

// The link for collaborators: this page with the shared data key instead of
// the password (researchers with the unlocked study can copy it).
$scheme = request_is_https() ? 'https' : 'http';
$dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$own_share_key = data_share_key($project_id);
$share_link = ($has_session && $own_share_key !== '')
    ? $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/data.php?project=' . urlencode($project_id) . '&key=' . urlencode($own_share_key)
    : '';

// Keep a valid ?password=… from the URL flowing into the cross-links, so the
// bookmarked link chain (settings ↔ data) stays self-contained.
$url_password = '';
$pwParam = (string)($_GET['password'] ?? '');
if (password_attempt($pwParam, (string)$project['password_hash'], 'project:' . $project_id)) {
    $url_password = $pwParam;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Data · <?= htmlspecialchars($project['name'] !== '' ? $project['name'] : $project_id) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
  <link rel="stylesheet" href="assets/fonts/font-awesome/css/all.min.css">
  <?= icon_links() ?>
  <style>
    html, body { height: 100%; overflow: hidden; }
    body { display: flex; flex-direction: column; }
    .data-main { flex: 1; display: flex; min-height: 0; }

    /* ---- Sidebar ---- */
    .sidebar {
      width: 320px; flex-shrink: 0; display: flex; flex-direction: column;
      border-right: 1px solid var(--line); background: #fff; min-height: 0;
    }
    .sidebar-head { padding: 14px 14px 10px; border-bottom: 1px solid var(--line-soft); }
    .sidebar-head .search { position: relative; }
    .sidebar-head input {
      width: 100%; font: inherit; font-size: 13.5px;
      padding: 8px 12px; border: 1px solid var(--line); border-radius: 9px; outline: none;
      background: var(--line-soft);
    }
    .sidebar-head input:focus { background: #fff; border-color: var(--accent); }
    .sidebar-meta { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; }
    .sidebar-meta .count { font-size: 12px; color: var(--muted); font-weight: 600; }
    .conv-list { flex: 1; overflow-y: auto; padding: 8px; min-height: 0; }
    .conv-item {
      width: 100%; text-align: left; font: inherit; cursor: pointer;
      border: 1px solid transparent; background: transparent; border-radius: 12px;
      padding: 10px 12px; display: block; margin-bottom: 2px;
    }
    .conv-item:hover { background: var(--line-soft); }
    .conv-item.active { background: var(--accent-soft); border-color: #dbe1ff; }
    .conv-item .cid {
      font-family: var(--mono); font-size: 12.5px; font-weight: 600; color: var(--ink);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .conv-item .cmeta { display: flex; gap: 8px; align-items: center; font-size: 11.5px; color: var(--muted); margin-top: 3px; flex-wrap: wrap; }
    .badge-warn { background: #fef3c7; color: #92400e; }
    .stat-chip.warn { background: #fef3c7; color: #92400e; }
    .conv-empty { padding: 30px 16px; text-align: center; color: var(--muted); font-size: 13.5px; }
    .conv-wrap { position: relative; }
    .conv-wrap .conv-del {
      position: absolute; right: 9px; top: 9px; display: none;
      width: 26px; height: 26px; align-items: center; justify-content: center;
      border: 1px solid var(--line); border-radius: 8px; background: #fff;
      color: var(--muted); cursor: pointer; padding: 0;
    }
    .conv-wrap:hover .conv-del { display: inline-flex; }
    .conv-wrap .conv-del:hover { color: var(--danger); border-color: #fca5a5; background: var(--danger-soft); }

    /* ---- Transcript pane ---- */
    .viewer { flex: 1; display: flex; flex-direction: column; min-width: 0; min-height: 0; background: var(--bg); }
    .viewer-head {
      display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
      padding: 12px 20px; background: #fff; border-bottom: 1px solid var(--line);
    }
    .viewer-title { font-family: var(--mono); font-size: 13.5px; font-weight: 700; min-width: 0; overflow-wrap: anywhere; }
    .viewer-back { display: none; }
    .stats { display: flex; gap: 8px; flex-wrap: wrap; }
    .stat-chip {
      display: inline-flex; align-items: center; gap: 6px;
      font-size: 12px; font-weight: 600; color: var(--ink-2);
      background: var(--line-soft); border-radius: 999px; padding: 4px 11px;
    }
    .stat-chip .dot { width: 7px; height: 7px; border-radius: 50%; }
    /* The costs load after the list (api/data.php?action=usage): a small spinner holds their place. */
    .spinner.spinner-xs { width: 10px; height: 10px; border-width: 2px; vertical-align: middle; }
    .stat-chip.loading { color: var(--muted); font-weight: 500; }
    .transcript { flex: 1; overflow-y: auto; padding: 10px 0 30px; min-height: 0; }
    .transcript-col { max-width: 780px; margin: 0 auto; padding: 0 20px; }
    .viewer-empty {
      flex: 1; display: flex; align-items: center; justify-content: center;
      color: var(--muted); font-size: 14px; text-align: center; padding: 30px;
    }

    /* ---- Export dialog ---- */
    .overlay {
      position: fixed; inset: 0; z-index: 60; background: rgba(15, 23, 42, .46);
      display: flex; align-items: center; justify-content: center; padding: 18px;
      opacity: 0; pointer-events: none; transition: opacity .18s ease;
    }
    .overlay.open { opacity: 1; pointer-events: auto; }
    .dialog {
      width: min(620px, 100%); max-height: calc(100vh - 60px); overflow-y: auto;
      background: #fff; border-radius: 18px; box-shadow: 0 24px 70px rgba(2, 6, 23, .35);
      padding: 22px 24px 24px; transform: translateY(10px) scale(.985); transition: transform .18s ease;
    }
    .overlay.open .dialog { transform: none; }
    .dialog-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 6px; }
    .dialog-title { font-size: 17px; font-weight: 800; letter-spacing: -.01em; }
    .dialog-sub { color: var(--muted); font-size: 13px; margin-top: 3px; }
    .dialog-close {
      margin-left: auto; border: none; background: var(--line-soft); color: var(--muted);
      width: 30px; height: 30px; border-radius: 9px; cursor: pointer; font-size: 15px; line-height: 1;
      flex-shrink: 0;
    }
    .dialog-close:hover { background: var(--line); color: var(--ink); }

    .export-section {
      border: 1px solid var(--line); border-radius: 14px; padding: 15px 16px;
      margin-top: 12px;
    }
    .export-section h3 { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .export-section h3 .emoji { font-size: 15px; }
    .export-section p { color: var(--muted); font-size: 12.8px; margin-top: 3px; line-height: 1.5; }
    .export-controls { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 11px; }

    .seg {
      display: inline-flex; background: var(--line-soft); border-radius: 10px; padding: 3px; gap: 2px;
    }
    .seg button {
      font: inherit; font-size: 12.8px; font-weight: 600; color: var(--muted);
      border: none; background: transparent; padding: 6px 13px; border-radius: 8px; cursor: pointer;
      white-space: nowrap;
    }
    .seg button.active { background: #fff; color: var(--ink); box-shadow: 0 1px 4px rgba(2, 6, 23, .14); }
    .seg button:disabled { opacity: .45; cursor: not-allowed; }

    .export-hint {
      font-size: 12px; color: var(--muted); margin-top: 9px; min-height: 16px;
    }
    .export-hint .mono { font-family: var(--mono); font-size: 11px; background: var(--line-soft); border-radius: 6px; padding: 1px 6px; }
    .export-go { margin-left: auto; }

    .opt {
      display: flex; align-items: flex-start; gap: 9px; margin-top: 9px; cursor: pointer;
      font-size: 12.8px; font-weight: 600; color: var(--ink-2); line-height: 1.35;
    }
    .opt input { accent-color: var(--accent); width: 15px; height: 15px; margin-top: 1px; flex-shrink: 0; cursor: pointer; }
    .opt .opt-sub { display: block; font-weight: 400; color: var(--muted); font-size: 11.8px; margin-top: 1px; }
    .opt .mono { font-family: var(--mono); font-size: 10.5px; background: var(--line-soft); border-radius: 5px; padding: 0 5px; }
    .warn {
      background: #fef3c7; border: 1px solid #fcd34d; color: #92400e;
      border-radius: 10px; padding: 8px 11px; font-size: 12px; line-height: 1.5; margin-top: 9px;
    }
    .warn-danger { background: var(--danger-soft); border-color: #fca5a5; color: #991b1b; }
    .sidebar-foot { padding: 9px; border-top: 1px solid var(--line-soft); }
    .btn-ghost-danger {
      width: 100%; justify-content: center; color: var(--danger);
      background: transparent; border-color: transparent;
    }
    .btn-ghost-danger:hover { background: var(--danger-soft); border-color: transparent; color: #b91c1c; }
    .wipe-ask { font-size: 12.8px; color: var(--ink-2); margin-top: 14px; line-height: 1.5; }
    .wipe-ask .mono {
      font-family: var(--mono); font-size: 12px; font-weight: 700;
      background: var(--line-soft); border-radius: 6px; padding: 1px 7px; user-select: all;
    }
    .wipe-input {
      width: 100%; box-sizing: border-box; font-family: var(--mono); font-size: 13px;
      padding: 9px 12px; border: 1px solid var(--line); border-radius: 9px;
      margin-top: 8px; outline: none; background: #fff;
    }
    .wipe-input:focus { border-color: var(--danger); box-shadow: 0 0 0 3px var(--danger-soft); }
    .wipe-tip { font-size: 12px; color: var(--muted); margin-top: 12px; }
    .btn-danger-solid { background: var(--danger); border-color: var(--danger); color: #fff; }
    .btn-danger-solid:hover { background: #dc2626; border-color: #dc2626; color: #fff; }
    .del-cid {
      font-family: var(--mono); font-size: 12.5px; font-weight: 700; color: var(--ink);
      background: var(--line-soft); border-radius: 8px; padding: 6px 10px; margin-top: 10px;
      display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .del-info { color: var(--muted); font-size: 12.5px; margin-top: 6px; }
    .dialog-actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 16px; }

    .zip-tree {
      font-family: var(--mono); font-size: 11px; color: var(--muted);
      background: var(--line-soft); border-radius: 10px; padding: 10px 12px; margin-top: 10px;
      line-height: 1.55; white-space: pre; overflow-x: auto;
    }

    /* ---- Costs dialog (the tiles, the note, "Show more" and the tables: app.css,
            shared with the costs card of the settings) ---- */
    .usage-dialog { width: min(860px, 100%); }
    .usage-model { margin: 12px 0 14px; }
    .usage-table-wrap { overflow-x: auto; }
    .usage-scroll { max-height: 300px; overflow: auto; border: 1px solid var(--line-soft); border-radius: 10px; }
    .usage-scroll .cost-table { margin-top: 0; }
    .usage-scroll .cost-table th { position: sticky; top: 0; z-index: 1; background: #fff; }
    .usage-scroll .cost-table th:first-child, .usage-scroll .cost-table td:first-child { padding-left: 12px; }
    .cost-table.convs tbody tr { cursor: pointer; }
    .cost-table.convs tbody tr:hover td { background: var(--line-soft); }
    .cost-table.convs th, .cost-table.convs td { white-space: nowrap; }
    .cost-table.convs th:nth-child(2), .cost-table.convs td:nth-child(2) { text-align: left; }
    .cost-table.convs td:first-child { font-family: var(--mono); font-size: 12.5px; }
    .cost-table.convs .badge-test { font-family: var(--font); font-size: 10px; padding: 1px 7px; margin-left: 6px; }
    .usage-notes { margin: 0; padding-left: 18px; color: var(--ink-2); font-size: 13px; line-height: 1.55; }
    .usage-notes li + li { margin-top: 6px; }

    @media (max-width: 760px) {
      .sidebar { width: 230px; }
    }
    /* Phones: one pane at a time. The list comes first; a tap on a conversation opens
       its transcript, and "All conversations" goes back to the list. */
    @media (max-width: 640px) {
      .topbar { gap: 8px; padding: 0 12px; }
      .topbar .brand, #projectBadge, .topbar .tb-label { display: none; }
      .sidebar { width: 100%; border-right: 0; }
      .data-main.reading .sidebar, .data-main:not(.reading) .viewer { display: none; }
      .viewer-head { padding: 8px 14px 12px; gap: 8px 10px; }
      .viewer-back { display: inline-flex; flex-basis: 100%; justify-content: flex-start; margin-left: -10px; }
      .viewer-head .spacer { flex: 1; }
      .transcript-col { padding: 0 14px; }
    }
    @media (hover: none) { /* touch screens have no hover: the delete button stays visible */
      .conv-wrap .conv-del { display: inline-flex; }
      .conv-wrap .conv-item { padding-right: 42px; }
    }
    @media (max-width: 400px) { .tb-label-xs { display: none; } }
  </style>
</head>
<body>

<header class="topbar">
  <?php if ($has_session): ?>
    <a class="btn btn-sm btn-ghost" style="flex: 0 0 auto; margin-right: 2px;"
       href="settings.php?project=<?= urlencode($project_id) ?><?= $url_password !== '' ? '&password=' . urlencode($url_password) : '' ?>"
       title="Go back to the settings of this study.">
      <i class="fas fa-arrow-left"></i>&nbsp;Settings
    </a>
  <?php endif; ?>
  <a class="brand" href="projects.php">
    <img class="brand-logo" src="assets/img/maestro-logo.svg" alt="MAESTRO">
  </a>
  <div class="topbar-context">
    <span class="badge badge-accent" id="projectBadge"><?= htmlspecialchars(($project['name'] !== '' ? $project['name'] : 'Untitled study') . ' · ' . $project_id) ?></span>
  </div>
  <div class="spacer"></div>
  <nav class="row" style="gap:8px;">
    <?php if ($share_link !== ''): ?>
      <button class="btn btn-sm btn-ghost" id="shareLinkBtn" type="button" title="Copies a link for collaborators. The link opens this data page, but not the settings or the password.">
        <i class="fas fa-link"></i><span class="tb-label"> Copy share link</span>
      </button>
    <?php endif; ?>
    <button class="btn btn-sm" id="usageOpenBtn" type="button" title="Shows what this study has cost so far, in total and per conversation." aria-haspopup="dialog">
      <i class="fas fa-coins"></i><span class="tb-label"> Costs</span>
    </button>
    <button class="btn btn-sm btn-primary" id="exportOpenBtn" type="button">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none" style="margin-right:6px;"><path d="M8 1v9m0 0L4.5 6.5M8 10l3.5-3.5M2 12.5V14h12v-1.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Export<span class="tb-label-xs"> data</span>
    </button>
  </nav>
</header>

<div class="data-main">
  <aside class="sidebar">
    <div class="sidebar-head">
      <div class="search"><input id="searchInput" type="search" placeholder="Filter conversations…"></div>
      <div class="sidebar-meta">
        <span class="count" id="convCount">–</span>
        <button class="btn btn-sm btn-ghost" id="reloadBtn" type="button"><i class="fas fa-sync-alt"></i> Reload</button>
      </div>
    </div>
    <div class="conv-list scroll-slim" id="convList">
      <div class="conv-empty">Loading…</div>
    </div>
    <?php if ($has_session): ?>
      <div class="sidebar-foot">
        <button class="btn btn-sm btn-ghost-danger" id="wipeAllBtn" type="button" title="Deletes every recorded conversation of this study, after a confirmation.">
          <i class="fas fa-trash-alt"></i>&nbsp; Delete all data…
        </button>
      </div>
    <?php endif; ?>
  </aside>

  <section class="viewer">
    <div class="viewer-head hidden" id="viewerHead">
      <button class="btn btn-sm btn-ghost viewer-back" id="viewerBackBtn" type="button"><i class="fas fa-arrow-left"></i>&nbsp;All conversations</button>
      <span class="viewer-title" id="viewerTitle"></span>
      <div class="stats" id="statsRow"></div>
      <span class="spacer"></span>
      <button class="btn btn-sm" id="exportThisBtn" type="button" title="Opens the export options for this conversation.">Export…</button>
      <?php if ($has_session): ?>
        <button class="btn btn-sm btn-danger" id="deleteThisBtn" type="button" title="Deletes this conversation, after a confirmation.">Delete…</button>
      <?php endif; ?>
    </div>
    <div class="transcript scroll-slim hidden" id="transcript">
      <div class="transcript-col" id="transcriptCol"></div>
    </div>
    <div class="viewer-empty" id="viewerEmpty">You can select a conversation on the left to read its transcript.</div>
  </section>
</div>

<!-- ---- Costs dialog: three results first, the details behind "Show more", like the
     costs card in Step 4 of the settings (assets/js/spending.js writes both) ---- -->
<div class="overlay" id="usageModal" aria-hidden="true">
  <div class="dialog usage-dialog" role="dialog" aria-modal="true" aria-labelledby="usageTitle">
    <div class="dialog-head">
      <div>
        <div class="dialog-title" id="usageTitle">Costs</div>
        <div class="dialog-sub">What this study has cost so far at the provider of the AI model.</div>
      </div>
      <button class="dialog-close" id="usageCloseBtn" type="button" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <p class="cost-model usage-model" id="usageModel"></p>

    <div class="cost-results">
      <div class="cost-stat cs-per">
        <span class="cs-label" id="usagePerLabel">Cost per participant</span>
        <span class="cs-value" id="usagePer"></span>
        <span class="cs-sub" id="usagePerSub"></span>
      </div>
      <div class="cost-stat cs-estimate">
        <span class="cs-label">Participants so far</span>
        <span class="cs-value" id="usageReal"></span>
        <span class="cs-sub" id="usageRealSub"></span>
      </div>
      <div class="cost-stat">
        <span class="cs-label">Spent so far</span>
        <span class="cs-value" id="usageTotal"></span>
        <span class="cs-sub" id="usageTotalSub"></span>
      </div>
    </div>
    <p class="cost-note"><i class="fas fa-info-circle"></i><span id="usageNote"></span></p>

    <div class="hidden" id="usageMore">
      <div class="card-part">
        <h4 class="part-title">What the money was spent on</h4>
        <div class="usage-table-wrap" id="usageBreakdown"></div>
      </div>
      <div class="card-part">
        <h4 class="part-title">Per conversation</h4>
        <p class="part-lead">You can click a conversation to open its transcript.</p>
        <div class="usage-scroll scroll-slim"><table class="cost-table convs" id="usageConvs"></table></div>
      </div>
      <div class="card-part">
        <h4 class="part-title">How the costs come about</h4>
        <ul class="usage-notes" id="usageNotes"></ul>
      </div>
    </div>
    <div class="show-more-row">
      <button class="show-more-btn" id="usageMoreBtn" type="button" aria-expanded="false" aria-controls="usageMore">
        <span id="usageMoreLabel">Show more</span> <i class="fas fa-chevron-down"></i>
      </button>
    </div>
  </div>
</div>

<!-- ---- Export dialog ---------------------------------------------------------- -->
<div class="overlay" id="exportModal" aria-hidden="true">
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="exportTitle">
    <div class="dialog-head">
      <div>
        <div class="dialog-title" id="exportTitle">Export data</div>
        <div class="dialog-sub"><?= htmlspecialchars($project['name'] !== '' ? $project['name'] : 'Untitled study') ?> · <span style="font-family:var(--mono);"><?= htmlspecialchars($project_id) ?></span></div>
      </div>
      <button class="dialog-close" id="exportCloseBtn" type="button" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>

    <!-- Transcripts -->
    <section class="export-section">
      <h3><span class="emoji"><i class="fas fa-comments"></i></span> Conversation transcripts</h3>
      <p>You can download the chats as a CSV table (a text file that spreadsheet programs open) or as a PDF that looks like the chat, with the team colors.</p>
      <div class="export-controls">
        <div class="seg" id="segScope">
          <button type="button" data-scope="current">This conversation</button>
          <button type="button" data-scope="all">All conversations</button>
        </div>
        <div class="seg" id="segFormat">
          <button type="button" data-format="csv">CSV</button>
          <button type="button" data-format="pdf">PDF</button>
        </div>
        <button class="btn btn-primary btn-sm export-go" id="transcriptGo" type="button">Download</button>
      </div>
      <div class="export-hint" id="transcriptHint"></div>
    </section>

    <!-- Participant overview -->
    <section class="export-section">
      <h3><span class="emoji"><i class="fas fa-chart-bar"></i></span> Participant overview</h3>
      <p>A CSV table with one row per participant: start and end time, duration, message counts, tokens (the billing units of text) and estimated costs. The table is made to be matched with your survey data. You can add text columns:</p>
      <label class="opt">
        <input type="checkbox" id="optHumanCol" checked>
        <span>Participant messages in one column
          <span class="opt-sub">Only the messages the participant typed, separated by <span class="mono">||</span>.</span>
        </span>
      </label>
      <label class="opt">
        <input type="checkbox" id="optTranscriptCol">
        <span>Full transcript in one column
          <span class="opt-sub">Every message with the name of its sender, separated by <span class="mono">||</span>.</span>
        </span>
      </label>
      <div class="warn hidden" id="transcriptWarn">
        ⚠ The whole conversation ends up in a single cell. For long sessions, the CSV file becomes very large,
        and some programs read the file incorrectly (Excel cuts cells off at 32,767 characters).
      </div>
      <div class="export-controls">
        <span class="export-hint" id="overviewHint" style="margin-top:0;"></span>
        <button class="btn btn-primary btn-sm export-go" id="overviewGo" type="button">Download CSV</button>
      </div>
    </section>

    <!-- Complete archive -->
    <section class="export-section">
      <h3><span class="emoji"><i class="fas fa-archive"></i></span> Complete archive (ZIP)</h3>
      <p>Everything in one download, including the study configuration: the AI provider and model, the conductor instructions, the study context, the sample conversation with its segments, the instructions for participants and the session settings. API keys are not included.</p>
      <div class="zip-tree" id="zipTree">project_<?= htmlspecialchars($project_id) ?>_…/
├─ configuration/   project_configuration.json · director_instructions.txt
│                   participant_instructions.txt · chat_instructions.html
│                   study_context.txt · conversation_script.json
├─ participant_overview.csv   with tokens and estimated costs per participant
├─ all_messages.csv
├─ director_events.csv   one row per conductor request: timing, status, tokens, unanswered messages, segment starts
└─ conversations/   one CSV and one PDF per conversation</div>
      <div class="export-controls">
        <span class="export-hint" id="zipHint" style="margin-top:0;"></span>
        <button class="btn btn-primary btn-sm export-go" id="zipGo" type="button">Download ZIP</button>
      </div>
    </section>
  </div>
</div>

<!-- ---- Delete confirmation dialog ---------------------------------------------- -->
<div class="overlay" id="deleteModal" aria-hidden="true">
  <div class="dialog" role="alertdialog" aria-modal="true" aria-labelledby="deleteTitle" style="width:min(460px,100%);">
    <div class="dialog-head">
      <div>
        <div class="dialog-title" id="deleteTitle">Delete this conversation?</div>
      </div>
      <button class="dialog-close" id="deleteCloseBtn" type="button" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <span class="del-cid" id="delCid"></span>
    <div class="del-info" id="delInfo"></div>
    <div class="warn warn-danger" style="margin-top:13px;">
      <strong>This cannot be undone.</strong> All messages of this conversation are permanently
      removed from the stored data and disappear from every export.
    </div>
    <div class="dialog-actions">
      <button class="btn btn-sm" id="deleteCancelBtn" type="button">Cancel</button>
      <button class="btn btn-sm btn-danger-solid" id="deleteConfirmBtn" type="button">Delete permanently</button>
    </div>
  </div>
</div>

<!-- ---- Delete-ALL-data confirmation dialog -------------------------------------- -->
<div class="overlay" id="wipeModal" aria-hidden="true">
  <div class="dialog" role="alertdialog" aria-modal="true" aria-labelledby="wipeTitle" style="width:min(520px,100%);">
    <div class="dialog-head">
      <div>
        <div class="dialog-title" id="wipeTitle">Delete all data of this study?</div>
        <div class="dialog-sub"><?= htmlspecialchars($project['name'] !== '' ? $project['name'] : 'Untitled study') ?> · <span style="font-family:var(--mono);"><?= htmlspecialchars($project_id) ?></span></div>
      </div>
      <button class="dialog-close" id="wipeCloseBtn" type="button" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <div class="del-info" id="wipeInfo"></div>
    <div class="warn warn-danger" style="margin-top:12px;">
      <strong>This cannot be undone.</strong> This permanently deletes <strong>every conversation of
      every participant</strong> in this study: all messages and the records of what the conductor did.
      The deleted conversations disappear from all exports. The study itself (settings, sample conversation, team,
      instructions) is kept and stays usable.
    </div>
    <div class="wipe-tip"><i class="fas fa-lightbulb"></i>&nbsp; You can download the complete ZIP archive first if you might need the data again.</div>
    <p class="wipe-ask">To confirm, type the study ID <span class="mono"><?= htmlspecialchars($project_id) ?></span> into the field below:</p>
    <input class="wipe-input" id="wipeConfirmInput" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Type the study ID to enable the button">
    <div class="dialog-actions">
      <button class="btn btn-sm" id="wipeCancelBtn" type="button">Cancel</button>
      <button class="btn btn-sm btn-danger-solid" id="wipeGoBtn" type="button" disabled>Delete all data permanently</button>
    </div>
  </div>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script type="module">
// A module, so that the costs dialog can share its parts with the settings page.
import { formatUsd, formatTokens, countText } from './assets/js/format.js';
import { spendingParts, amountOf, showAmount, partsText, costCellText, spendingTable, priceBasisText } from './assets/js/spending.js';

(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);

  // Everyone in the chat: names, colors and pictures for every row. Here the
  // participant appears under their name (the chat itself shows "You").
  AIT.setTeam(<?= json_encode(team_roles_map(team_all($project), 'researcher'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>);

  const PROJECT_ID = <?= json_encode($project_id) ?>;
  const SHARE_KEY = <?= json_encode($share_key) ?>;
  const SHARE_LINK = <?= json_encode($share_link, JSON_UNESCAPED_SLASHES) ?>; // for collaborators ('' when not allowed)
  const CAN_DELETE = <?= json_encode($has_session) ?>;

  const API = 'api/data.php';
  function apiUrl(action, extra) {
    let url = API + '?action=' + action + '&project=' + encodeURIComponent(PROJECT_ID);
    if (SHARE_KEY) url += '&key=' + encodeURIComponent(SHARE_KEY);
    if (extra) url += extra;
    return url;
  }

  let conversations = [];
  let listLoaded = false;
  let currentId = null;
  // The tokens and costs come from a second request (action=usage), which takes a
  // while in large studies; the list and the transcript show without them first.
  let studyUsage = null;   // the study totals, null while loading
  let usageByConv = null;  // conversation id → tokens and costs, null while loading
  let usageError = '';
  let loadSeq = 0;         // only the latest load may fill in its costs
  const NO_CALLS = { calls: 0 };
  /** The tokens and costs of a conversation, or null while they are loading. */
  function usageOf(c) {
    return usageByConv ? usageByConv[c.conversation_id] || NO_CALLS : null;
  }
  function usageLoading() { return !usageByConv && !usageError; }
  function smallSpinner(title) {
    const s = AIT.el('span', 'spinner spinner-xs');
    s.title = title;
    return s;
  }

  // ---- Tokens and costs ----------------------------------------------------------
  // (the amounts are written as on the settings page: assets/js/format.js)
  const fmtTokens = formatTokens;
  const fmtUsd = formatUsd;
  /** "≈" in front of numbers that contain estimates. */
  function approx(u) { return u && u.estimated_calls > 0 ? '≈ ' : ''; }

  function fmtDate(ts) {
    if (!ts) return '';
    return ts.substring(0, 16) + ' UTC'; // YYYY-MM-DD HH:MM; the chat stores its times in UTC
  }
  function durationText(first, last) {
    try {
      const a = new Date(first.replace(' ', 'T'));
      const b = new Date(last.replace(' ', 'T'));
      const s = Math.max(0, Math.round((b - a) / 1000));
      const m = Math.floor(s / 60);
      return m + ':' + String(s % 60).padStart(2, '0') + ' min';
    } catch (_) { return ''; }
  }

  // ---- Conversation list ------------------------------------------------------
  function renderList() {
    const filter = $('searchInput').value.trim().toLowerCase();
    const host = $('convList');
    host.innerHTML = '';
    const filtered = conversations.filter((c) => !filter || c.conversation_id.toLowerCase().includes(filter));
    $('convCount').textContent = filtered.length + ' conversation' + (filtered.length === 1 ? '' : 's');
    if (!filtered.length) {
      host.innerHTML = '<div class="conv-empty">' + (conversations.length ? 'No conversation matches the filter.' : 'No conversations have been recorded yet.<br><br>You can open the test link from the settings page to create a test conversation.') + '</div>';
      return;
    }
    filtered.forEach((c) => {
      const btn = AIT.el('button', 'conv-item' + (c.conversation_id === currentId ? ' active' : ''));
      btn.type = 'button';
      const cid = AIT.el('div', 'cid', c.conversation_id);
      const meta = AIT.el('div', 'cmeta');
      meta.appendChild(AIT.el('span', null, fmtDate(c.first_at)));
      meta.appendChild(AIT.el('span', null, '· ' + countText(Number(c.message_count), 'message')));
      meta.appendChild(AIT.el('span', null, '· ' + c.human_count + ' from participant'));
      const u = usageOf(c);
      if (usageLoading()) {
        meta.append('·', smallSpinner('Loading the cost of this conversation.'));
      } else if (u && u.calls && u.cost !== null) {
        const cost = AIT.el('span', null, '· ' + approx(u) + fmtUsd(u.cost));
        cost.title = fmtTokens(u.input) + ' input tokens and ' + fmtTokens(u.output) + ' output tokens (the billing units of text)';
        meta.appendChild(cost);
      }
      if (c.is_test) {
        const b = AIT.el('span', 'badge badge-test', 'TEST');
        b.style.fontSize = '10px';
        b.style.padding = '1px 7px';
        meta.appendChild(b);
      }
      if (c.director_failed > 0) {
        const b = AIT.el('span', 'badge badge-warn', '⚠ ' + c.director_failed + ' unanswered');
        b.title = c.director_failed + ' participant message' + (c.director_failed === 1 ? '' : 's') + ' got no reaction from the team, because the conductor (the model that adapts the conversation during the chat) failed.';
        b.style.fontSize = '10px';
        b.style.padding = '1px 7px';
        meta.appendChild(b);
      }
      if (c.segments_failed > 0) {
        const b = AIT.el('span', 'badge badge-warn', '⚠ ' + c.segments_failed + ' segment start' + (c.segments_failed === 1 ? '' : 's') + ' failed');
        b.title = 'The conductor failed at ' + (c.segments_failed === 1 ? 'a segment start' : c.segments_failed + ' segment starts') + ', so the team stayed silent from that moment until the participant wrote again.';
        b.style.fontSize = '10px';
        b.style.padding = '1px 7px';
        meta.appendChild(b);
      }
      btn.append(cid, meta);
      btn.addEventListener('click', () => { selectConversation(c.conversation_id); showTranscriptPane(true); });
      if (!CAN_DELETE) {
        host.appendChild(btn);
        return;
      }
      const wrap = AIT.el('div', 'conv-wrap');
      const del = AIT.el('button', 'conv-del');
      del.type = 'button';
      del.title = 'Delete this conversation…';
      del.innerHTML = '<i class="far fa-trash-alt"></i>';
      del.addEventListener('click', (e) => {
        e.stopPropagation();
        openDeleteConfirm(c.conversation_id);
      });
      wrap.append(btn, del);
      host.appendChild(wrap);
    });
  }

  async function loadList(keepSelection) {
    const seq = ++loadSeq;
    loadUsage(seq); // at the same time as the list, not after it
    try {
      const data = await AIT.getJSON(apiUrl('list'));
      if (seq !== loadSeq) return;
      conversations = data.conversations || [];
      listLoaded = true;
      renderList();
      if ($('usageModal').classList.contains('open')) renderUsage();
      if (!keepSelection || !conversations.some((c) => c.conversation_id === currentId)) {
        if (conversations.length) selectConversation(conversations[0].conversation_id);
        else { currentId = null; syncExportState(); }
      } else {
        syncExportState();
      }
    } catch (e) {
      $('convList').innerHTML = '<div class="conv-empty">The conversations could not be loaded: ' + e.message + '</div>';
    }
  }

  /** Loads the tokens and costs and fills them into the list, the open transcript and the costs dialog. */
  function loadUsage(seq) {
    studyUsage = null;
    usageByConv = null;
    usageError = '';
    showUsage(); // spinners in place of the old amounts
    AIT.getJSON(apiUrl('usage')).then((data) => {
      if (seq !== loadSeq) return;
      studyUsage = data.usage || null;
      usageByConv = data.per_conversation || {};
      showUsage();
    }, (e) => {
      if (seq !== loadSeq) return;
      usageError = e.message || 'unknown error';
      showUsage();
    });
  }
  function showUsage() {
    if (listLoaded) renderList(); // before the list arrives, the list keeps its "Loading…"
    renderCostChips();
    if ($('usageModal').classList.contains('open')) renderUsage();
  }

  // ---- Transcript ----------------------------------------------------------------
  /** A chip in the head of the transcript. */
  function chip(text, dotColor, cls) {
    const c = AIT.el('span', 'stat-chip' + (cls ? ' ' + cls : ''));
    if (dotColor) {
      const d = AIT.el('span', 'dot');
      d.style.background = dotColor;
      c.appendChild(d);
    }
    c.appendChild(document.createTextNode(text));
    $('statsRow').appendChild(c);
    return c;
  }
  /** The tokens and costs of the open conversation after its other chips: a spinner until they are loaded. */
  function renderCostChips() {
    const stats = $('statsRow');
    if (!currentId || stats.dataset.cid !== currentId) return; // the transcript is still loading
    stats.querySelectorAll('.cost-chip').forEach((c) => c.remove());
    if (usageLoading()) {
      chip('Loading the tokens and costs', null, 'cost-chip loading').prepend(smallSpinner(''));
      return;
    }
    if (usageError) {
      chip('The tokens and costs could not be loaded', null, 'cost-chip loading');
      return;
    }
    const info = conversations.find((c) => c.conversation_id === currentId);
    const u = info && usageOf(info);
    if (u && u.calls) {
      chip(approx(u) + fmtTokens(u.input) + ' input · ' + fmtTokens(u.output) + ' output tokens', null, 'cost-chip');
      if (u.cost !== null) chip(approx(u) + fmtUsd(u.cost), null, 'cost-chip');
    }
  }

  // Phones show one pane at a time (see the styles): the list, or the transcript a tap opened.
  const dataMain = document.querySelector('.data-main');
  function showTranscriptPane(on) { dataMain.classList.toggle('reading', on); }
  $('viewerBackBtn').addEventListener('click', () => showTranscriptPane(false));

  async function selectConversation(id) {
    currentId = id;
    renderList();
    syncExportState();
    $('viewerEmpty').classList.add('hidden');
    $('viewerHead').classList.remove('hidden');
    $('transcript').classList.remove('hidden');
    $('viewerTitle').textContent = id;
    $('statsRow').innerHTML = '';
    delete $('statsRow').dataset.cid;
    const col = $('transcriptCol');
    col.innerHTML = '<div class="chat-divider">Loading…</div>';

    try {
      const data = await AIT.getJSON(apiUrl('messages', '&conversation_id=' + encodeURIComponent(id)));
      if (id !== currentId) return; // another conversation was selected in the meantime
      const messages = data.messages || [];
      const director = data.director || { calls: 0, failed: 0, by_message: {} };
      col.innerHTML = '';

      const info = conversations.find((c) => c.conversation_id === id);
      const humanCount = messages.filter((m) => m.sender === 'human').length;
      chip(countText(messages.length, 'message'));
      chip(humanCount + ' from participant', 'var(--yellow)');
      chip((messages.length - humanCount) + ' from team');
      if (info) chip(durationText(info.first_at, info.last_at));
      if (director.calls) chip(director.calls + ' conductor request' + (director.calls === 1 ? '' : 's'));
      if (director.failed) chip('⚠ ' + director.failed + ' unanswered', null, 'warn');
      if (director.segments_failed) chip('⚠ ' + director.segments_failed + ' segment start' + (director.segments_failed === 1 ? '' : 's') + ' failed', null, 'warn');
      $('statsRow').dataset.cid = id;
      renderCostChips();

      if (!messages.length) {
        col.innerHTML = '<div class="chat-divider">This conversation has no messages.</div>';
        return;
      }
      const divider = AIT.el('div', 'chat-divider', fmtDate(messages[0].shown_at));
      col.appendChild(divider);

      let prevSender = null;
      let prevTime = 0;
      messages.forEach((m) => {
        const t = new Date((m.shown_at || '').replace(' ', 'T')).getTime() || 0;
        const grouped = prevSender === m.sender && (t - prevTime) < 90000;
        const row = AIT.messageRow({
          role: m.sender,
          // The keystroke count at the end of a participant message ("… [[//12]]") is
          // analytics data: the exports keep it, the transcript shows the text (as the PDF).
          text: m.sender === 'human' ? String(m.message_text || '').replace(/\s*\[\[\/\/\d+\]\]\s*$/, '') : m.message_text,
          time: m.shown_at,
          grouped,
        });
        // A participant message the team never reacted to (the director failed).
        const outcome = m.sender === 'human' && m.client_id ? (director.by_message || {})[m.client_id] : null;
        if (outcome && outcome.status === 'failed') {
          const note = AIT.el('div', 'msg-note');
          note.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
          note.appendChild(document.createTextNode('The team did not react to this message, because the conductor failed' + (outcome.error ? ' (' + outcome.error + ')' : '') + '. The sample conversation continued as written.'));
          row.querySelector('.msg-body').appendChild(note);
        }
        col.appendChild(row);
        prevSender = m.sender;
        prevTime = t;
      });
      $('transcript').scrollTop = 0;
    } catch (e) {
      col.innerHTML = '<div class="chat-divider">The transcript could not be loaded: ' + e.message + '</div>';
    }
  }

  // ---- Costs dialog -------------------------------------------------------------------
  // The same three tiles, note and "Show more" as the costs card in Step 4 of
  // the settings, for what was actually spent: the cost per participant, the
  // conversations with participants, and everything (assets/js/spending.js).
  const usageModal = $('usageModal');
  function tableHead(cols) {
    const thead = document.createElement('thead');
    const tr = document.createElement('tr');
    cols.forEach((c) => tr.appendChild(AIT.el('th', null, c)));
    thead.appendChild(tr);
    return thead;
  }
  function renderUsage() {
    const u = studyUsage;
    if (!u || !listLoaded) { // the amounts (or the list they are split by) are still loading
      ['usagePer', 'usageReal', 'usageTotal'].forEach((id) => {
        $(id).innerHTML = '';
        if (usageError) $(id).textContent = '–';
        else $(id).appendChild(AIT.el('span', 'spinner'));
      });
      ['usagePerSub', 'usageRealSub', 'usageTotalSub'].forEach((id) => { $(id).textContent = ''; });
      $('usageModel').innerHTML = '';
      ['usageBreakdown', 'usageConvs', 'usageNotes'].forEach((id) => { $(id).innerHTML = ''; });
      $('usageNote').textContent = usageError
        ? 'The amounts could not be loaded (' + usageError + '). You can click “Reload” in the list to try again.'
        : 'Loading the amounts. In studies with many conversations, the amounts take a few seconds.';
      return;
    }
    const parts = spendingParts(u, conversations);

    // The model or models that were used
    const model = $('usageModel');
    model.innerHTML = '';
    model.appendChild(AIT.el('span', 'cm-label', u.models.length > 1 ? 'Models' : 'Model'));
    (u.models.length ? u.models : ['none used yet']).forEach((m) => model.appendChild(AIT.el('span', 'cm-name mono', m)));

    // 1 · per participant: the average of the conversations with participants,
    //     and before there are any, the average of the test conversations
    if (parts.real.count) {
      $('usagePerLabel').textContent = 'Cost per participant';
      showAmount($('usagePer'), amountOf(parts.real, parts.real.count));
      $('usagePerSub').textContent = 'The average of ' + countText(parts.real.count, 'conversation') + ' with participants.';
    } else if (parts.tests.count) {
      $('usagePerLabel').textContent = 'Cost per test conversation';
      showAmount($('usagePer'), amountOf(parts.tests, parts.tests.count));
      $('usagePerSub').textContent = 'The average of your ' + countText(parts.tests.count, 'test conversation') + '. There are no conversations with participants yet.';
    } else {
      $('usagePerLabel').textContent = 'Cost per participant';
      $('usagePer').textContent = '–';
      $('usagePerSub').textContent = 'No conversations have been recorded yet.';
    }
    // 2 · the conversations with participants together
    if (parts.real.count || parts.real.calls) showAmount($('usageReal'), amountOf(parts.real));
    else $('usageReal').textContent = '–';
    $('usageRealSub').textContent = parts.real.count
      ? 'The total of all ' + countText(parts.real.count, 'conversation') + ' with participants.'
      : 'No participants yet. Test conversations are not counted here.';
    // 3 · everything this study has sent to the AI model
    showAmount($('usageTotal'), amountOf(u.total));
    const besides = partsText(parts, 'in the settings', { real: false });
    $('usageTotalSub').textContent = !u.total.calls ? 'Nothing has been spent yet.'
      : besides ? 'This includes ' + besides + '.' : 'The whole amount went to conversations with participants.';
    $('usageNote').textContent = 'These amounts are estimates. ' + priceBasisText(u);

    // Behind "Show more": what the money was spent on, every conversation, and how the costs come about
    const breakdown = $('usageBreakdown');
    breakdown.innerHTML = '';
    breakdown.appendChild(spendingTable(parts, 'AI Assistant in the settings'));

    const withPrice = u.total.priced_calls > 0 || !u.total.calls; // money, unless nothing has a price
    const table = $('usageConvs');
    table.innerHTML = '';
    table.appendChild(tableHead(['Conversation', 'Started', 'Participant messages', withPrice ? 'Cost' : 'Tokens']));
    const body = document.createElement('tbody');
    conversations.forEach((c) => {
      const cu = usageOf(c) || NO_CALLS;
      const tr = document.createElement('tr');
      tr.title = 'Opens this conversation.';
      const id = AIT.el('td', null, c.conversation_id);
      if (c.is_test) id.appendChild(AIT.el('span', 'badge badge-test', 'TEST'));
      tr.append(id, AIT.el('td', null, fmtDate(c.first_at)), AIT.el('td', null, String(c.human_count)),
        AIT.el('td', null, withPrice ? costCellText(cu) : (cu.calls ? formatTokens(cu.input + cu.output) : '–')));
      tr.addEventListener('click', () => { closeUsage(); selectConversation(c.conversation_id); showTranscriptPane(true); });
      body.appendChild(tr);
    });
    if (!conversations.length) {
      const tr = document.createElement('tr');
      const td = AIT.el('td', null, 'No conversations have been recorded yet.');
      td.colSpan = 4;
      tr.appendChild(td);
      body.appendChild(tr);
    }
    table.appendChild(body);

    const notes = $('usageNotes');
    notes.innerHTML = '';
    const note = (text) => notes.appendChild(AIT.el('li', null, text));
    note('The provider of the AI model charges per token, the billing unit of text. A token is a piece of a word, and 1,000 tokens are about 750 English words.');
    note('Every time a participant writes a message, the conductor (the model that adapts the conversation during the chat) reads the instructions, the sample conversation and the chat so far (input tokens) ' +
      'and writes the rest of the conversation (output tokens). Output tokens cost more than input tokens. Every segment start adds one more such request.');
    note('The AI Assistant in the settings uses the model as well, when the assistant writes the sample conversation or the conductor instructions for you.');
    if (u.total.cached > 0 || u.total.cache_write > 0) {
      note('With OpenAI models, the part of the input that stays the same, mainly the conductor instructions, is read from a cache (a short-term memory at the provider) at a much lower price, ' +
        'and new parts are written to the cache at a slightly higher price. The amounts include both prices.');
    }
    if (u.total.estimated_calls > 0) {
      const one = u.total.estimated_calls === 1;
      note(countText(u.total.estimated_calls, 'request') + (one ? ' has' : ' have') + ' no exact token count, because ' + (one ? 'it was' : 'they were') +
        ' made before the tool counted tokens (24 September 2026) or because the participant wrote again before the answer was complete. ' +
        'The tokens are estimated from the texts, and amounts that include such estimates are marked “about” here and “≈” in the conversation list.');
    }
    if (u.total.unpriced_calls > 0) {
      note('Requests through a custom gateway (a server address you enter instead of the provider\'s) have no price here, because your contract with the gateway decides what these requests cost. The tokens of these requests are still counted.');
    }
    note('The participant overview under “Export data” lists the tokens and the costs of every participant, so that you can match them with your survey data.');
  }
  $('usageMoreBtn').addEventListener('click', () => {
    const open = $('usageMore').classList.toggle('hidden') === false;
    $('usageMoreBtn').classList.toggle('open', open);
    $('usageMoreBtn').setAttribute('aria-expanded', String(open));
    $('usageMoreLabel').textContent = open ? 'Show less' : 'Show more';
  });
  function openUsage() {
    renderUsage();
    usageModal.classList.add('open');
    usageModal.setAttribute('aria-hidden', 'false');
  }
  function closeUsage() {
    usageModal.classList.remove('open');
    usageModal.setAttribute('aria-hidden', 'true');
  }
  $('usageOpenBtn').addEventListener('click', openUsage);
  $('usageCloseBtn').addEventListener('click', closeUsage);
  usageModal.addEventListener('click', (e) => { if (e.target === usageModal) closeUsage(); });

  // ---- Export dialog --------------------------------------------------------------
  const modal = $('exportModal');
  const exportState = { scope: 'current', format: 'csv' };

  function openExport(preferScope) {
    if (preferScope) exportState.scope = preferScope;
    syncExportState();
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
  }
  function closeExport() {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
  }

  function segButtons(segId) {
    return Array.from($(segId).querySelectorAll('button'));
  }

  function syncExportState() {
    const total = conversations.length;
    const hasCurrent = !!currentId;

    if (!hasCurrent && exportState.scope === 'current') exportState.scope = 'all';

    segButtons('segScope').forEach((b) => {
      const scope = b.dataset.scope;
      b.disabled = scope === 'current' && !hasCurrent;
      b.classList.toggle('active', exportState.scope === scope);
    });
    segButtons('segFormat').forEach((b) => {
      b.classList.toggle('active', exportState.format === b.dataset.format);
    });

    // Hint under the transcript controls
    const hint = $('transcriptHint');
    if (!total) {
      hint.textContent = 'No conversations have been recorded yet.';
    } else if (exportState.scope === 'current') {
      const label = '<span class="mono">' + escapeHtml(shorten(currentId, 34)) + '</span>';
      hint.innerHTML = (exportState.format === 'csv'
        ? 'One row per message of ' + label + '.'
        : 'A PDF of ' + label + ' that looks like the chat, with a cover page.');
    } else {
      hint.textContent = exportState.format === 'csv'
        ? 'One table with every message of all ' + total + ' conversations.'
        : 'One PDF with a cover page and all ' + total + ' conversations, each on a new page.';
    }

    $('transcriptGo').disabled = !total;
    $('overviewGo').disabled = !total;
    $('overviewHint').textContent = total
      ? (total === 1 ? 'One row for the single recorded conversation.' : 'One row for each of the ' + total + ' recorded conversations.')
      : 'No conversations have been recorded yet.';
    $('zipHint').textContent = total
      ? total + ' conversation' + (total === 1 ? '' : 's') + ' and the configuration.'
      : 'No conversations yet, so the archive contains only the configuration.';
  }

  function shorten(s, n) { return s && s.length > n ? s.slice(0, n - 1) + '…' : (s || ''); }
  function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  function triggerDownload(url) {
    const a = document.createElement('a');
    a.href = url;
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
    AIT.toast('The download has started.');
  }

  $('exportOpenBtn').addEventListener('click', () => openExport(null));
  $('exportThisBtn').addEventListener('click', () => openExport('current'));
  $('exportCloseBtn').addEventListener('click', closeExport);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeExport(); });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (wipeModal.classList.contains('open')) closeWipe();
    else if (deleteModal.classList.contains('open')) closeDeleteConfirm();
    else if (usageModal.classList.contains('open')) closeUsage();
    else if (modal.classList.contains('open')) closeExport();
  });

  segButtons('segScope').forEach((b) => b.addEventListener('click', () => {
    if (b.disabled) return;
    exportState.scope = b.dataset.scope;
    syncExportState();
  }));
  segButtons('segFormat').forEach((b) => b.addEventListener('click', () => {
    exportState.format = b.dataset.format;
    syncExportState();
  }));

  $('transcriptGo').addEventListener('click', () => {
    let url;
    if (exportState.scope === 'current') {
      if (!currentId) return;
      const conv = '&conversation_id=' + encodeURIComponent(currentId);
      url = apiUrl(exportState.format === 'pdf' ? 'download_pdf' : 'download_csv', conv);
    } else {
      url = apiUrl(exportState.format === 'pdf' ? 'download_all_pdf' : 'download_all_csv');
    }
    triggerDownload(url);
  });
  // ---- Delete conversation ---------------------------------------------------------
  const deleteModal = $('deleteModal');
  let deleteTarget = null;

  function hideViewer() {
    showTranscriptPane(false);
    $('viewerHead').classList.add('hidden');
    $('transcript').classList.add('hidden');
    $('viewerEmpty').classList.remove('hidden');
  }

  function openDeleteConfirm(cid) {
    deleteTarget = cid;
    $('delCid').textContent = cid;
    const c = conversations.find((x) => x.conversation_id === cid);
    $('delInfo').textContent = c
      ? c.message_count + ' message' + (c.message_count === 1 ? '' : 's') + ' · ' + c.human_count + ' from the participant · started ' + fmtDate(c.first_at)
      : '';
    deleteModal.classList.add('open');
    deleteModal.setAttribute('aria-hidden', 'false');
  }
  function closeDeleteConfirm() {
    deleteModal.classList.remove('open');
    deleteModal.setAttribute('aria-hidden', 'true');
    deleteTarget = null;
  }

  $('deleteCloseBtn').addEventListener('click', closeDeleteConfirm);
  $('deleteCancelBtn').addEventListener('click', closeDeleteConfirm);
  deleteModal.addEventListener('click', (e) => { if (e.target === deleteModal) closeDeleteConfirm(); });

  const deleteThisBtn = $('deleteThisBtn');
  if (deleteThisBtn) {
    deleteThisBtn.addEventListener('click', () => { if (currentId) openDeleteConfirm(currentId); });
  }

  $('deleteConfirmBtn').addEventListener('click', async () => {
    if (!deleteTarget) return;
    const btn = $('deleteConfirmBtn');
    btn.disabled = true;
    btn.textContent = 'Deleting…';
    try {
      const data = await AIT.postJSON(apiUrl('delete_conversation'), { conversation_id: deleteTarget });
      AIT.toast('The conversation was deleted (' + countText(Number(data.deleted_messages), 'message') + ' removed).');
      if (currentId === deleteTarget) {
        currentId = null;
        hideViewer();
      }
      closeDeleteConfirm();
      loadList(!!currentId);
    } catch (e) {
      AIT.toast('The conversation could not be deleted: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Delete permanently';
    }
  });

  // ---- Delete ALL data of the study --------------------------------------------------
  const wipeModal = $('wipeModal');

  function openWipe() {
    const msgs = conversations.reduce((s, c) => s + c.message_count, 0);
    $('wipeInfo').textContent = conversations.length
      ? 'This study currently has ' + conversations.length + ' conversation' + (conversations.length === 1 ? '' : 's')
        + ' with ' + msgs + ' message' + (msgs === 1 ? '' : 's') + '.'
      : 'No conversations are recorded at the moment.';
    $('wipeConfirmInput').value = '';
    $('wipeGoBtn').disabled = true;
    wipeModal.classList.add('open');
    wipeModal.setAttribute('aria-hidden', 'false');
    setTimeout(() => $('wipeConfirmInput').focus(), 60);
  }
  function closeWipe() {
    wipeModal.classList.remove('open');
    wipeModal.setAttribute('aria-hidden', 'true');
  }

  const wipeAllBtn = $('wipeAllBtn');
  if (wipeAllBtn) {
    wipeAllBtn.addEventListener('click', openWipe);
  }
  $('wipeCloseBtn').addEventListener('click', closeWipe);
  $('wipeCancelBtn').addEventListener('click', closeWipe);
  wipeModal.addEventListener('click', (e) => { if (e.target === wipeModal) closeWipe(); });

  $('wipeConfirmInput').addEventListener('input', () => {
    $('wipeGoBtn').disabled = $('wipeConfirmInput').value.trim() !== PROJECT_ID;
  });
  $('wipeConfirmInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !$('wipeGoBtn').disabled) $('wipeGoBtn').click();
  });

  $('wipeGoBtn').addEventListener('click', async () => {
    const btn = $('wipeGoBtn');
    btn.disabled = true;
    btn.textContent = 'Deleting…';
    try {
      const data = await AIT.postJSON(apiUrl('wipe_project_data'), {
        confirm_project_id: $('wipeConfirmInput').value.trim(),
      });
      AIT.toast('All study data was deleted: ' + countText(Number(data.deleted_messages), 'message') + ' and '
        + countText(Number(data.deleted_events), 'conductor record') + ' removed.');
      currentId = null;
      hideViewer();
      closeWipe();
      loadList(false);
    } catch (e) {
      AIT.toast('The data could not be deleted: ' + e.message);
      btn.disabled = $('wipeConfirmInput').value.trim() !== PROJECT_ID;
    } finally {
      btn.textContent = 'Delete all data permanently';
    }
  });

  $('optTranscriptCol').addEventListener('change', () => {
    $('transcriptWarn').classList.toggle('hidden', !$('optTranscriptCol').checked);
  });
  $('overviewGo').addEventListener('click', () => {
    const extra = '&include_human=' + ($('optHumanCol').checked ? '1' : '0')
                + '&include_transcript=' + ($('optTranscriptCol').checked ? '1' : '0');
    triggerDownload(apiUrl('download_overview_csv', extra));
  });
  $('zipGo').addEventListener('click', () => triggerDownload(apiUrl('download_zip')));

  // ---- Share link for collaborators ---------------------------------------------------
  const shareBtn = $('shareLinkBtn');
  if (shareBtn && SHARE_LINK) {
    shareBtn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(SHARE_LINK);
        AIT.toast('The share link was copied. The link opens this data page, but not the settings or the password.', { duration: 3500 });
      } catch (_) {
        AIT.copyText(SHARE_LINK); // older browsers: the fallback copy
      }
    });
  }

  // ---- Wiring -------------------------------------------------------------------------
  $('searchInput').addEventListener('input', renderList);
  $('reloadBtn').addEventListener('click', () => loadList(true));
  syncExportState();
  loadList(false);
})();
</script>
</body>
</html>
