<?php
/** @var string $assetBase */
/** @var string $title */
$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/tokens.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/base.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/components.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
  <style>
    .CodeMirror { min-height: 180px; font-size: 13px; border-top: 1px solid var(--color-border); }
    .CodeMirror-gutters { background: var(--color-white); border-right: 1px solid var(--color-border); }
  </style>
</head>
<body>
  <header class="admin-header">
    <div class="admin-header__inner">
      <div class="admin-header__brand" id="app-title"><?= htmlspecialchars($title) ?></div>
      <nav>
        <ul class="admin-nav" id="admin-nav">
          <li><button type="button" class="admin-nav__link is-active" data-panel="pages">Pages</button></li>
          <li><button type="button" class="admin-nav__link" data-panel="editor">Editor</button></li>
          <li><button type="button" class="admin-nav__link" data-panel="media">Media</button></li>
          <li><button type="button" class="admin-nav__link admin-only hidden" data-panel="users">Users</button></li>
          <li><button type="button" class="admin-nav__link admin-only hidden" data-panel="settings">Settings</button></li>
        </ul>
      </nav>
      <div class="admin-header__user">
        <span id="user-label"><?= htmlspecialchars($user['username'] ?? '') ?></span>
        <button type="button" class="btn btn--header btn--sm" id="btn-logout">Logout</button>
      </div>
    </div>
  </header>

  <main class="admin-main">
    <div id="global-status" class="contact-form__status hidden" role="alert"></div>

    <!-- Pages panel -->
    <section class="admin-panel is-active" id="panel-pages">
      <div class="admin-split">
        <div class="card card--flat">
          <h3>HTML files</h3>
          <ul class="events-list" id="file-list"></ul>
        </div>
        <div class="card card--flat">
          <h3>Getting started</h3>
          <p>Select a file from the list to open it in the editor. Files are backed up automatically when opened.</p>
        </div>
      </div>
    </section>

    <!-- Editor panel -->
    <section class="admin-panel" id="panel-editor">
      <div class="card card--flat">
        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;margin-bottom:1rem">
          <h3 id="editor-filename" style="margin:0;flex:1">No file open</h3>
          <span id="unsaved-badge" class="badge badge--unsaved hidden">Unsaved</span>
          <button type="button" class="btn btn--ghost btn--sm" id="btn-view-basic">Basic</button>
          <button type="button" class="btn btn--ghost btn--sm" id="btn-view-advanced">Advanced</button>
          <button type="button" class="btn btn--ghost btn--sm" id="btn-preview">Preview</button>
          <a href="#" class="btn btn--ghost btn--sm hidden" id="btn-live" target="_blank" rel="noopener">Open live</a>
          <button type="button" class="btn btn--primary btn--sm" id="btn-save">Save</button>
        </div>

        <div id="view-basic">
          <div class="editor-layout">
            <div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem">
                <strong>Sections</strong>
                <button type="button" class="btn btn--ghost btn--sm" id="btn-add-section">+ Add</button>
              </div>
              <ul class="section-list" id="section-list"></ul>
            </div>
            <div>
              <div class="editor-toolbar" id="basic-toolbar">
                <button type="button" class="btn btn--ghost btn--sm" data-cmd="bold"><b>B</b></button>
                <button type="button" class="btn btn--ghost btn--sm" data-cmd="italic"><i>I</i></button>
                <button type="button" class="btn btn--ghost btn--sm" data-cmd="createLink">Link</button>
                <button type="button" class="btn btn--ghost btn--sm" data-cmd="insertImage">Image</button>
              </div>
              <div class="editor-canvas" id="section-canvas" contenteditable="true"></div>
            </div>
          </div>
        </div>

        <div id="view-advanced" class="hidden">
          <div class="segment-accordion" id="segment-accordion"></div>
        </div>

        <div class="editor-preview hidden" id="editor-preview">
          <iframe id="preview-frame" title="Preview"></iframe>
        </div>
      </div>
    </section>

    <!-- Media panel -->
    <section class="admin-panel" id="panel-media">
      <div class="card card--flat">
        <h3>Media library</h3>
        <div class="contact-form__actions" style="margin-bottom:1rem">
          <label class="btn btn--primary btn--sm">
            Upload file
            <input type="file" id="media-upload" accept="image/*,.pdf,.svg" hidden>
          </label>
        </div>
        <div class="media-grid" id="media-grid"></div>
      </div>
    </section>

    <!-- Users panel -->
    <section class="admin-panel" id="panel-users">
      <div class="card card--flat">
        <h3>Users</h3>
        <table class="users-table">
          <thead><tr><th>Username</th><th>Role</th><th>Created</th><th></th></tr></thead>
          <tbody id="users-tbody"></tbody>
        </table>
        <h3 style="margin-top:2rem">Add user</h3>
        <form id="add-user-form">
          <div class="contact-form__field">
            <label for="new-username">Username</label>
            <input type="text" id="new-username" required>
          </div>
          <div class="contact-form__field">
            <label for="new-password">Password</label>
            <input type="password" id="new-password" required minlength="8">
          </div>
          <div class="contact-form__field">
            <label for="new-role">Role</label>
            <select id="new-role">
              <option value="editor">Editor</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="contact-form__actions">
            <button type="submit" class="btn btn--primary">Add user</button>
          </div>
        </form>
      </div>
    </section>

    <!-- Settings panel -->
    <section class="admin-panel" id="panel-settings">
      <div class="card card--flat">
        <h3>Settings</h3>
        <form id="settings-form">
          <div class="contact-form__field">
            <label for="settings-site-root">Site root (filesystem path)</label>
            <input type="text" id="settings-site-root" readonly>
            <button type="button" class="btn btn--ghost btn--sm" id="btn-browse-root" style="margin-top:0.5rem">Browse…</button>
          </div>
          <div class="contact-form__field">
            <label for="settings-web-base">Public URL base</label>
            <input type="text" id="settings-web-base">
          </div>
          <div class="contact-form__field">
            <label for="settings-upload-dir">Upload directory</label>
            <input type="text" id="settings-upload-dir">
          </div>
          <div class="contact-form__field">
            <label for="settings-retention">Backup retention</label>
            <input type="number" id="settings-retention" min="1" max="100">
          </div>
          <div class="contact-form__field">
            <label for="settings-title">Admin title</label>
            <input type="text" id="settings-title">
          </div>
          <div class="contact-form__actions">
            <button type="submit" class="btn btn--primary">Save settings</button>
          </div>
        </form>
      </div>
    </section>
  </main>

  <dialog class="contact-modal contact-modal--wide" id="modal-add-section">
    <div class="contact-modal__inner">
      <button type="button" class="contact-modal__close" data-close-modal>&times;</button>
      <h2 class="contact-modal__title">Add section</h2>
      <p class="contact-modal__lead">Choose a template to insert into the page.</p>
      <div id="template-list" class="events-list"></div>
    </div>
  </dialog>

  <dialog class="contact-modal" id="modal-browse">
    <div class="contact-modal__inner">
      <button type="button" class="contact-modal__close" data-close-modal>&times;</button>
      <h2 class="contact-modal__title">Select site folder</h2>
      <div class="breadcrumb" id="modal-breadcrumb"></div>
      <ul class="dir-list" id="modal-dir-list"></ul>
      <div class="contact-form__actions">
        <button type="button" class="btn btn--primary" id="modal-select-dir" disabled>Select folder</button>
      </div>
    </div>
  </dialog>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
  <script type="module" src="<?= htmlspecialchars($assetBase) ?>/js/admin.js"></script>
</body>
</html>
