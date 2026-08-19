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
  <?php require __DIR__ . '/partials/favicon.php'; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/tokens.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/base.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/components.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/admin.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/css/studio.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
</head>
<body class="studio-app">
  <header class="studio-top">
    <div class="studio-top__brand">
      <img src="<?= htmlspecialchars($assetBase) ?>/img/favicon.png" alt="" class="brand-logo brand-logo--icon" width="32" height="32">
      <span id="app-title"><?= htmlspecialchars($title) ?></span>
    </div>

    <label class="studio-page-picker">
      <span class="sr-only">Page</span>
      <select id="page-select"></select>
    </label>
    <button type="button" class="btn btn--header btn--sm" id="btn-new-page">New page</button>

    <nav class="studio-workspaces" id="workspace-nav">
      <button type="button" class="studio-workspaces__btn is-active" data-workspace="studio">Studio</button>
      <button type="button" class="studio-workspaces__btn admin-only hidden" data-workspace="library">Library</button>
      <button type="button" class="studio-workspaces__btn admin-only hidden" data-workspace="design">Design</button>
      <button type="button" class="studio-workspaces__btn" data-workspace="media">Media</button>
      <button type="button" class="studio-workspaces__btn admin-only hidden" data-workspace="cms">CMS</button>
      <button type="button" class="studio-workspaces__btn admin-only hidden" data-workspace="users">Users</button>
      <button type="button" class="studio-workspaces__btn admin-only hidden" data-workspace="settings">Settings</button>
    </nav>

    <div class="studio-top__status">
      <span id="save-state" class="save-state">Ready</span>
      <button type="button" class="btn btn--header btn--sm" id="btn-save-draft">Save draft</button>
      <button type="button" class="btn btn--primary btn--sm" id="btn-publish">Publish</button>
    </div>

    <div class="admin-header__user">
      <span id="user-label"><?= htmlspecialchars($user['username'] ?? '') ?></span>
      <button type="button" class="btn btn--header btn--sm" id="btn-logout">Logout</button>
    </div>
  </header>

  <div id="global-status" class="studio-toast hidden" role="alert"></div>

  <!-- Studio -->
  <div class="workspace is-active" id="workspace-studio">
    <section class="preview-pane">
      <div class="preview-toolbar">
        <div class="preview-widths">
          <button type="button" class="is-active" data-width="100%">Desktop</button>
          <button type="button" data-width="768px">Tablet</button>
          <button type="button" data-width="390px">Phone</button>
        </div>
        <a href="#" class="preview-live hidden" id="btn-live" target="_blank" rel="noopener">Open published</a>
      </div>
      <div class="preview-stage" id="preview-stage">
        <iframe id="preview-frame" title="Live preview" sandbox="allow-scripts allow-same-origin"></iframe>
      </div>
    </section>

    <aside class="side-pane">
      <div class="side-tabs" id="side-tabs">
        <button type="button" class="is-active" data-tab="blocks">Blocks</button>
        <button type="button" data-tab="write">Write</button>
        <button type="button" data-tab="code">Code</button>
      </div>

      <div class="side-tab is-active" id="tab-blocks">
        <div class="palette" id="block-palette"></div>
        <div class="outline-head">
          <strong>Outline</strong>
          <span class="muted" id="selected-label"></span>
        </div>
        <div class="outline" id="block-outline"></div>
      </div>

      <div class="side-tab" id="tab-write">
        <form id="page-meta" class="meta-form">
          <div class="contact-form__field">
            <label for="page-title">Page title</label>
            <input type="text" id="page-title">
          </div>
          <div class="contact-form__field">
            <label for="page-slug">Slug</label>
            <input type="text" id="page-slug">
          </div>
          <div class="contact-form__field">
            <label for="page-seo">SEO description</label>
            <textarea id="page-seo" rows="2"></textarea>
          </div>
        </form>
        <div id="write-fields" class="write-fields">
          <p class="muted">Select a block in the outline or preview.</p>
        </div>
      </div>

      <div class="side-tab" id="tab-code">
        <div class="code-toolbar">
          <select id="code-target">
            <option value="block-html">Block HTML</option>
            <option value="block-css">Block CSS</option>
            <option value="block-js">Block JS</option>
            <option value="template-html">Template HTML</option>
            <option value="template-css">Template CSS</option>
            <option value="page-css">Page CSS</option>
            <option value="page-js">Page JS</option>
          </select>
          <button type="button" class="btn btn--ghost btn--sm admin-only hidden" id="btn-save-code">Save definition</button>
        </div>
        <div class="codemirror-wrap" id="code-wrap"></div>
      </div>
    </aside>
  </div>

  <!-- Library -->
  <div class="workspace workspace--scroll" id="workspace-library">
    <div class="library-grid">
      <section class="card card--flat">
        <div class="panel-head">
          <h3>Templates</h3>
          <button type="button" class="btn btn--primary btn--sm admin-only hidden" id="btn-new-template">New</button>
        </div>
        <ul class="events-list" id="template-list"></ul>
      </section>
      <section class="card card--flat">
        <div class="panel-head">
          <h3>Block types</h3>
          <button type="button" class="btn btn--primary btn--sm admin-only hidden" id="btn-new-block-type">New</button>
        </div>
        <ul class="events-list" id="block-type-list"></ul>
      </section>
      <section class="card card--flat library-editor">
        <h3 id="library-title">Select an item</h3>
        <div id="library-form"></div>
      </section>
    </div>
  </div>

  <!-- Design -->
  <div class="workspace workspace--scroll" id="workspace-design">
    <div class="library-grid">
      <section class="card card--flat">
        <h3>Design tokens</h3>
        <p class="muted">Flattened to CSS variables at publish time (<code>--color-brand</code>, <code>--font-body</code>, …).</p>
        <div id="token-fields"></div>
        <div class="contact-form__actions">
          <button type="button" class="btn btn--primary" id="btn-save-tokens">Save tokens</button>
        </div>
      </section>
      <section class="card card--flat">
        <h3>Global CSS</h3>
        <div id="stylesheet-list"></div>
      </section>
      <section class="card card--flat">
        <h3>Global JS</h3>
        <div id="script-list"></div>
      </section>
    </div>
  </div>

  <!-- Media -->
  <div class="workspace workspace--scroll" id="workspace-media">
    <section class="card card--flat">
      <div class="panel-head">
        <h3>Media library</h3>
        <label class="btn btn--primary btn--sm">
          Upload
          <input type="file" id="media-upload" accept="image/*,.pdf,.svg" hidden>
        </label>
      </div>
      <div class="media-grid" id="media-grid"></div>
    </section>
  </div>

  <!-- CMS -->
  <div class="workspace workspace--scroll" id="workspace-cms">
    <section class="card card--flat">
      <h3>Headless CMS cache</h3>
      <p class="muted">Fetch remote JSON from the admin. Entries are stored in SQLite and can bind to block fields. Visitors only ever see published HTML — they never call the CMS.</p>
      <form id="cms-form" class="meta-form">
        <input type="hidden" id="cms-id">
        <div class="contact-form__field">
          <label for="cms-name">Source name</label>
          <input type="text" id="cms-name" required>
        </div>
        <div class="contact-form__field">
          <label for="cms-url">JSON URL</label>
          <input type="url" id="cms-url" placeholder="https://example.com/api/posts">
        </div>
        <div class="contact-form__field">
          <label for="cms-list-path">List path</label>
          <input type="text" id="cms-list-path" placeholder="data.items (blank if the root is an array)">
        </div>
        <div class="contact-form__field">
          <label for="cms-id-path">ID path</label>
          <input type="text" id="cms-id-path" value="id">
        </div>
        <div class="contact-form__field">
          <label for="cms-mapping">Field mapping (JSON)</label>
          <textarea id="cms-mapping" rows="6" placeholder='{"title":"title","body":"content.html"}'></textarea>
        </div>
        <div class="contact-form__actions">
          <button type="submit" class="btn btn--primary">Save source</button>
          <button type="button" class="btn btn--ghost" id="btn-cms-sync">Sync now</button>
        </div>
      </form>
      <ul class="events-list" id="cms-source-list"></ul>
      <h3 style="margin-top:1.5rem">Cached entries</h3>
      <pre class="cms-entries" id="cms-entries">No source selected.</pre>
    </section>
  </div>

  <!-- Users -->
  <div class="workspace workspace--scroll" id="workspace-users">
    <section class="card card--flat">
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
    </section>
  </div>

  <!-- Settings -->
  <div class="workspace workspace--scroll" id="workspace-settings">
    <section class="card card--flat">
      <h3>Settings</h3>
      <form id="settings-form">
        <div class="contact-form__field">
          <label for="settings-site-root">Publish folder (filesystem)</label>
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
          <label for="settings-retention">Publish backup retention</label>
          <input type="number" id="settings-retention" min="1" max="100">
        </div>
        <div class="contact-form__field">
          <label for="settings-title">Admin / site title</label>
          <input type="text" id="settings-title">
        </div>
        <div class="contact-form__actions">
          <button type="submit" class="btn btn--primary">Save settings</button>
          <button type="button" class="btn btn--ghost" id="btn-publish-all">Publish all pages</button>
        </div>
      </form>
    </section>
  </div>

  <dialog class="contact-modal" id="modal-browse">
    <div class="contact-modal__inner">
      <button type="button" class="contact-modal__close" data-close-modal>&times;</button>
      <h2 class="contact-modal__title">Select publish folder</h2>
      <div class="breadcrumb" id="modal-breadcrumb"></div>
      <ul class="dir-list" id="modal-dir-list"></ul>
      <div class="contact-form__actions">
        <button type="button" class="btn btn--primary" id="modal-select-dir" disabled>Select folder</button>
      </div>
    </div>
  </dialog>

  <dialog class="contact-modal" id="modal-new-page">
    <div class="contact-modal__inner">
      <button type="button" class="contact-modal__close" data-close-modal>&times;</button>
      <h2 class="contact-modal__title">New page</h2>
      <form id="new-page-form">
        <div class="contact-form__field">
          <label for="new-page-title">Title</label>
          <input type="text" id="new-page-title" required>
        </div>
        <div class="contact-form__field">
          <label for="new-page-slug">Slug</label>
          <input type="text" id="new-page-slug" placeholder="about">
        </div>
        <div class="contact-form__actions">
          <button type="submit" class="btn btn--primary">Create</button>
        </div>
      </form>
    </div>
  </dialog>

  <dialog class="contact-modal contact-modal--wide" id="modal-media-pick">
    <div class="contact-modal__inner">
      <button type="button" class="contact-modal__close" data-close-modal>&times;</button>
      <h2 class="contact-modal__title">Choose image</h2>
      <div class="media-grid" id="media-pick-grid"></div>
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
