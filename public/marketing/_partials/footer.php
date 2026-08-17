<footer class="footer">
  <div class="footer__inner">

    <div class="footer__brand">
      <p class="footer__name"><?= e(APP_NAME) ?></p>
      <p class="footer__note">A timed, invigilated examination platform for higher education.</p>
    </div>

    <nav class="footer__links" aria-label="Footer">
      <a href="<?= e(url('marketing/')) ?>">Home</a>
      <a href="<?= e(url('marketing/about.php')) ?>">About</a>
      <a href="<?= e(url('marketing/how-it-works.php')) ?>">How it works</a>
      <a href="<?= e(url('marketing/contact.php')) ?>">Contact</a>
      <a href="<?= e(url(LOGIN_URL_PATH)) ?>">Sign in</a>
    </nav>

    <div class="footer__legal">
      <p>
        Hero photograph by
        <a href="https://unsplash.com/@sanskritiuni" rel="noopener">Vishnu Mehra</a>
        on <a href="https://unsplash.com/photos/students-attending-a-lecture-in-a-modern-classroom-v3P_spLsOH0" rel="noopener">Unsplash</a>.
      </p>
      <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>.</p>
    </div>

  </div>
</footer>
</body>
</html>
