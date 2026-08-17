<?php
/**
 * Site navigation.
 *
 * While the landing page is the only marketing page, these point at sections on
 * it. They become cross-page links once About / How it works / Contact exist.
 */
$landing = url('marketing/');

$nav_links = [
    ['href' => $landing . '#roles',    'label' => 'Who it is for'],
    ['href' => $landing . '#features', 'label' => 'What it does'],
    ['href' => $landing . '#process',  'label' => 'How it works'],
];
?>
<header class="nav">
  <div class="nav__inner">

    <a class="wordmark" href="<?= e(url('marketing/')) ?>">
      <span class="wordmark__mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
          <circle cx="12" cy="12" r="9"></circle>
          <path d="M12 7v5.2l3.4 2"></path>
        </svg>
      </span>
      <span class="wordmark__text"><?= e(APP_NAME) ?></span>
    </a>

    <nav class="nav__links" aria-label="Primary">
      <?php foreach ($nav_links as $link): ?>
        <a href="<?= e($link['href']) ?>"><?= e($link['label']) ?></a>
      <?php endforeach; ?>
    </nav>

    <a class="btn btn--primary btn--sm" href="<?= e(url(LOGIN_URL_PATH)) ?>">Sign in</a>

  </div>
</header>
