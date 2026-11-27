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
    <button type="button" class="btn btn--header btn--sm admin-only hidden" id="btn-import-html">Import HTML</button>

    <nav class="studio-workspaces" id="workspace-nav">
      <button type="button" class="studio-workspaces__btn is-active" data-workspace="studio">Studio</button>
      <button type="button" class="studio-workspaces__btn admin-only hidden" data-workspace="library">Library</button>
      <button type="button" class="studio-workspaces__btn admin-only hidden" data-workspace="design">Design</button>
      <button type="button" class="studio-workspaces__btn" data-workspace="media">Media</button>
      <button type="button" class="studio-workspaces__btn admin-only hidden" data-workspace="users">Users</button>
      <button type="button" class="studio-workspaces__btn admin-only hidden" data-workspace="plugins">Plugins</button>
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
          <div class="contact-form__field">
            <label for="page-header-mode">Header</label>
            <select id="page-header-mode">
              <option value="inherit">Sitewide</option>
              <option value="custom">Custom</option>
              <option value="none">None</option>
            </select>
          </div>
          <div class="contact-form__field hidden" id="page-header-wrap">
            <label for="page-header-html">Custom header HTML</label>
            <textarea id="page-header-html" rows="6" class="code-textarea" spellcheck="false"></textarea>
          </div>
          <div class="contact-form__field">
            <label for="page-footer-mode">Footer</label>
            <select id="page-footer-mode">
              <option value="inherit">Sitewide</option>
              <option value="custom">Custom</option>
              <option value="none">None</option>
            </select>
          </div>
          <div class="contact-form__field hidden" id="page-footer-wrap">
            <label for="page-footer-html">Custom footer HTML</label>
            <textarea id="page-footer-html" rows="5" class="code-textarea" spellcheck="false"></textarea>
          </div>
          <div class="contact-form__field">
            <span class="field-label">Global assets on this page</span>
            <div id="page-asset-excludes" class="page-asset-excludes muted">Load Design assets to choose exclusions.</div>
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
            <option value="block-js">Block TypeScript</option>
            <option value="template-html">Template HTML</option>
            <option value="template-css">Template CSS</option>
            <option value="template-js">Template TypeScript</option>
            <option value="page-css">Page CSS</option>
            <option value="page-js">Page TypeScript</option>
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
          <div class="panel-head__actions">
            <button type="button" class="btn btn--ghost btn--sm admin-only hidden" id="btn-import-template">Import HTML</button>
            <button type="button" class="btn btn--primary btn--sm admin-only hidden" id="btn-new-template">New</button>
          </div>
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
        <h3>TypeScript &amp; Tailwind</h3>
        <p class="muted">Scripts are compiled as TypeScript. Tailwind scans published HTML and emits only used utilities. Prefer <code>esbuild</code> / <code>tailwindcss</code> on PATH; built-in compilers are used otherwise.</p>
        <label class="toolchain-check"><input type="checkbox" id="ts-enabled" checked> Compile TypeScript</label>
        <label class="toolchain-check"><input type="checkbox" id="tw-enabled" checked> Compile Tailwind CSS</label>
        <p class="muted" id="toolchain-engines"></p>
        <div class="contact-form__field">
          <label for="tw-config">tailwind.config.ts</label>
          <textarea id="tw-config" rows="16" class="code-textarea" spellcheck="false"></textarea>
        </div>
        <div class="contact-form__actions">
          <button type="button" class="btn btn--primary" id="btn-save-toolchain">Save toolchain</button>
        </div>
      </section>
      <section class="card card--flat">
        <h3>Design tokens</h3>
        <p class="muted">Flattened to CSS variables at publish time (<code>--color-brand</code>, <code>--font-body</code>, …). Tailwind color names match these tokens (<code>bg-brand</code>, <code>text-navy</code>).</p>
        <div id="token-fields"></div>
        <div class="contact-form__actions">
          <button type="button" class="btn btn--primary" id="btn-save-tokens">Save tokens</button>
        </div>
      </section>
      <section class="card card--flat">
        <h3>Site chrome</h3>
        <p class="muted">Sitewide header and footer injected where templates include <code>&lt;!-- sneeze:header --&gt;</code> / <code>&lt;!-- sneeze:footer --&gt;</code>. Pages can inherit, override, or hide them.</p>
        <div class="contact-form__field">
          <label for="chrome-header">Header HTML</label>
          <textarea id="chrome-header" rows="10" class="code-textarea" spellcheck="false"></textarea>
        </div>
        <div class="contact-form__field">
          <label for="chrome-footer">Footer HTML</label>
          <textarea id="chrome-footer" rows="8" class="code-textarea" spellcheck="false"></textarea>
        </div>
        <div class="contact-form__actions">
          <button type="button" class="btn btn--primary" id="btn-save-chrome">Save chrome</button>
        </div>
      </section>
      <section class="card card--flat">
        <h3>Global CSS</h3>
        <p class="muted">Inlined as one minified <code>&lt;style&gt;</code> in <code>&lt;head&gt;</code> on publish. Unused block CSS is omitted. Pages can exclude individual sheets.</p>
        <div class="contact-form__actions" style="margin-bottom:0.75rem">
          <button type="button" class="btn btn--ghost btn--sm" id="btn-add-stylesheet">Add stylesheet</button>
        </div>
        <div id="stylesheet-list"></div>
      </section>
      <section class="card card--flat">
        <h3>Global JS</h3>
        <p class="muted">Inlined as one minified <code>&lt;script&gt;</code> at the end of <code>&lt;body&gt;</code> so it does not block first paint. Empty scripts are not emitted. Pages can exclude individual scripts.</p>
        <div class="contact-form__actions" style="margin-bottom:0.75rem">
          <button type="button" class="btn btn--ghost btn--sm" id="btn-add-script">Add script</button>
        </div>
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
          <input type="file" id="media-upload" accept="image/*,.pdf,.svg,.woff,.woff2" hidden>
        </label>
      </div>
      <p class="muted">Files are stored in the studio (<code>sneeze/data/uploads</code>) until you publish. Publish copies used files into the site asset folder.</p>
      <div class="media-grid" id="media-grid"></div>
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

  <!-- Plugins -->
  <div class="workspace workspace--scroll" id="workspace-plugins">
    <section class="card card--flat">
      <h3>Plugins</h3>
      <p class="muted" id="plugins-api">Plugin API</p>
      <p class="muted">Drop a folder in <code>data/plugins/{id}</code> or upload a zip. Installed plugins survive Update Sneeze. Enable a plugin to load it.</p>
      <div class="contact-form__field">
        <label for="plugin-zip">Plugin zip</label>
        <input type="file" id="plugin-zip" accept=".zip,application/zip">
      </div>
      <div class="contact-form__actions">
        <button type="button" class="btn btn--primary btn--sm" id="btn-install-plugin">Install</button>
      </div>
      <div id="plugin-list" class="plugin-list"></div>
    </section>
  </div>

  <!-- Settings -->
  <div class="workspace workspace--scroll" id="workspace-settings">
    <section class="card card--flat">
      <h3>Settings</h3>
      <p class="muted" id="settings-version">Sneeze</p>
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
          <label for="settings-upload-dir">Published asset folder</label>
          <input type="text" id="settings-upload-dir" placeholder="uploads">
          <p class="muted" style="margin-top:0.5rem">Relative to the site folder. Used files are copied here on Publish.</p>
        </div>
        <p class="muted" id="settings-draft-dir">Studio upload folder: <code>sneeze/data/uploads</code> (not public).</p>
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
    <section class="card card--flat" style="margin-top:1.5rem">
      <h3>Update Sneeze</h3>
      <p class="muted">Upload the zip from GitHub <strong>Code → Download ZIP</strong>. Runtime data (<code>data/</code>, database, uploads, backups) is preserved. Requires the PHP <code>zip</code> extension.</p>
      <div class="contact-form__field">
        <label for="settings-update-zip">Package zip</label>
        <input type="file" id="settings-update-zip" accept=".zip,application/zip">
      </div>
      <div class="contact-form__actions">
        <button type="button" class="btn btn--primary" id="btn-update-sneeze">Install update</button>
      </div>
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

  <dialog class="contact-modal contact-modal--wide" id="modal-import">
    <div class="contact-modal__inner">
      <button type="button" class="contact-modal__close" data-close-modal>&times;</button>
      <h2 class="contact-modal__title">Import HTML</h2>
      <p class="contact-modal__lead">Turn an existing page into a Sneeze template (document chrome) plus a page whose main content is an HTML block. Local images are copied into the studio library.</p>
      <div class="contact-form__field">
        <label for="import-file">Upload an HTML file</label>
        <input type="file" id="import-file" accept=".html,.htm,text/html">
      </div>
      <p class="muted">Or browse the site folder:</p>
      <div class="breadcrumb" id="import-breadcrumb"></div>
      <ul class="dir-list" id="import-dir-list"></ul>
      <p class="muted" id="import-selected">No site file selected.</p>
      <div class="contact-form__field">
        <label for="import-name">Name</label>
        <input type="text" id="import-name" placeholder="About">
      </div>
      <label class="toolchain-check"><input type="checkbox" id="import-create-template" checked> Create a template from the document chrome</label>
      <label class="toolchain-check"><input type="checkbox" id="import-create-page" checked> Create a page from the main content</label>
      <label class="toolchain-check"><input type="checkbox" id="import-copy-assets" checked> Copy local images and other assets into the studio library</label>
      <div class="contact-form__actions">
        <button type="button" class="btn btn--primary" id="btn-import-run">Import</button>
      </div>
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
