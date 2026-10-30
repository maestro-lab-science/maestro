<?php
/**
 * projects.php — the portal of the studio.
 *
 * Signed out, the top row is a hero card (welcome sentence, "Create a new
 * study", "Open an existing study", the logo mark) next to the "Getting
 * started" video tile; below it the paper's
 * example studies as three tiles (includes/portal_examples.php) and one line
 * about the optional account (its form opens in a dialog). A form replaces
 * all of it until "Back".
 * Signed in, it becomes a small dashboard: every study in the account,
 * one click to open, plus "new study" and "add existing" actions, with the
 * example studies underneath. The forms come from includes/portal_forms.php;
 * the requests go to api/auth.php (studies) and api/account.php (accounts).
 *
 * @author Marc Becker
 */
require_once __DIR__ . '/includes/account.php';
require_once __DIR__ . '/includes/videos.php';

header('X-Content-Type-Options: nosniff');

// The "Getting started" video of the signed-out page lives on YouTube
// (id in includes/videos.php). The tile shows a local copy of the video's YouTube
// thumbnail next to the landing page's poster; the YouTube player (privacy-enhanced
// domain) is only loaded on click. The file's date in the address makes browsers fetch
// a replaced thumbnail at once.
$gs_src    = site_video_embed('step-by-step');
$gs_poster_file = __DIR__ . '/../landing/maestro-getting-started-poster.jpg';
$gs_poster = is_file($gs_poster_file) ? '../landing/maestro-getting-started-poster.jpg?v=' . filemtime($gs_poster_file) : '';

