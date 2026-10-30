<?php
/**
 * unlock.php — the password page for clean (password-less) researcher links.
 *
 * Rendered by settings.php / data.php when a study link arrives without a
 * session, URL password, or share key. Asks for the password in a small card;
 * "Save the password in this browser" stores it in localStorage so future
 * visits unlock silently (the page tries a saved password before asking).
 *
 * Account-aware: a signed-in researcher sees that unlocking adds the study
 * to their account (which then unlocks it everywhere); a signed-out visitor
 * can switch to signing in, which silently unlocks every study the account
 * already keeps.
 *
 * @author Marc Becker
 */

/**
 * Render the unlock page and stop. $returnUrl is reloaded after a successful
 * unlock (the PHP session is granted by api/auth.php?action=open).
 */
function render_unlock_page(array $project, string $returnUrl, ?array $user = null): void
{
    $projectId = (string)$project['project_id'];
    $name = trim((string)$project['name']) !== '' ? (string)$project['name'] : 'Untitled study';
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Unlock · <?= htmlspecialchars($name) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
  <link rel="stylesheet" href="assets/fonts/font-awesome/css/all.min.css">
  <?= icon_links() ?>
  <style>
    body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
    .unlock-card { width: min(420px, 100%); text-align: center; }
    .unlock-icon {
      width: 52px; height: 52px; border-radius: 16px; margin: 0 auto 14px;
      background: var(--accent-soft); color: var(--accent);
      display: flex; align-items: center; justify-content: center; font-size: 20px;
    }
    .unlock-name { font-size: 16px; font-weight: 800; }
    .unlock-id { font-family: var(--mono); font-size: 12px; color: var(--muted); margin-top: 2px; }
    .unlock-form { margin-top: 18px; text-align: left; }
    .unlock-form input[type="password"], .unlock-form input[type="email"] {
      width: 100%; font: inherit; font-size: 14.5px;
      padding: 11px 14px; border: 1px solid var(--line); border-radius: 10px; outline: none;
    }
    .unlock-form input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79,70,229,.12); }
    .unlock-form .gap { margin-top: 10px; }
    .unlock-save {
      display: flex; align-items: center; gap: 8px; margin-top: 12px;
      font-size: 13px; color: var(--ink-2); cursor: pointer;
    }
    .unlock-save input { accent-color: var(--accent); width: 15px; height: 15px; cursor: pointer; }
    .unlock-account-note {
      display: flex; align-items: flex-start; gap: 8px; margin-top: 12px;
      font-size: 12.5px; color: var(--muted); text-align: left;
    }
    .unlock-account-note i { margin-top: 2px; color: var(--accent); }
    .unlock-error { color: var(--danger); font-size: 13px; margin-top: 10px; min-height: 17px; }
    .unlock-btn { width: 100%; margin-top: 12px; justify-content: center; }
    .unlock-busy { display: none; padding: 16px 0 4px; color: var(--muted); font-size: 13.5px; }
    .unlock-busy .spinner { width: 26px; height: 26px; border-width: 3px; margin: 0 auto 10px; }
    .unlock-alt { margin-top: 14px; font-size: 12.5px; color: var(--muted); }
    .unlock-alt a { cursor: pointer; }
    .unlock-back { display: inline-block; margin-top: 16px; font-size: 12.5px; color: var(--muted); }
  </style>
