<?php
/**
 * Site navigation.
 * $page_key marks the current page so it can carry aria-current.
 */
$nav_links = [
    'about'        => ['href' => url('marketing/about.php'),         'label' => 'About'],
    'how-it-works' => ['href' => url('marketing/how-it-works.php'),  'label' => 'How it works'],
    'contact'      => ['href' => url('marketing/contact.php'),       'label' => 'Contact'],
];

$current = $page_key ?? '';
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
      <?php foreach ($nav_links as $key => $link): ?>
        <a href="<?= e($link['href']) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= e($link['label']) ?></a>
      <?php endforeach; ?>
    </nav>

    <a class="btn btn--primary btn--sm" href="<?= e(url(LOGIN_URL_PATH)) ?>">Sign in</a>

  </div>
</header>
