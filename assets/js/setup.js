import { api, showStatus, hideStatus } from './api.js';

function pageUrl(path = '') {
  const script = document.querySelector('script[type="module"]');
  const base = script?.src.replace(/\/assets\/js\/[^/]+$/, '') || '';
  return `${base}/${path.replace(/^\//, '')}`;
}

const statusEl = document.getElementById('setup-status');
let currentPath = null;
let selectedPath = null;

const steps = {
  show(n) {
    [1, 2, 3].forEach((i) => {
      document.getElementById(`step-${i}`).classList.toggle('hidden', i !== n);
      const badge = document.querySelector(`#setup-steps [data-step="${i}"]`);
      badge.classList.toggle('is-active', i === n);
      badge.classList.toggle('is-done', i < n);
    });
  },
};

async function loadDir(path) {
  hideStatus(statusEl);
  const q = path ? `?path=${encodeURIComponent(path)}` : '';
  const data = await api(`api/setup/browse${q}`);
  currentPath = data.path;
  renderBreadcrumb(data);
  renderDirList(data);
  document.getElementById('btn-step1-next').disabled = !data.valid;
  selectedPath = data.valid ? data.path : null;
  const preview = document.getElementById('dir-html-preview');
  if (data.html_files?.length) {
    const names = data.html_files.map((f) => (typeof f === 'string' ? f : f.name));
    preview.textContent = `Existing HTML (may be overwritten on publish): ${names.slice(0, 5).join(', ')}${names.length > 5 ? '…' : ''}`;
  } else if (data.valid) {
    preview.textContent = 'Folder is writable. Pages will be written here when you publish.';
  } else {
    preview.textContent = 'Folder is not writable.';
  }
}

function renderBreadcrumb(data) {
  const el = document.getElementById('dir-breadcrumb');
  const parts = data.path.split('/').filter(Boolean);
  let html = '';
  let acc = '';
  parts.forEach((part, i) => {
    acc += '/' + part;
    const p = acc;
    html += i > 0 ? ' <span>/</span> ' : '';
    html += `<button type="button" data-path="${p}">${part}</button>`;
  });
  el.innerHTML = html || `<span>${data.path}</span>`;
  el.querySelectorAll('button').forEach((btn) => {
    btn.addEventListener('click', () => loadDir(btn.dataset.path));
  });
}

function renderDirList(data) {
  const list = document.getElementById('dir-list');
  list.innerHTML = '';
  if (data.parent) {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'dir-up';
    btn.textContent = '↑ Parent folder';
    btn.addEventListener('click', () => loadDir(data.parent));
    li.appendChild(btn);
    list.appendChild(li);
  }
  data.directories.forEach((dir) => {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = `📁 ${dir.name}`;
    btn.addEventListener('click', () => loadDir(dir.path));
    li.appendChild(btn);
    list.appendChild(li);
  });
  if (!data.parent && !data.directories.length) {
    const li = document.createElement('li');
    li.innerHTML = '<button type="button" disabled>No subfolders</button>';
    list.appendChild(li);
  }
}

document.getElementById('btn-step1-next').addEventListener('click', () => steps.show(2));
document.getElementById('btn-step2-back').addEventListener('click', () => steps.show(1));
document.getElementById('btn-step2-next').addEventListener('click', () => steps.show(3));
document.getElementById('btn-step3-back').addEventListener('click', () => steps.show(2));

document.getElementById('btn-finish').addEventListener('click', async () => {
  hideStatus(statusEl);
  const username = document.getElementById('admin-username').value.trim();
  const password = document.getElementById('admin-password').value;
  const password2 = document.getElementById('admin-password2').value;
  if (password !== password2) {
    showStatus(statusEl, 'Passwords do not match', true);
    return;
  }
  if (!selectedPath) {
    showStatus(statusEl, 'Select a valid site folder first', true);
    return;
  }
  try {
    await api('api/setup/complete', {
      method: 'POST',
      body: JSON.stringify({
        site_root: selectedPath,
        site_web_base: document.getElementById('site-web-base').value || '/',
        upload_dir: document.getElementById('upload-dir').value || 'uploads',
        site_title: document.getElementById('site-title').value || 'Sneeze Admin',
        username,
        password,
      }),
    });
    window.location.href = pageUrl('');
  } catch (e) {
    showStatus(statusEl, e.message, true);
  }
});

loadDir().catch((e) => showStatus(statusEl, e.message, true));