</head>
<body>
  <div class="card unlock-card">
    <div class="unlock-icon"><i class="fas fa-lock"></i></div>
    <div class="unlock-name"><?= htmlspecialchars($name) ?></div>
    <div class="unlock-id"><?= htmlspecialchars($projectId) ?></div>

    <div class="unlock-busy" id="busy">
      <div class="spinner"></div>
      Unlocking with the saved password…
    </div>

    <form class="unlock-form" id="form">
      <input type="password" id="pw" placeholder="Study password" autocomplete="current-password" autofocus>
      <?php if ($user): ?>
      <div class="unlock-account-note">
        <i class="far fa-user-circle"></i>
        <span>You are signed in as <strong><?= htmlspecialchars((string)$user['email']) ?></strong>. Unlocking adds
        this study to your account, so the study opens without a password from now on.</span>
      </div>
      <?php else: ?>
      <label class="unlock-save">
        <input type="checkbox" id="savePw">
        Save the password in this browser, so you are not asked again.
      </label>
      <?php endif; ?>
      <div class="unlock-error" id="err"></div>
      <button class="btn btn-primary unlock-btn" type="submit">Unlock</button>
      <?php if (!$user): ?>
      <p class="unlock-alt">Have an account? <a id="showLogin">Sign in</a>, and the studies in your account unlock automatically.</p>
      <?php endif; ?>
    </form>

    <?php if (!$user): ?>
    <form class="unlock-form" id="loginForm" style="display: none;">
      <input type="email" id="acctEmail" placeholder="Email" autocomplete="username">
      <input type="password" id="acctPw" class="gap" placeholder="Account password" autocomplete="current-password">
      <div class="unlock-error" id="loginErr"></div>
      <button class="btn btn-primary unlock-btn" type="submit">Sign in</button>
      <p class="unlock-alt"><a id="showUnlock">← Enter the study password instead</a></p>
    </form>
    <?php endif; ?>

    <a class="unlock-back" href="projects.php">← All studies</a>
  </div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
(function () {
  'use strict';
  const PROJECT_ID = <?= json_encode($projectId) ?>;
  const RETURN_URL = <?= json_encode($returnUrl) ?>;
  const SAVED_KEY = 'aiteamstudio.savedPw.' + PROJECT_ID;
  const form = document.getElementById('form');
  const busy = document.getElementById('busy');
  const err = document.getElementById('err');

  async function unlock(pw, remember, fromSaved) {
    try {
      await AIT.postJSON('api/auth.php?action=open', { project_id: PROJECT_ID, password: pw });
      if (remember) {
        try { localStorage.setItem(SAVED_KEY, pw); } catch (_) {}
      }
      // Session-scoped handoff so the settings page can show/build links
      // that need the plaintext, without persisting anything extra.
      try { sessionStorage.setItem('aiteamstudio.pwSession.' + PROJECT_ID, pw); } catch (_) {}
      location.replace(RETURN_URL);
    } catch (e) {
      if (fromSaved) {
        // The stored password no longer matches — forget it and ask normally.
        try { localStorage.removeItem(SAVED_KEY); } catch (_) {}
        busy.style.display = 'none';
        form.style.display = 'block';
        err.textContent = 'The saved password no longer works. Please enter the password again.';
      } else {
        err.textContent = e.message || 'This password is not correct.';
      }
    }
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const pw = document.getElementById('pw').value;
    if (!pw) return;
    err.textContent = '';
    const save = document.getElementById('savePw');
    unlock(pw, !!(save && save.checked), false);
  });

  // ---- Sign in instead (signed-out visitors only) ----------------------------
  const loginForm = document.getElementById('loginForm');
  if (loginForm) {
    const swap = (showLogin) => {
      form.style.display = showLogin ? 'none' : 'block';
      loginForm.style.display = showLogin ? 'block' : 'none';
      (showLogin ? document.getElementById('acctEmail') : document.getElementById('pw'))
        .focus({ preventScroll: true });
    };
    document.getElementById('showLogin').addEventListener('click', () => swap(true));
    document.getElementById('showUnlock').addEventListener('click', () => swap(false));
    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const errEl = document.getElementById('loginErr');
      errEl.textContent = '';
      try {
        await AIT.postJSON('api/account.php?action=login', {
          email: document.getElementById('acctEmail').value,
          password: document.getElementById('acctPw').value,
        });
        // Reload: if the account keeps this study it unlocks silently,
        // otherwise the prompt returns with the signed-in note.
        location.replace(RETURN_URL);
      } catch (ex) {
        errEl.textContent = ex.message || 'Signing in did not work. Please check your email and password.';
      }
    });
  }

  // Silent unlock when a password was saved in this browser.
  let saved = null;
  try { saved = localStorage.getItem(SAVED_KEY); } catch (_) {}
  if (saved) {
    form.style.display = 'none';
    busy.style.display = 'block';
    unlock(saved, false, true);
  }
})();
</script>
</body>
</html>
    <?php
    exit;
}
