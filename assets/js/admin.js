import { api, setCsrf, showStatus, hideStatus, apiUrl } from './api.js';

const state = {
  user: null,
  csrf: '',
  currentFile: null,
  segments: [],
  activeSegmentId: null,
  dirty: false,
  viewMode: 'basic',
  cmEditors: new Map(),
  templates: [],
};

const globalStatus = document.getElementById('global-status');

async function init() {
  try {
    const data = await api('api/auth/me');
    state.user = data.user;
    state.csrf = data.csrf;
    setCsrf(data.csrf);
    if (data.config?.site_title) {
      document.getElementById('app-title').textContent = data.config.site_title;
      document.title = data.config.site_title;
    }
    if (state.user?.role === 'admin') {
      document.querySelectorAll('.admin-only').forEach((el) => el.classList.remove('hidden'));
    }
    bindNav();
    bindEditor();
    bindMedia();
    bindUsers();
    bindSettings();
    await loadFiles();
    await loadTemplates();
  } catch (e) {
    window.location.href = 'login';
  }
}

function bindNav() {
  document.querySelectorAll('.admin-nav__link').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.admin-nav__link').forEach((b) => b.classList.remove('is-active'));
      btn.classList.add('is-active');
      document.querySelectorAll('.admin-panel').forEach((p) => p.classList.remove('is-active'));
      document.getElementById(`panel-${btn.dataset.panel}`).classList.add('is-active');
      if (btn.dataset.panel === 'media') loadMedia();
      if (btn.dataset.panel === 'users') loadUsers();
      if (btn.dataset.panel === 'settings') loadSettings();
    });
  });

  document.getElementById('btn-logout').addEventListener('click', async () => {
    await api('api/auth/logout', { method: 'POST' });
    window.location.href = 'login';
  });
}

async function loadFiles() {
  const data = await api('api/files');
  const list = document.getElementById('file-list');
  list.innerHTML = '';
  data.files.forEach((file) => {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'event';
    btn.innerHTML = `<div class="event__details"><h3>${escapeHtml(file.name)}</h3><p>${escapeHtml(file.path)}</p></div>`;
    btn.addEventListener('click', () => openFile(file.path, btn));
    li.appendChild(btn);
    list.appendChild(li);
  });
}

async function openFile(path, btn) {
  document.querySelectorAll('#file-list .event').forEach((e) => e.classList.remove('is-active'));
  btn?.classList.add('is-active');

  const data = await api(`api/files/content?path=${encodeURIComponent(path)}`);
  state.currentFile = path;
  state.segments = data.segments;
  state.dirty = false;
  state.activeSegmentId = state.segments.find((s) => s.type === 'block')?.id || null;

  document.getElementById('editor-filename').textContent = path;
  document.getElementById('unsaved-badge').classList.add('hidden');
  document.getElementById('btn-live').href = data.live_url;
  document.getElementById('btn-live').classList.remove('hidden');

  renderSections();
  renderAdvanced();
  switchPanel('editor');
}

function switchPanel(name) {
  document.querySelectorAll('.admin-nav__link').forEach((b) => {
    b.classList.toggle('is-active', b.dataset.panel === name);
  });
  document.querySelectorAll('.admin-panel').forEach((p) => p.classList.remove('is-active'));
  document.getElementById(`panel-${name}`).classList.add('is-active');
}

function renderSections() {
  const list = document.getElementById('section-list');
  list.innerHTML = '';
  const blocks = state.segments.filter((s) => s.type === 'block');
  blocks.forEach((seg) => {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = seg.label;
    btn.classList.toggle('is-active', seg.id === state.activeSegmentId);
    btn.addEventListener('click', () => selectSection(seg.id));
    li.appendChild(btn);
    list.appendChild(li);
  });
  updateCanvas();
}

function selectSection(id) {
  if (state.dirty && state.activeSegmentId) {
    syncCanvasToSegment();
  }
  state.activeSegmentId = id;
  renderSections();
}