try {
    $account_user = account_boot();
    $account_list = $account_user ? account_projects((int)$account_user['id']) : [];
} catch (RuntimeException $e) {
    // Portal must render even when the DB is down (the forms explain the rest).
    $account_user = null;
    $account_list = [];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Studies · MAESTRO</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
  <link rel="stylesheet" href="assets/fonts/font-awesome/css/all.min.css">
  <?= icon_links() ?>
  <style>
    body { display: flex; flex-direction: column; min-height: 100vh; }
    .hero { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 40px 20px 72px; }
    .portal { width: 100%; max-width: 980px; }
    .portal .card { margin-top: 0; }

    /* ---- Signed out: hero + account tile on top, three example tiles below ---- */
    .portal-guest { max-width: 1000px; }
    .top-row { display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 16px; }
    .hero-card { display: grid; grid-template-columns: 1fr auto; gap: 24px; align-items: center; padding: 32px 32px; }
    .hero-card h1 { font-size: 27px; line-height: 1.2; }
    .hero-card .hero-sub { color: var(--ink-2); font-size: 15px; line-height: 1.55; max-width: 44ch; margin-top: 10px; }
    .hero-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 22px; max-width: 420px; }
    .hero-actions .btn-lg { min-height: 46px; font-size: 14.5px; padding-left: 14px; padding-right: 14px; width: 100%; }
    .hero-logo { display: flex; align-items: center; justify-content: center; width: 120px; height: 120px; background: var(--bg); border-radius: var(--radius); }
    .hero-logo img { width: 70px; height: 70px; }
    .block-title { font-size: 15.5px; line-height: 1.35; }
    .block-sub { color: var(--muted); font-size: 13.5px; margin-top: 6px; line-height: 1.5; }
    /* "Getting started" video tile */
    .video-card { display: flex; flex-direction: column; padding: 18px 16px 16px; }
    .video-frame {
      position: relative; aspect-ratio: 16 / 9; border-radius: 10px; overflow: hidden; margin: 0;
      background: var(--accent); color: #fff; cursor: pointer;
    }
    .video-frame img, .video-frame iframe { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; border: 0; }
    .video-frame .video-mark { position: absolute; left: 14px; top: 12px; width: 24px; height: 24px; }
    /* Round play button in the middle, so the YouTube thumbnail stays readable around it */
    .video-play {
      position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
      width: 46px; height: 46px; border-radius: 50%; border: 0; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      background: #fff; color: var(--ink); box-shadow: 0 2px 10px rgba(0,0,0,.3); transition: background-color .15s ease;
    }
    .video-play i { font-size: 15px; margin-left: 3px; }
    .video-frame:hover .video-play { background: var(--accent-soft); }
    .video-card .block-title { font-size: 15px; margin-bottom: 12px; }
    /* One column: a small thumbnail beside the heading instead of a full-width frame;
       once the video plays, the player takes the full width below the heading */
    @media (max-width: 860px) {
      .video-card { display: grid; grid-template-columns: 128px minmax(0, 1fr); grid-template-areas: "frame title"; gap: 14px; align-items: center; padding: 12px; }
      .video-card .video-frame { grid-area: frame; border-radius: 8px; }
      .video-card .block-title { grid-area: title; margin: 0; }
      .video-card .video-play { width: 34px; height: 34px; }
      .video-card .video-play i { font-size: 12px; margin-left: 2px; }
      .video-card.playing { grid-template-columns: minmax(0, 1fr); grid-template-areas: "title" "frame"; gap: 12px; padding: 16px; }
    }
    .acct-line { font-size: 13.5px; color: var(--muted); margin: 20px 0 0; text-align: center; }
    .acct-line a { font-weight: 600; cursor: pointer; }
    .examples { margin-top: 36px; padding-top: 36px; border-top: 1px solid var(--line); }
    .tiles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-top: 14px; }
    /* The study tiles look different from the action cards: tinted, with a numbered square like the landing page's steps */
    .ex-tile { display: flex; flex-direction: column; padding: 20px; background: var(--accent-soft); }
    .ex-tile .ex-head { display: flex; align-items: center; gap: 10px; }
    .ex-tile .ex-num {
      flex-shrink: 0; width: 28px; height: 28px; border-radius: 7px; background: var(--accent); color: #fff;
      display: inline-flex; align-items: center; justify-content: center; font: 700 13px var(--display);
    }
    .ex-tile h3 { font-size: 15px; line-height: 1.3; }
    .ex-tile p { font-size: 13px; color: var(--ink-2); line-height: 1.5; margin: 12px 0 0; flex: 1; }
    .inline-ico {
      display: inline-flex; align-items: center; justify-content: center; width: 19px; height: 19px; border-radius: 5px;
      background: var(--accent-soft); color: var(--accent); font-size: 9px; line-height: 1;
      vertical-align: middle; position: relative; top: -1px; margin: 0 2px;
    }
    .inline-ico .fa-play { position: relative; left: 1px; }
    .ex-conds { margin-top: 14px; display: flex; flex-direction: column; gap: 8px; }
    .ex-cond { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: #fff; border-radius: 9px; padding: 7px 7px 7px 12px; }
    .ex-cond-name { font-size: 13.5px; font-weight: 600; }
    .ex-cond-btns { display: flex; gap: 6px; flex-shrink: 0; }
    .icon-btn {
      width: 32px; height: 32px; border-radius: 7px; display: inline-flex; align-items: center; justify-content: center;
      background: var(--accent-soft); color: var(--accent); font-size: 12px; transition: background .14s, color .14s;
    }
    .icon-btn:hover, .icon-btn:focus-visible { background: var(--accent); color: #fff; text-decoration: none; }
    /* Tooltip above the icon button (data-tip), shown on hover and keyboard focus */
    .icon-btn { position: relative; }
    .icon-btn::after {
      content: attr(data-tip); position: absolute; bottom: calc(100% + 8px); right: 0;
      background: var(--ink); color: #fff; font-size: 12.5px; font-weight: 500; line-height: 1.35;
      padding: 6px 10px; border-radius: 7px; width: max-content; max-width: 220px; text-align: left;
      opacity: 0; transform: translateY(3px); pointer-events: none; transition: opacity .12s, transform .12s; z-index: 5;
    }
    .icon-btn::before {
      content: ""; position: absolute; bottom: calc(100% + 3px); right: 11px;
      border: 5px solid transparent; border-top-color: var(--ink); border-bottom: 0;
      opacity: 0; transition: opacity .12s; pointer-events: none; z-index: 5;
    }
    .icon-btn:hover::after, .icon-btn:hover::before, .icon-btn:focus-visible::after, .icon-btn:focus-visible::before { opacity: 1; transform: none; }
    .ex-links .btn-sm i { font-size: 10px; margin-left: 2px; }
    .ex-off { color: var(--faint); cursor: default; }
    .ex-off:hover { background: var(--line-soft); }
    /* Below 1000px the logo makes room, so the video tile keeps its 280px */
    @media (max-width: 1000px) { .hero-logo { display: none; } .hero-card { grid-template-columns: 1fr; } }
    @media (max-width: 860px) { .top-row { grid-template-columns: 1fr; } }
    /* The example tiles keep three columns longer: they only stack once the condition rows run out of room */
    @media (max-width: 720px) { .tiles { grid-template-columns: 1fr; } }
    @media (max-width: 480px) { .hero-card { padding: 26px 22px; } .hero-card h1 { font-size: 24px; } .hero-actions { grid-template-columns: 1fr; } }


    /* ---- Account dialog and study dialog ---- */
    .modal .tabs { display: flex; width: 100%; margin-bottom: 18px; }
    .modal .tabs button { flex: 1; }

    /* ---- Signed in: dashboard ---- */
    .dash-head { display: flex; align-items: flex-end; gap: 14px; margin-bottom: 18px; flex-wrap: wrap; }
    .dash-head h1 { font-size: 22px; }
    .dash-head .dash-sub { font-size: 13.5px; color: var(--muted); margin-top: 4px; }
    .dash-head .dash-actions { margin-left: auto; display: flex; gap: 8px; padding-bottom: 2px; }
    /* Phones: the two buttons share the full width and wrap when the labels need the room */
    @media (max-width: 560px) {
      .dash-head .dash-actions { margin-left: 0; width: 100%; flex-wrap: wrap; }
      .dash-head .dash-actions .btn { flex: 1 1 auto; }
    }
    .dash-card { padding: 10px; }
    .dash-note { font-size: 12.5px; color: var(--muted); margin: 12px 4px 0; }
    .portal .examples.dash-examples { margin-top: 28px; }

    /* Study cards */
    .dash-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(255px, 1fr)); gap: 14px; }
    .proj-card {
      position: relative; padding: 18px 18px 13px; cursor: pointer; display: flex; flex-direction: column;
      transition: transform .13s ease, box-shadow .13s ease;
    }
    .proj-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
    .proj-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 13px; }
    .proj-mark { width: 24px; height: 24px; display: block; }
    .proj-remove {
      border: none; background: transparent; color: var(--faint);
      width: 30px; height: 30px; border-radius: 8px; cursor: pointer; font-size: 13px;
      opacity: 0; transition: opacity .12s ease, background .12s ease, color .12s ease;
    }
    .proj-card:hover .proj-remove, .proj-remove:focus-visible { opacity: 1; }
    @media (hover: none) { .proj-remove { opacity: 1; } } /* touch screens have no hover */
    .proj-remove:hover { background: var(--danger-soft); color: var(--danger); }
    /* The whole name (80 characters at most): conditions of one study often differ only at the end */
    .proj-name { font-weight: 700; font-size: 15px; line-height: 1.35; overflow-wrap: anywhere; }
    .proj-id { font-family: var(--mono); font-size: 12px; color: var(--muted); margin-top: 3px; }
    .proj-meta { display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; font-size: 12.5px; color: var(--ink-2); margin: 13px 0; }
    .proj-meta i { color: var(--accent); margin-right: 3px; }
    .proj-meta .proj-last { color: var(--muted); }
    .proj-foot {
      display: flex; align-items: center; justify-content: space-between; gap: 8px;
      margin-top: auto; padding-top: 10px; border-top: 1px solid var(--line-soft); /* at the bottom, level across a row */
    }
    .proj-open { font-size: 12.5px; font-weight: 600; color: var(--accent); }
    .proj-open i { font-size: 11px; transition: transform .13s ease; }
    .proj-card:hover .proj-open i { transform: translateX(3px); }
    .proj-confirm {
      position: absolute; inset: 0; border-radius: inherit; z-index: 2;
      background: rgba(255, 255, 255, .97); border: 1.5px solid #fecaca;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      gap: 12px; text-align: center; padding: 16px; cursor: default;
    }
    .proj-confirm.hidden { display: none; }
    .proj-confirm p { font-size: 13.5px; font-weight: 600; line-height: 1.45; }
    .proj-confirm small { font-weight: 400; color: var(--muted); }
    .acct-empty { font-size: 13.5px; color: var(--muted); padding: 30px 14px; text-align: center; }
    .acct-empty i { display: block; font-size: 20px; color: var(--faint); margin-bottom: 8px; }

    /* Topbar account chip (clickable → account settings) */
    .acct-chip {
      display: inline-flex; align-items: center; gap: 8px; max-width: 260px;
      padding: 6px 12px; border-radius: 999px; background: var(--line-soft);
      font-size: 13px; color: var(--ink-2);
      border: none; cursor: pointer; font-family: inherit;
      transition: background .12s ease;
    }
    .acct-chip:hover { background: #e2e8f0; }
    .acct-chip i { color: var(--accent); }
    .acct-chip .acct-email { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .acct-chip .acct-caret { font-size: 10px; color: var(--faint); }
    /* Phones: the top bar keeps the logo and the account button in one row */
    @media (max-width: 560px) {
      .topbar { gap: 8px; padding: 0 14px; }
      .topbar .brand small, .topbar .tb-label { display: none; }
      .acct-chip { min-width: 0; }
    }

    .cred-box { background: #f8fafc; border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; }
    .cred-box .cred-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 5px 0; }
    .cred-box .cred-label { font-size: 12.5px; font-weight: 600; color: var(--muted); }
    .cred-box .cred-value { font-family: var(--mono); font-size: 13px; word-break: break-all; text-align: right; }
    .modal .acct-note { display: flex; gap: 8px; align-items: flex-start; font-size: 12.5px; color: var(--muted); margin-top: 14px; }
    .modal .acct-note i { color: var(--accent); margin-top: 2px; }
  </style>
</head>
<body>

<header class="topbar">
  <a class="brand" href="projects.php">
    <img class="brand-logo" src="assets/img/maestro-logo.svg" alt="MAESTRO">
    <small>· Studies</small>
  </a>
  <span class="spacer"></span>
  <a class="btn btn-sm btn-ghost" href="https://maestro-lab.science/about" title="What MAESTRO is and who built it">
    About<span class="tb-label"> MAESTRO</span>
  </a>
  <?php if ($account_user): ?>
    <button class="acct-chip" id="acctChipBtn" type="button" title="Account settings: name, email and password">
      <i class="far fa-user-circle"></i>
      <span class="acct-email"><?= htmlspecialchars(($account_user['display_name'] ?? '') !== '' ? $account_user['display_name'] : $account_user['email']) ?></span>
      <i class="fas fa-chevron-down acct-caret"></i>
    </button>
    <button class="btn btn-sm btn-ghost" id="signOutBtn" type="button" title="Sign out">
      <i class="fas fa-sign-out-alt"></i><span class="tb-label">&nbsp;Sign out</span>
    </button>
  <?php else: ?>
    <button class="btn btn-sm" id="signInBtn" type="button" title="Sign in to an account, or create an account">
      <i class="far fa-user-circle"></i>&nbsp;Sign in
    </button>
  <?php endif; ?>
</header>

<main class="hero">
  <div class="portal<?= $account_user ? '' : ' portal-guest' ?>">

<?php if (!$account_user): ?>
    <!-- ============================ SIGNED OUT ============================ -->
    <div class="top-row" id="guestMenu">
      <div class="card hero-card">
        <div>
          <h1>Welcome to MAESTRO.</h1>
          <p class="hero-sub">Here you set up and test a study and collect the conversations. Everything runs in the
          browser, and you do not need an account.</p>
          <div class="hero-actions">
            <button class="btn btn-primary btn-lg" id="choiceCreate" type="button"><i class="fas fa-plus"></i>&nbsp;Create a new study</button>
            <button class="btn btn-lg" id="choiceOpen" type="button"><i class="fas fa-folder-open"></i>&nbsp;Open an existing study</button>
          </div>
        </div>
        <div class="hero-logo" aria-hidden="true"><img src="assets/img/maestro-mark.svg" alt=""></div>
      </div>

      <div class="card video-card">
        <h2 class="block-title">Watch how to set up your first study.</h2>
        <figure class="video-frame" id="gsVideo" data-src="<?= htmlspecialchars($gs_src) ?>">
          <?php if ($gs_poster): ?>
            <img src="<?= htmlspecialchars($gs_poster) ?>" alt="">
          <?php else: ?>
            <img class="video-mark" src="assets/img/maestro-mark.svg" alt="">
          <?php endif; ?>
          <button class="video-play" type="button" id="gsPlay" aria-label="Play the getting-started video"><i class="fas fa-play"></i></button>
        </figure>
      </div>
    </div>

    <p class="acct-line" id="acctCard"><a id="acctOpenRegister">Create an account</a> or <a id="acctOpenLogin">sign in</a>.
    An account keeps all your studies in one list. An account is optional, because every study also opens with its own link and password.</p>

    <?php require __DIR__ . '/includes/portal_examples.php'; ?>

<?php else: ?>
    <!-- ============================ SIGNED IN ============================ -->
    <div class="dash-head">
      <div>
        <h1>Your studies</h1>
        <p class="dash-sub">Welcome back. You have <?= count($account_list) === 1 ? '1 study' : count($account_list) . ' studies' ?> in your account.</p>
      </div>
      <div class="dash-actions">
        <button class="btn" id="dashOpen" type="button"><i class="fas fa-folder-open"></i>&nbsp;Add existing study</button>
        <button class="btn btn-primary" id="dashNew" type="button"><i class="fas fa-plus"></i>&nbsp;New study</button>
      </div>
    </div>

    <?php if (!$account_list): ?>
    <div class="card dash-card">
      <p class="acct-empty"><i class="far fa-folder-open"></i>
        Nothing here yet. You can create a new study or add an existing study, and the study appears in your account.</p>
    </div>
    <?php else: ?>
    <div class="dash-grid" id="acctList">
      <?php foreach ($account_list as $row):
        $convs = (int)$row['conversations'];
        $last  = !empty($row['last_at']) ? date('j M', strtotime((string)$row['last_at'])) : '';
      ?>
      <div class="card proj-card" data-id="<?= htmlspecialchars($row['project_id']) ?>"
           title="Added to your account on <?= htmlspecialchars(date('j M Y', strtotime((string)$row['added_at']))) ?>">
        <div class="proj-top">
          <img class="proj-mark" src="assets/img/maestro-mark.svg" alt="" width="24" height="24">
          <button class="proj-remove" type="button" title="Remove from your account"><i class="far fa-trash-alt"></i></button>
        </div>
        <div class="proj-name"><?= htmlspecialchars($row['name'] !== '' ? $row['name'] : 'Untitled study') ?></div>
        <div class="proj-id"><?= htmlspecialchars($row['project_id']) ?></div>
        <div class="proj-meta">
          <span><i class="far fa-comments"></i>
            <?= $convs === 0 ? 'No conversations yet' : ($convs === 1 ? '1 conversation' : $convs . ' conversations') ?></span>
          <?php if ($last !== ''): ?><span class="proj-last">· last <?= htmlspecialchars($last) ?></span><?php endif; ?>
        </div>
        <div class="proj-foot">
          <span class="proj-open">Open settings <i class="fas fa-arrow-right"></i></span>
          <a class="btn btn-sm btn-ghost proj-data" href="data.php?project=<?= urlencode($row['project_id']) ?>"
             title="Open the data page: recorded conversations, costs and exports"><i class="far fa-chart-bar"></i>&nbsp;Data</a>
        </div>
        <div class="proj-confirm hidden">
          <p>Remove this study from your account?<br><small>The study itself stays as it is.</small></p>
          <div class="row">
            <button class="btn btn-sm btn-danger proj-confirm-yes" type="button">Remove</button>
            <button class="btn btn-sm proj-confirm-no" type="button">Cancel</button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="dash-note">Click a study to open its settings. Removing a study only takes the study out of your account.
    The study itself and everyone else's access stay as they are.</p>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/portal_examples.php'; ?>
<?php endif; ?>

  </div>
</main>

<!-- Study dialog: create a new study or open an existing one -->
<div class="modal-backdrop" id="formsCard" role="dialog" aria-labelledby="formsTitle">
  <div class="modal">
    <?php require __DIR__ . '/includes/portal_forms.php'; ?>
  </div>
</div>

<!-- Credentials modal (after create) -->
<div class="modal-backdrop" id="credModal">
  <div class="modal">
    <h2>Your study has been created</h2>
    <p class="modal-sub">Save these credentials now. You need the study ID and password to reopen the study and its data.</p>
    <div class="cred-box">
      <div class="cred-row"><span class="cred-label">Name</span><span class="cred-value cred-text" id="credName"></span></div>
      <div class="cred-row"><span class="cred-label">Study ID</span><span class="cred-value" id="credId"></span></div>
      <div class="cred-row"><span class="cred-label">Password</span><span class="cred-value" id="credPass"></span></div>
    </div>
    <?php if ($account_user): ?>
    <p class="acct-note"><i class="fas fa-check-circle"></i>
      The study was also added to your account (<?= htmlspecialchars($account_user['email']) ?>), so you can reopen
      the study from any device you are signed in on.</p>
    <?php else: ?>
    <p class="acct-note"><i class="far fa-user-circle"></i>
      If you create an account later, you can add this study to the account with the study ID and password.</p>
    <?php endif; ?>
    <div class="row mt-3 cred-actions">
      <div class="row">
        <button class="btn" id="credDownload" type="button">Download .txt</button>
        <button class="btn" id="credCopy" type="button">Copy credentials</button>
      </div>
      <button class="btn btn-primary" id="credProceed" type="button">Open settings →</button>
    </div>
  </div>
</div>

<?php if (!$account_user): ?>
<!-- Account dialog: sign in or create an account -->
<div class="modal-backdrop" id="acctModal" role="dialog" aria-labelledby="acctTitle">
  <div class="modal">
    <h2 id="acctTitle">Sign in or create an account</h2>
    <p class="modal-sub">Creating an account lets you manage your studies more easily: all your studies appear in one list on
    the studies page and open from any device without typing the study ID and password. An account is optional; without an account,
    every study still opens with its own link and password.</p>
    <div class="tabs" role="tablist">
      <button id="acctTabLogin" class="active" type="button">Sign in</button>
      <button id="acctTabRegister" type="button">Create account</button>
    </div>
    <form id="acctForm">
      <div class="field">
        <label class="label" for="acctEmail">Email</label>
        <input id="acctEmail" class="input" type="email" autocomplete="username" required>
      </div>
      <div class="field">
        <label class="label" for="acctPassword">Password</label>
        <input id="acctPassword" class="input" type="password" autocomplete="current-password" required>
        <p class="hint hidden" id="acctPwHint">At least 8 characters. There is no password recovery, so please store the password safely.</p>
      </div>
      <p class="hint hidden" id="acctRegisterNote">Studies you created without an account can be added afterwards with the
      study ID and password.</p>
      <p class="hint" id="acctError" style="color: var(--danger);" role="alert"></p>
      <div class="row mt-2 modal-actions" style="justify-content: flex-end;">
        <button class="btn" id="acctCancel" type="button">Cancel</button>
        <button class="btn btn-primary" id="acctSubmit" type="submit">Sign in</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($account_user): ?>
<!-- Account settings modal (name / email / password) -->
<div class="modal-backdrop" id="profileModal">
  <div class="modal">
    <h2>Account settings</h2>
    <p class="modal-sub">Your account stores and organizes the credentials of your studies.</p>
    <form id="profileForm">
      <div class="field">
        <label class="label" for="profName">Name</label>
        <input id="profName" class="input" type="text" maxlength="80"
               placeholder="Shown instead of your email" autocomplete="name"
               value="<?= htmlspecialchars($account_user['display_name'] ?? '') ?>">
      </div>
      <div class="field">
        <label class="label" for="profEmail">Email</label>
        <input id="profEmail" class="input" type="email" required autocomplete="username"
               value="<?= htmlspecialchars($account_user['email']) ?>">
      </div>
      <div class="field">
        <label class="label" for="profNewPw">New password</label>
        <input id="profNewPw" class="input" type="password" autocomplete="new-password"
               placeholder="Leave empty to keep the current password">
      </div>
      <div class="field hidden" id="profCurrWrap">
        <label class="label" for="profCurrPw">Current password</label>
        <input id="profCurrPw" class="input" type="password" autocomplete="current-password">
        <p class="hint">Your current password is needed to set a new password.</p>
      </div>
      <p class="hint" id="profError" style="color: var(--danger);" role="alert"></p>
      <div class="row mt-2 modal-actions" style="justify-content: flex-end;">
        <button class="btn" id="profCancel" type="button">Cancel</button>
        <button class="btn btn-primary" id="profSave" type="submit">Save changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
(function () {
  const $ = (id) => document.getElementById(id);

  // ---- Study dialog: create / open ----------------------------------------
  // 'create' / 'open' show the matching form in the study dialog; 'menu'
  // closes the dialog again.
  function showView(view) {
    $('formsCard').classList.toggle('open', view !== 'menu');
    $('createWrap').classList.toggle('hidden', view !== 'create');
    $('openWrap').classList.toggle('hidden', view !== 'open');
    $('formsTitle').textContent = view === 'open' ? OPEN_TITLE : 'New study';
    if (view === 'create') setTimeout(() => $('createName').focus({ preventScroll: true }), 60);
    if (view === 'open') setTimeout(() => ($('openId').value ? $('openPassword') : $('openId')).focus({ preventScroll: true }), 60);
  }
  const OPEN_TITLE = $('formsTitle').dataset.openTitle;
  if ($('choiceCreate')) $('choiceCreate').addEventListener('click', () => showView('create'));
  if ($('choiceOpen')) $('choiceOpen').addEventListener('click', () => showView('open'));
  if ($('dashNew')) $('dashNew').addEventListener('click', () => showView('create'));
  if ($('dashOpen')) $('dashOpen').addEventListener('click', () => showView('open'));
  document.querySelectorAll('.form-back').forEach((btn) => {
    btn.addEventListener('click', () => showView('menu'));
  });
  $('formsCard').addEventListener('click', (e) => { if (e.target === $('formsCard')) showView('menu'); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') showView('menu'); });

  // ---- Credential generation --------------------------------------------------
  function regenerate() {
    $('createId').value = AIT.randomString(16, AIT.ID_CHARS);
    $('createPassword').value = AIT.randomString(20, AIT.PW_CHARS);
  }
  $('regenId').addEventListener('click', () => { $('createId').value = AIT.randomString(16, AIT.ID_CHARS); });
  $('regenPass').addEventListener('click', () => { $('createPassword').value = AIT.randomString(20, AIT.PW_CHARS); });
  regenerate();

  // ---- Link helpers -----------------------------------------------------------
  function linkMode() {
    try { return localStorage.getItem('aiteamstudio.linkMode') === 'embed' ? 'embed' : 'clean'; } catch (_) { return 'clean'; }
  }
  function handoffPw(id, pw) {
    if (pw) { try { sessionStorage.setItem('aiteamstudio.pwSession.' + id, pw); } catch (_) {} }
  }
  // Embeds the password only when the user opted into "link only" mode.
  function settingsUrl(id, password) {
    return 'settings.php?project=' + encodeURIComponent(id) +
      (password && linkMode() === 'embed' ? '&password=' + encodeURIComponent(password) : '');
  }

  // ---- Account dialog: sign in / create account (signed out) ------------------
  if ($('acctForm')) {
    let acctMode = 'login';
    const setAcctMode = (mode) => {
      acctMode = mode;
      $('acctTabLogin').classList.toggle('active', mode === 'login');
      $('acctTabRegister').classList.toggle('active', mode === 'register');
      $('acctSubmit').textContent = mode === 'login' ? 'Sign in' : 'Create account';
      $('acctPwHint').classList.toggle('hidden', mode === 'login');
      $('acctRegisterNote').classList.toggle('hidden', mode === 'login');
      $('acctPassword').setAttribute('autocomplete', mode === 'login' ? 'current-password' : 'new-password');
      $('acctError').textContent = '';
    };
    const openAcct = (mode) => {
      setAcctMode(mode);
      $('acctModal').classList.add('open');
      setTimeout(() => $('acctEmail').focus({ preventScroll: true }), 60);
    };
    const closeAcct = () => $('acctModal').classList.remove('open');
    $('acctTabLogin').addEventListener('click', () => setAcctMode('login'));
    $('acctTabRegister').addEventListener('click', () => setAcctMode('register'));
    $('signInBtn').addEventListener('click', () => openAcct('login'));
    $('acctOpenLogin').addEventListener('click', () => openAcct('login'));
    $('acctOpenRegister').addEventListener('click', () => openAcct('register'));
    $('acctCancel').addEventListener('click', closeAcct);
    $('acctModal').addEventListener('click', (e) => { if (e.target === $('acctModal')) closeAcct(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAcct(); });
    $('acctForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      $('acctError').textContent = '';
      $('acctSubmit').disabled = true;
      try {
        await AIT.postJSON('api/account.php?action=' + acctMode, {
          email: $('acctEmail').value.trim(),
          password: $('acctPassword').value,
        });
        location.reload();
      } catch (err) {
        $('acctError').textContent = err.message;
        $('acctSubmit').disabled = false;
      }
    });
  }

  // ---- Getting-started video: the YouTube player replaces the poster on a click anywhere on it ----
  if ($('gsVideo')) {
    $('gsVideo').addEventListener('click', () => {
      const frame = $('gsVideo');
      if (frame.querySelector('iframe')) return;
      frame.closest('.video-card').classList.add('playing');
      const f = document.createElement('iframe');
      f.src = frame.dataset.src;
      f.title = 'Getting started with MAESTRO';
      f.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
      frame.querySelectorAll('img, button').forEach((el) => el.remove());
      frame.appendChild(f);
    });
  }

  // ---- Account settings (chip → modal) ---------------------------------------
  if ($('acctChipBtn')) {
    const openProfile = () => {
      $('profileModal').classList.add('open');
      setTimeout(() => $('profName').focus({ preventScroll: true }), 60);
    };
    $('acctChipBtn').addEventListener('click', openProfile);
    $('profCancel').addEventListener('click', () => $('profileModal').classList.remove('open'));
    $('profileModal').addEventListener('click', (e) => {
      if (e.target === $('profileModal')) $('profileModal').classList.remove('open');
    });
    // The current password is only needed (and shown) when setting a new one.
    $('profNewPw').addEventListener('input', () => {
      $('profCurrWrap').classList.toggle('hidden', !$('profNewPw').value);
    });
    $('profileForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      $('profError').textContent = '';
      $('profSave').disabled = true;
      try {
        await AIT.postJSON('api/account.php?action=update', {
          name: $('profName').value.trim(),
          email: $('profEmail').value.trim(),
          new_password: $('profNewPw').value,
          current_password: $('profCurrPw').value,
        });
        location.reload();
      } catch (err) {
        $('profError').textContent = err.message;
        $('profSave').disabled = false;
      }
    });
  }

  if ($('signOutBtn')) {
    $('signOutBtn').addEventListener('click', async () => {
      $('signOutBtn').disabled = true;
      try {
        await AIT.postJSON('api/account.php?action=logout', {});
        location.reload();
      } catch (err) {
        AIT.toast(err.message, { error: true });
        $('signOutBtn').disabled = false;
      }
    });
  }

  // ---- Dashboard cards: open on click, remove = unlink with in-card confirm ---
  document.querySelectorAll('#acctList .proj-card').forEach((card) => {
    const id = card.dataset.id;
    const confirmBox = card.querySelector('.proj-confirm');
    card.addEventListener('click', (e) => {
      if (e.target.closest('.proj-remove') || e.target.closest('.proj-confirm') || e.target.closest('.proj-data')) return;
      // Clean link — the settings page unlocks via the account automatically.
      location.href = 'settings.php?project=' + encodeURIComponent(id);
    });
    card.querySelector('.proj-remove').addEventListener('click', () => confirmBox.classList.remove('hidden'));
    card.querySelector('.proj-confirm-no').addEventListener('click', () => confirmBox.classList.add('hidden'));
    card.querySelector('.proj-confirm-yes').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        await AIT.postJSON('api/account.php?action=remove', { project_id: id });
        card.remove();
        if (!document.querySelector('#acctList .proj-card')) location.reload();
        else AIT.toast('Removed from your account. The study itself is unchanged.');
      } catch (err) {
        AIT.toast(err.message, { error: true });
        btn.disabled = false;
      }
    });
  });

  // ---- Create ---------------------------------------------------------------------
  let created = null;
  $('createPane').addEventListener('submit', async (e) => {
    e.preventDefault();
    $('createError').textContent = '';
    $('createSubmit').disabled = true;
    try {
      const payload = {
        name: $('createName').value.trim(),
        project_id: $('createId').value.trim(),
        password: $('createPassword').value.trim(),
      };
      const data = await AIT.postJSON('api/auth.php?action=create', payload);
      created = { id: data.project_id, name: payload.name, password: payload.password };
      handoffPw(created.id, created.password);
      $('credName').textContent = created.name || '(no name)';
      $('credId').textContent = created.id;
      $('credPass').textContent = created.password;
      showView('menu');
      $('credModal').classList.add('open');
    } catch (err) {
      $('createError').textContent = err.message;
    } finally {
      $('createSubmit').disabled = false;
    }
  });

  $('credDownload').addEventListener('click', () => {
    if (!created) return;
    const content = [
      'MAESTRO study credentials',
      '',
      'Name:       ' + (created.name || '(no name)'),
      'Study ID:   ' + created.id,
      'Password:   ' + created.password,
      '',
      'Settings page: ' + location.origin + location.pathname.replace(/projects\.php$/, 'settings.php') + '?project=' + created.id,
      'Keep this file safe. You need these credentials to reopen the study.',
    ].join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([content], { type: 'text/plain' }));
    a.download = 'project_' + created.id + '.txt';
    document.body.appendChild(a);
    a.click();
    a.remove();
  });

  $('credCopy').addEventListener('click', () => {
    if (!created) return;
    AIT.copyText('Study ID: ' + created.id + '\nPassword: ' + created.password);
  });

  $('credProceed').addEventListener('click', () => {
    if (!created) return;
    location.href = settingsUrl(created.id, created.password);
  });

  // ---- Open -----------------------------------------------------------------------
  $('openPane').addEventListener('submit', async (e) => {
    e.preventDefault();
    $('openError').textContent = '';
    $('openSubmit').disabled = true;
    try {
      const pw = $('openPassword').value.trim();
      const data = await AIT.postJSON('api/auth.php?action=open', {
        project_id: $('openId').value.trim(),
        password: pw,
      });
      handoffPw(data.project_id, pw);
      location.href = settingsUrl(data.project_id, pw);
    } catch (err) {
      $('openError').textContent = err.message;
    } finally {
      $('openSubmit').disabled = false;
    }
  });

  // ---- Deep links (unlock page links here; legacy redirects) -----------------------
  const q = new URLSearchParams(location.search);
  const openId = (q.get('open') || '').trim();
  if (openId) {
    showView('open');
    $('openId').value = openId;
    setTimeout(() => $('openPassword').focus(), 50);
    AIT.toast('Enter the password to open this study.');
  }
  if (q.get('missing')) {
    showView('open');
    $('openError').textContent = 'Study "' + q.get('missing') + '" was not found.';
  }
})();
</script>
</body>
</html>
