<?php
/**
 * Page head: the title block that opens every standard page.
 *
 * Presentation only. It reads state, never changes it, and no controller
 * needs to know it exists.
 *
 * Set these before requiring it. Each is raw HTML, escaped by the caller
 * exactly as it was when this markup lived inline in each view, so moving a
 * page onto the partial cannot change what reaches the browser:
 *
 *   $page_title       required. The <h1>.
 *   $page_lead        optional. The supporting line beneath the title.
 *   $page_lead_class  optional. Extra class on the lead, e.g. 'small'.
 *   $page_actions     optional. Buttons or links pinned to the right.
 *
 * Usage:
 *   $page_title = 'Question bank';
 *   $page_lead  = htmlspecialchars($course['title']);
 *   require APP_ROOT . '/app/views/_partials/page_head.php';
 *
 * The variables are cleared afterwards so a second page head on the same
 * request cannot inherit a stale lead or a stale set of actions.
 */

$ph_title   = $page_title      ?? '';
$ph_lead    = $page_lead       ?? '';
$ph_class   = $page_lead_class ?? '';
$ph_actions = $page_actions    ?? '';
?>
    <div class="page__head">
        <div>
            <h1 class="page__title"><?= $ph_title ?></h1>
<?php if ($ph_lead !== ''): ?>
            <p class="page__lead<?= $ph_class !== '' ? ' ' . $ph_class : '' ?>"><?= $ph_lead ?></p>
<?php endif; ?>
        </div>
<?php if ($ph_actions !== ''): ?>
        <div class="page__actions">
<?= $ph_actions ?>
        </div>
<?php endif; ?>
    </div>
<?php
unset($page_title, $page_lead, $page_lead_class, $page_actions,
      $ph_title, $ph_lead, $ph_class, $ph_actions);