function updateCanvas() {
  const canvas = document.getElementById('section-canvas');
  const seg = state.segments.find((s) => s.id === state.activeSegmentId);
  if (!seg) {
    canvas.innerHTML = '<p style="color:var(--color-text-muted)">Select a section</p>';
    canvas.contentEditable = 'false';
    return;
  }
  canvas.contentEditable = 'true';
  const match = seg.content.match(/<[^>]+>([\s\S]*)<\/[^>]+>\s*$/i);
  canvas.innerHTML = match ? match[1] : seg.content;
}

function syncCanvasToSegment() {
  const seg = state.segments.find((s) => s.id === state.activeSegmentId);
  if (!seg) return;
  const canvas = document.getElementById('section-canvas');
  const inner = canvas.innerHTML;
  if (seg.content.match(/^<([a-z0-9-]+)/i)) {
    const tag = seg.content.match(/^<([a-z0-9-]+)/i)[1];
    const open = seg.content.match(/^<[^>]+>/)[0];
    const close = `</${tag}>`;
    seg.content = open + inner + close;
  } else {
    seg.content = inner;
  }
  markDirty();
}

function markDirty() {
  state.dirty = true;
  document.getElementById('unsaved-badge').classList.remove('hidden');
}

function bindEditor() {
  document.getElementById('section-canvas').addEventListener('input', markDirty);

  document.getElementById('btn-view-basic').addEventListener('click', () => {
    state.viewMode = 'basic';
    document.getElementById('view-basic').classList.remove('hidden');
    document.getElementById('view-advanced').classList.add('hidden');
  });

  document.getElementById('btn-view-advanced').addEventListener('click', async () => {
    if (state.dirty && state.activeSegmentId) syncCanvasToSegment();
    state.viewMode = 'advanced';
    document.getElementById('view-basic').classList.add('hidden');
    document.getElementById('view-advanced').classList.remove('hidden');
    await renderAdvanced();
  });

  document.getElementById('basic-toolbar').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-cmd]');
    if (!btn) return;
    const cmd = btn.dataset.cmd;
    if (cmd === 'createLink') {
      const url = prompt('URL:');
      if (url) document.execCommand('createLink', false, url);
    } else if (cmd === 'insertImage') {
      openMediaPicker((url) => document.execCommand('insertImage', false, url));
    } else {
      document.execCommand(cmd, false, null);
    }
    markDirty();
  });

  document.getElementById('btn-save').addEventListener('click', saveFile);
  document.getElementById('btn-preview').addEventListener('click', showPreview);

  document.getElementById('btn-add-section').addEventListener('click', () => {
    if (!state.currentFile) return;
    document.getElementById('modal-add-section').showModal();
    renderTemplates();
  });

  document.querySelectorAll('[data-close-modal]').forEach((btn) => {
    btn.addEventListener('click', () => btn.closest('dialog')?.close());
  });
}

async function loadTemplates() {
  const data = await api('api/files/templates');
  state.templates = data.templates;
}

function renderTemplates() {
  const list = document.getElementById('template-list');
  list.innerHTML = '';
  state.templates.forEach((t) => {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'event';
    btn.innerHTML = `<div class="event__details"><h3>${escapeHtml(t.label)}</h3></div>`;
    btn.addEventListener('click', async () => {
      document.getElementById('modal-add-section').close();
      const data = await api('api/files/section', {
        method: 'POST',
        body: JSON.stringify({ path: state.currentFile, template_id: t.id }),
      });
      state.segments = data.segments;
      state.activeSegmentId = state.segments.filter((s) => s.type === 'block').pop()?.id;
      renderSections();
      markDirty();
    });
    li.appendChild(btn);
    list.appendChild(li);
  });
}

async function saveFile() {
  if (!state.currentFile) return;
  hideStatus(globalStatus);
  try {
    if (state.viewMode === 'basic' && state.activeSegmentId) {
      syncCanvasToSegment();
    }
    if (state.viewMode === 'advanced') {
      syncCmToSegments();
    }
    await api('api/files/segments', {
      method: 'POST',
      body: JSON.stringify({
        path: state.currentFile,
        segments: state.segments,
        sanitize: state.viewMode === 'basic',
      }),
    });
    state.dirty = false;
    document.getElementById('unsaved-badge').classList.add('hidden');
    showStatus(globalStatus, 'Saved successfully');
    setTimeout(() => hideStatus(globalStatus), 3000);
  } catch (e) {
    showStatus(globalStatus, e.message, true);
  }
}

