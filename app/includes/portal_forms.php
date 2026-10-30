<?php
/**
 * portal_forms.php — the create/open study forms of projects.php.
 *
 * Included exactly once per page, inside the study dialog (#formsCard);
 * projects.php shows one of the two forms and sets the dialog title.
 * $account_user comes from projects.php and only changes the wording.
 *
 * @author Marc Becker
 */
?>
<h2 id="formsTitle" data-open-title="<?= $account_user ? 'Add an existing study' : 'Open a study' ?>">New study</h2>

<!-- Create -->
<div id="createWrap" class="hidden">
  <p class="modal-sub">A study has its own ID and password. After creating the study, you set up the study in four steps.</p>
  <form id="createPane">
    <div class="field">
      <label class="label" for="createName">Study name</label>
      <input id="createName" class="input" type="text" maxlength="80" placeholder="e.g. Psychological safety pilot, wave 1">
    </div>
    <div class="field">
      <label class="label" for="createId">Study ID</label>
      <div class="input-group">
        <input id="createId" class="input mono" type="text" maxlength="16" pattern="[A-Za-z0-9]{16}" required title="16 letters and digits (A to Z, a to z, 0 to 9)">
        <button type="button" class="btn" id="regenId" title="Generate a new ID"><i class="fas fa-sync-alt"></i></button>
      </div>
      <p class="hint">The ID has 16 characters and appears in every participant link. The ID cannot be changed later.</p>
    </div>
    <div class="field">
      <label class="label" for="createPassword">Password</label>
      <div class="input-group">
        <input id="createPassword" class="input mono" type="text" minlength="8" required>
        <button type="button" class="btn" id="regenPass" title="Generate a new password"><i class="fas fa-sync-alt"></i></button>
      </div>
      <p class="hint"><?= $account_user
          ? 'The password is stored in your account and shown once after creating the study. Participants do not see the password.'
          : 'Store the password safely, because a lost password cannot be recovered.' ?></p>
    </div>
    <p class="hint" id="createError" style="color: var(--danger);" role="alert"></p>
    <div class="row mt-2 modal-actions" style="justify-content: flex-end;">
      <button class="btn form-back" type="button">Cancel</button>
      <button class="btn btn-primary" id="createSubmit" type="submit">Create study</button>
    </div>
  </form>
</div>

<!-- Open -->
<div id="openWrap" class="hidden">
  <p class="modal-sub">Enter the ID and password of the study.</p>
  <form id="openPane">
    <div class="field">
      <label class="label" for="openId">Study ID</label>
      <input id="openId" class="input mono" type="text" maxlength="16" pattern="[A-Za-z0-9]{16}" required>
    </div>
    <div class="field">
      <label class="label" for="openPassword">Password</label>
      <input id="openPassword" class="input" type="password" required>
    </div>
    <?php if ($account_user): ?>
    <p class="hint">Opening works for any study, including studies that other people created and shared with you. The study
    is added to your account and afterwards opens without a password.</p>
    <?php endif; ?>
    <p class="hint" id="openError" style="color: var(--danger);" role="alert"></p>
    <div class="row mt-2 modal-actions" style="justify-content: flex-end;">
      <button class="btn form-back" type="button">Cancel</button>
      <button class="btn btn-primary" id="openSubmit" type="submit"><?= $account_user ? 'Open and add to my account' : 'Open study' ?></button>
    </div>
  </form>
</div>
