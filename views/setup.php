<?php
/** @var string $assetBase */
/** @var string $title */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Setup — <?= htmlspecialchars($title) ?></title>
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
      <h1>Sneeze Setup</h1>
      <p class="contact-modal__lead">Configure your drop-in admin panel. Your existing site will not be modified until you edit files.</p>

      <div class="setup-steps" id="setup-steps">
        <span class="is-active" data-step="1">1. Site folder</span>
        <span data-step="2">2. URL path</span>
        <span data-step="3">3. Admin account</span>
      </div>

      <div id="setup-status" class="contact-form__status hidden" role="alert"></div>

      <div id="step-1">
        <p class="contact-modal__lead">Browse to the folder containing your HTML files. Default: parent folder (<code>../</code>).</p>
        <div class="breadcrumb" id="dir-breadcrumb"></div>
        <ul class="dir-list" id="dir-list"></ul>
        <p id="dir-html-preview" class="card__meta"></p>
        <div class="contact-form__actions">
          <button type="button" class="btn btn--primary" id="btn-step1-next" disabled>Continue</button>
        </div>
      </div>

      <div id="step-2" class="hidden">
        <div class="contact-form__field">
          <label for="site-web-base">Public URL path to your site</label>
          <input type="text" id="site-web-base" value="/" placeholder="/ or /subfolder/">
          <p class="card__meta" style="margin-top:0.5rem">Used for preview asset paths. Usually <code>/</code> if the site is at the domain root.</p>
        </div>
        <div class="contact-form__field">
          <label for="site-title">Site title (admin display)</label>
          <input type="text" id="site-title" value="Sneeze Admin">
        </div>
        <div class="contact-form__field">
          <label for="upload-dir">Upload folder (inside site)</label>
          <input type="text" id="upload-dir" value="uploads">
        </div>
        <div class="contact-form__actions">
          <button type="button" class="btn btn--ghost" id="btn-step2-back">Back</button>
          <button type="button" class="btn btn--primary" id="btn-step2-next">Continue</button>
        </div>
      </div>

      <div id="step-3" class="hidden">
        <div class="contact-form__field">
          <label for="admin-username">Admin username</label>
          <input type="text" id="admin-username" autocomplete="username" required>
        </div>
        <div class="contact-form__field">
          <label for="admin-password">Password (min 8 characters)</label>
          <input type="password" id="admin-password" autocomplete="new-password" required minlength="8">
        </div>
        <div class="contact-form__field">
          <label for="admin-password2">Confirm password</label>
          <input type="password" id="admin-password2" autocomplete="new-password" required>
        </div>
        <div class="contact-form__actions">
          <button type="button" class="btn btn--ghost" id="btn-step3-back">Back</button>
          <button type="button" class="btn btn--primary" id="btn-finish">Complete setup</button>
        </div>
      </div>
    </div>
  </div>
  <script type="module" src="<?= htmlspecialchars($assetBase) ?>/js/setup.js"></script>
</body>
</html>