async function showPreview() {
  if (!state.currentFile) return;
  if (state.dirty) {
    if (state.viewMode === 'basic' && state.activeSegmentId) syncCanvasToSegment();
    if (state.viewMode === 'advanced') syncCmToSegments();
  }

  const merged = mergeSegmentsForPreview();
  const html = await fetch(apiUrl('api/preview'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
    body: JSON.stringify({ path: state.currentFile, content: merged }),
  }).then((r) => r.text());

  document.getElementById('editor-preview').classList.remove('hidden');
  document.getElementById('preview-frame').srcdoc = html;
}

function mergeSegmentsForPreview() {
  if (state.viewMode === 'basic' && state.activeSegmentId) {
    syncCanvasToSegment();
  }
  const doctype = state.segments.find((s) => s.type === 'doctype')?.content || '<!DOCTYPE html>';
  const head = state.segments.find((s) => s.type === 'head')?.content || '';
  const blocks = state.segments.filter((s) => s.type === 'block').map((s) => s.content).join('\n');
  const bodyContent = state.segments.find((s) => s.id === 'body_content')?.content;
  const inner = bodyContent ?? blocks;
  return `${doctype}\n<html lang="en">\n<head>${head}</head>\n<body>\n${inner}\n</body>\n</html>`;
}

async function renderAdvanced() {
  const container = document.getElementById('segment-accordion');
  container.innerHTML = '';
  state.cmEditors.clear();

  if (typeof CodeMirror === 'undefined') {
    container.innerHTML = '<p class="contact-form__status contact-form__status--error">CodeMirror failed to load.</p>';
    return;
  }

  state.segments.forEach((seg, i) => {
    const details = document.createElement('details');
    details.open = i === 0;
    const summary = document.createElement('summary');
    summary.textContent = seg.label;
    details.appendChild(summary);

    const wrap = document.createElement('div');
    wrap.className = 'codemirror-wrap';
    const ta = document.createElement('textarea');
    ta.value = seg.content;
    wrap.appendChild(ta);
    details.appendChild(wrap);
    container.appendChild(details);

    const cm = CodeMirror.fromTextArea(ta, {
      mode: 'htmlmixed',
      lineNumbers: true,
      lineWrapping: true,
      theme: 'default',
    });
    cm.on('change', markDirty);
    state.cmEditors.set(seg.id, cm);
  });
}

function syncCmToSegments() {
  state.cmEditors.forEach((cm, id) => {
    const seg = state.segments.find((s) => s.id === id);
    if (seg) seg.content = cm.getValue();
  });
}

function bindMedia() {
  document.getElementById('media-upload').addEventListener('change', async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    try {
      await api('api/media', { method: 'POST', body: fd });
      await loadMedia();
      showStatus(globalStatus, 'Upload complete');
      setTimeout(() => hideStatus(globalStatus), 3000);
    } catch (err) {
      showStatus(globalStatus, err.message, true);
    }
    e.target.value = '';
  });
}

async function loadMedia() {
  const data = await api('api/media');
  const grid = document.getElementById('media-grid');
  grid.innerHTML = '';
  data.items.forEach((item) => {
    const div = document.createElement('div');
    div.className = 'media-item';
    const img = item.mime.startsWith('image/')
      ? `<img src="${item.thumb ? apiUrl(item.thumb) : escapeAttr(item.url)}" alt="">`
      : `<div style="aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:var(--color-cream)">PDF</div>`;
    div.innerHTML = `${img}<div class="media-item__name">${escapeHtml(item.filename)}</div>`;
    div.addEventListener('click', () => {
      navigator.clipboard?.writeText(item.url);
      showStatus(globalStatus, 'URL copied: ' + item.url);
      setTimeout(() => hideStatus(globalStatus), 3000);
    });
    grid.appendChild(div);
  });
}

