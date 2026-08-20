<?php
/**
 * Application top bar — the signed-in counterpart to the marketing site's nav.
 *
 * Presentation only. It reads state, never changes it:
 *   $user         optional; the three dashboards already pass it, every other
 *                 view falls back to Auth::user() so no controller had to change
 *   $nav_current  optional string key marking the active link
 *
 * Include with:  require APP_ROOT . '/app/views/_partials/topbar.php';
 */

$u = $user ?? (class_exists('Auth') && Auth::check() ? Auth::user() : null);

if ($u === null) {
    return;   // signed out — nothing to draw
}

$role = $u['role'] ?? '';

$nav = [
    'admin' => [
        'dashboard' => ['admin/dashboard', 'Dashboard'],
        'users'     => ['admin/users',     'Users'],
        'courses'   => ['admin/courses',   'Courses'],
        'analytics' => ['admin/analytics', 'Analytics'],
    ],
    'lecturer' => [
        'dashboard' => ['lecturer/dashboard', 'My courses'],
        'grading'   => ['lecturer/grading',   'Grading queue'],
    ],
    'student' => [
        'dashboard' => ['student/dashboard', 'My exams'],
    ],
];

$links   = $nav[$role] ?? [];
$current = $nav_current ?? '';
$home    = $links['dashboard'][0] ?? '';
?>
<header class="topbar">
  <div class="topbar__inner">

    <div class="topbar__brand">
      <a class="wordmark" href="<?= BASE_URL . $home ?>">
        <span class="wordmark__mark" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 7v5.2l3.4 2"></path>
          </svg>
        </span>
        <span class="wordmark__text"><?= htmlspecialchars(APP_NAME) ?></span>
      </a>
      <?php if ($role !== ''): ?>
        <span class="topbar__role"><?= htmlspecialchars($role) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($links): ?>
      <nav class="topbar__nav" aria-label="Primary">
        <?php foreach ($links as $key => [$path, $label]): ?>
          <a href="<?= BASE_URL . $path ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <div class="topbar__user">
      <strong><?= htmlspecialchars($u['name'] ?? '') ?></strong>
      <a href="<?= BASE_URL ?>auth/logout">Log out</a>
    </div>

  </div>
</header>
