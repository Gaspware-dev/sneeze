<?php
/** @var string $assetBase */
/** @var string $title */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — <?= htmlspecialchars($title) ?></title>
  <?php require __DIR__ . '/partials/favicon.php'; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/tokens.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/base.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/components.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/admin.css">
</head>
<body>
  <div class="auth-page">
    <div class="auth-card card">
      <img src="<?= htmlspecialchars($assetBase) ?>/img/logo.png" alt="Sneeze" class="brand-logo brand-logo--full" width="200" height="56">
      <h1><?= htmlspecialchars($title) ?></h1>
      <p class="contact-modal__lead">Sign in to the studio.</p>
      <div id="login-status" class="contact-form__status contact-form__status--error hidden" role="alert"></div>
      <form id="login-form">
        <div class="contact-form__field">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" autocomplete="username" required>
        </div>
        <div class="contact-form__field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <div class="contact-form__actions">
          <button type="submit" class="btn btn--primary">Sign in</button>
        </div>
      </form>
    </div>
  </div>
  <script type="module" src="<?= htmlspecialchars($assetBase) ?>/js/login.js"></script>
</body>
</html>