function openMediaPicker(cb) {
  loadMedia().then(() => {
    switchPanel('media');
    const handler = (e) => {
      const item = e.target.closest('.media-item');
      if (!item) return;
      document.getElementById('media-grid').removeEventListener('click', handler);
      switchPanel('editor');
      const name = item.querySelector('.media-item__name')?.textContent;
      api('api/media').then((data) => {
        const found = data.items.find((i) => i.filename === name);
        if (found && state.currentFile) {
          api('api/media/relative', {
            method: 'POST',
            body: JSON.stringify({ from_file: state.currentFile, to_path: found.path }),
          }).then((r) => cb(r.url));
        }
      });
    };
    document.getElementById('media-grid').addEventListener('click', handler);
  });
}

function bindUsers() {
  document.getElementById('add-user-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await api('api/users', {
        method: 'POST',
        body: JSON.stringify({
          username: document.getElementById('new-username').value,
          password: document.getElementById('new-password').value,
          role: document.getElementById('new-role').value,
        }),
      });
      e.target.reset();
      loadUsers();
    } catch (err) {
      showStatus(globalStatus, err.message, true);
    }
  });
}

async function loadUsers() {
  const data = await api('api/users');
  const tbody = document.getElementById('users-tbody');
  tbody.innerHTML = '';
  data.users.forEach((u) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${escapeHtml(u.username)}</td><td>${escapeHtml(u.role)}</td><td>${escapeHtml(u.created_at)}</td><td></td>`;
    if (u.id !== state.user.id) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn--ghost btn--sm';
      btn.textContent = 'Delete';
      btn.addEventListener('click', async () => {
        if (!confirm('Delete user?')) return;
        await api(`api/users/${u.id}`, { method: 'DELETE' });
        loadUsers();
      });
      tr.lastElementChild.appendChild(btn);
    }
    tbody.appendChild(tr);
  });
}

function bindSettings() {
  document.getElementById('settings-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await api('api/settings', {
        method: 'POST',
        body: JSON.stringify({
          site_web_base: document.getElementById('settings-web-base').value,
          upload_dir: document.getElementById('settings-upload-dir').value,
          backup_retention: parseInt(document.getElementById('settings-retention').value, 10),
          site_title: document.getElementById('settings-title').value,
          site_root: document.getElementById('settings-site-root').dataset.path || undefined,
        }),
      });
      showStatus(globalStatus, 'Settings saved');
    } catch (err) {
      showStatus(globalStatus, err.message, true);
    }
  });

  const modal = document.getElementById('modal-browse');
  let browsePath = null;
  let browseSelected = null;

  document.getElementById('btn-browse-root').addEventListener('click', () => {
    modal.showModal();
    loadModalDir();
  });

  async function loadModalDir(path) {
    const q = path ? `?path=${encodeURIComponent(path)}` : '';
    const data = await api(`api/settings/browse${q}`);
    browsePath = data.path;
    browseSelected = data.valid ? data.path : null;
    document.getElementById('modal-select-dir').disabled = !data.valid;
    const bc = document.getElementById('modal-breadcrumb');
    bc.innerHTML = `<span>${escapeHtml(data.path)}</span>`;
    const list = document.getElementById('modal-dir-list');
    list.innerHTML = '';
    if (data.parent) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'dir-up';
      btn.textContent = '↑ Parent';
      btn.onclick = () => loadModalDir(data.parent);
      list.appendChild(document.createElement('li')).appendChild(btn);
    }
    (data.directories || []).forEach((d) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = '📁 ' + d.name;
      btn.onclick = () => loadModalDir(d.path);
      list.appendChild(document.createElement('li')).appendChild(btn);
    });
  }

  document.getElementById('modal-select-dir').addEventListener('click', () => {
    if (browseSelected) {
      document.getElementById('settings-site-root').value = browseSelected;
      document.getElementById('settings-site-root').dataset.path = browseSelected;
      modal.close();
    }
  });
}

async function loadSettings() {
  const data = await api('api/settings');
  const s = data.settings;
  document.getElementById('settings-site-root').value = s.site_root || '';
  document.getElementById('settings-site-root').dataset.path = s.site_root || '';
  document.getElementById('settings-web-base').value = s.site_web_base || '/';
  document.getElementById('settings-upload-dir').value = s.upload_dir || 'uploads';
  document.getElementById('settings-retention').value = s.backup_retention || 20;
  document.getElementById('settings-title').value = s.site_title || '';
}

function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function escapeAttr(s) {
  return escapeHtml(s).replace(/"/g, '&quot;');
}

init();
