<?php
/**
 * portal_examples.php — the "example studies" tiles of projects.php.
 *
 * One tile per study of the paper: title, one-sentence summary and one row
 * per condition with two icon buttons: play = the chat as participants see
 * it (test link, new tab), cog = the settings as the visitor's own draft copy
 * (settings.php?example=ID, stored only on Save); each shows a tooltip on
 * hover (data-tip). The list comes from includes/examples.php.
 *
 * @author Marc Becker
 */
require_once __DIR__ . '/examples.php';
$example_studies = example_studies_resolved();
// A server without example projects (EXAMPLE_PROJECTS in config.local.php)
// shows no tiles at all; a condition without a project is listed without links.
$examples_available = false;
foreach ($example_studies as $study) {
    foreach ($study['conditions'] as $cond) {
        $examples_available = $examples_available || !empty($cond['available']);
    }
}
if (!$examples_available) {
    return;
}
?>
<section class="examples<?= !empty($account_user) ? ' dash-examples' : '' ?>" id="examples">
  <h2 class="block-title">Explore exemplary studies</h2>
  <p class="block-sub">Below are three exemplary studies from our paper, with two conditions each. The
  <span class="inline-ico"><i class="fas fa-play"></i></span> button lets you experience the interaction, and the
  <span class="inline-ico"><i class="fas fa-cog"></i></span> button opens the settings of the condition as a copy that you can
  adapt and save as a new study.</p>
  <div class="tiles">
    <?php foreach ($example_studies as $i => $study): ?>
    <div class="card ex-tile">
      <div class="ex-head">
        <span class="ex-num"><?= $i + 1 ?></span>
        <h3><?= htmlspecialchars($study['title']) ?></h3>
      </div>
      <p><?= htmlspecialchars($study['summary']) ?></p>
      <div class="ex-conds">
        <?php foreach ($study['conditions'] as $cond): ?>
        <div class="ex-cond">
          <span class="ex-cond-name"><?= htmlspecialchars($cond['name']) ?></span>
          <?php if (!empty($cond['available'])): ?>
          <span class="ex-cond-btns">
            <a class="icon-btn" href="aiTeam.php?project=<?= urlencode($cond['project_id']) ?>&amp;conversationID=TEST" target="_blank" rel="noopener"
               data-tip="Experience the interaction" aria-label="Experience the interaction"><i class="fas fa-play"></i></a>
            <a class="icon-btn" href="settings.php?example=<?= urlencode($cond['project_id']) ?>"
               data-tip="Open the settings as a copy" aria-label="Open the settings as a copy"><i class="fas fa-cog"></i></a>
          </span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
