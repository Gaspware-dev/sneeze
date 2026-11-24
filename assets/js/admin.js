import { api, setCsrf, showStatus, hideStatus, apiUrl } from './api.js';
import { defaultData, renderFields, flattenTokens, unflattenTokens } from './fields.js';

const state = {
  user: null,
  pages: [],
  page: null,
  blockTypes: [],
  templates: [],
  selectedId: null,
  dirty: false,
  saving: false,
  previewTimer: null,
  saveTimer: null,
  tempId: -1,
  codeEditor: null,
  mediaPicker: null,
  design: { tokens: {}, stylesheets: [], scripts: [] },
  drag: null,
  previewDrop: null,
};

const history = {
  stack: [],
  index: 0,
  lock: false,
  timer: null,
};

const toast = document.getElementById('global-status');

function isAdmin() {
  return state.user?.role === 'admin';
}

function locate(nodes, id, parent = null) {
  for (let i = 0; i < nodes.length; i += 1) {
    if (String(nodes[i].id) === String(id)) {
      return { list: nodes, index: i, node: nodes[i], parent };
    }
    const found = locate(nodes[i].children || [], id, nodes[i]);
    if (found) return found;
  }
  return null;
}

function findBlock(id) {
  if (!state.page) return null;
  return locate(state.page.blocks || [], id)?.node || null;
}

function setSaveState(text) {
  document.getElementById('save-state').textContent = text;
}

function publishedHref(url) {
  if (!url) return '#';
  if (/^[a-z]+:/i.test(url) || url.startsWith('/')) return url;
  return `/${String(url).replace(/^\.\//, '')}`;
}

function markDirty(opts = {}) {
  if (history.lock) return;
  state.dirty = true;
  setSaveState('Unsaved');
  schedulePreview();
  scheduleSave();
  if (opts.history === false) return;
  clearTimeout(history.timer);
  if (opts.immediate) pushHistory();
  else history.timer = setTimeout(pushHistory, 450);
}

function pageSnapshot() {
  if (!state.page) return '';
  return JSON.stringify({
    title: state.page.title,
    slug: state.page.slug,
    seo_description: state.page.seo_description,
    custom_css: state.page.custom_css,
    custom_js: state.page.custom_js,
    template_id: state.page.template_id,
    blocks: state.page.blocks,
    selectedId: state.selectedId,
  });
}

function pushHistory() {
  if (history.lock || !state.page) return;
  const snap = pageSnapshot();
  if (history.stack[history.index] === snap) return;
  history.stack = history.stack.slice(0, history.index + 1);
  history.stack.push(snap);
  if (history.stack.length > 80) {
    history.stack.splice(0, history.stack.length - 80);
  }
  history.index = history.stack.length - 1;
  updateHistoryButtons();
}

function resetHistory() {
  clearTimeout(history.timer);
  history.lock = false;
  history.stack = [pageSnapshot()];
  history.index = 0;
  updateHistoryButtons();
}

function updateHistoryButtons() {
  const undoBtn = document.getElementById('btn-undo');
  const redoBtn = document.getElementById('btn-redo');
  if (undoBtn) undoBtn.disabled = history.index <= 0;
  if (redoBtn) redoBtn.disabled = history.index >= history.stack.length - 1;
}

function applySnapshot(snap) {
  if (!state.page) return;
  const data = JSON.parse(snap);
  history.lock = true;
  state.page.title = data.title;
  state.page.slug = data.slug;
  state.page.seo_description = data.seo_description;
  state.page.custom_css = data.custom_css;
  state.page.custom_js = data.custom_js;
  state.page.template_id = data.template_id;
  state.page.blocks = data.blocks;
  state.selectedId = data.selectedId;
  document.getElementById('page-title').value = data.title || '';
  document.getElementById('page-slug').value = data.slug || '';
  document.getElementById('page-seo').value = data.seo_description || '';
  renderOutline();
  renderWrite();
  if (document.getElementById('tab-code')?.classList.contains('is-active')) renderCode();
  history.lock = false;
  state.dirty = true;
  setSaveState('Unsaved');
  schedulePreview();
  scheduleSave();
  updateHistoryButtons();
}

function undo() {
  pushHistory();
  if (history.index <= 0) return;
  history.index -= 1;
  applySnapshot(history.stack[history.index]);
}

function redo() {
  clearTimeout(history.timer);
  if (history.index >= history.stack.length - 1) return;
  history.index += 1;
  applySnapshot(history.stack[history.index]);
}

function commitStructure(fn) {
  clearTimeout(history.timer);
  pushHistory();
  const before = pageSnapshot();
  fn();
  if (pageSnapshot() === before) return;
  pushHistory();
  state.dirty = true;
  setSaveState('Unsaved');
  schedulePreview();
  scheduleSave();
}

function collectIdMap(oldNodes, newNodes, map) {
  const count = Math.min(oldNodes.length, newNodes.length);
  for (let i = 0; i < count; i += 1) {
    if (String(oldNodes[i].id) !== String(newNodes[i].id)) {
      map.set(String(oldNodes[i].id), newNodes[i].id);
    }
    collectIdMap(oldNodes[i].children || [], newNodes[i].children || [], map);
  }
}

function remapTree(nodes, map) {
  nodes.forEach((node) => {
    if (map.has(String(node.id))) node.id = map.get(String(node.id));
    if (node.parent_id != null && map.has(String(node.parent_id))) {
      node.parent_id = map.get(String(node.parent_id));
    }
    remapTree(node.children || [], map);
  });
}

function remapSnapshot(snap, map) {
  const data = JSON.parse(snap);
  remapTree(data.blocks || [], map);
  if (data.selectedId != null && map.has(String(data.selectedId))) {
    data.selectedId = map.get(String(data.selectedId));
  }
  return JSON.stringify(data);
}

function rememberServerIds(oldBlocks) {
  const map = new Map();
  collectIdMap(oldBlocks || [], state.page?.blocks || [], map);
  if (map.size) {
    history.stack = history.stack.map((snap) => remapSnapshot(snap, map));
    if (state.selectedId != null && map.has(String(state.selectedId))) {
      state.selectedId = map.get(String(state.selectedId));
    }
  }
  if (!history.stack.length) {
    resetHistory();
    return;
  }
  history.stack[history.index] = pageSnapshot();
  updateHistoryButtons();
}

function schedulePreview() {
  clearTimeout(state.previewTimer);
  state.previewTimer = setTimeout(refreshPreview, 280);
}

function scheduleSave() {
  clearTimeout(state.saveTimer);
  state.saveTimer = setTimeout(() => saveDraft(true), 1200);
}

async function init() {
  try {
    const data = await api('api/auth/me');
    state.user = data.user;
    setCsrf(data.csrf);
    if (data.config?.site_title) {
      document.getElementById('app-title').textContent = data.config.site_title;
      document.title = data.config.site_title;
    }
    if (isAdmin()) {
      document.querySelectorAll('.admin-only').forEach((el) => el.classList.remove('hidden'));
    }
    bindShell();
    bindStudio();
    bindLibrary();
    bindDesign();
    bindMedia();
    bindCms();
    bindUsers();
    bindSettings();
    bindImport();
    await Promise.all([loadTypes(), loadTemplates(), loadPages()]);
    if (state.pages[0]) await openPage(state.pages[0].id);
  } catch (e) {
    window.location.href = 'login';
  }
}

function bindShell() {
  document.getElementById('btn-logout').addEventListener('click', async () => {
    await api('api/auth/logout', { method: 'POST' });
    window.location.href = 'login';
  });

  document.getElementById('workspace-nav').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-workspace]');
    if (!btn) return;
    switchWorkspace(btn.dataset.workspace);
  });

  document.getElementById('page-select').addEventListener('change', (e) => {
    openPage(Number(e.target.value));
  });

  document.getElementById('btn-new-page').addEventListener('click', () => {
    document.getElementById('modal-new-page').showModal();
  });
  document.getElementById('btn-import-html')?.addEventListener('click', () => openImportModal());

  document.getElementById('new-page-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const page = (await api('api/pages', {
      method: 'POST',
      body: JSON.stringify({
        title: document.getElementById('new-page-title').value,
        slug: document.getElementById('new-page-slug').value,
        template_id: state.templates[0]?.id,
      }),
    })).page;
    document.getElementById('modal-new-page').close();
    await loadPages();
    await openPage(page.id);
  });

  document.getElementById('btn-save-draft').addEventListener('click', () => saveDraft(false));
  document.getElementById('btn-publish').addEventListener('click', publishPage);

  document.querySelectorAll('[data-close-modal]').forEach((btn) => {
    btn.addEventListener('click', () => btn.closest('dialog')?.close());
  });
}

function switchWorkspace(name) {
  document.querySelectorAll('.studio-workspaces__btn').forEach((b) => {
    b.classList.toggle('is-active', b.dataset.workspace === name);
  });
  document.querySelectorAll('.workspace').forEach((el) => {
    el.classList.toggle('is-active', el.id === `workspace-${name}`);
  });
  if (name === 'library') renderLibrary();
  if (name === 'design') loadDesign();
  if (name === 'media') loadMedia();
  if (name === 'cms') loadCms();
  if (name === 'users') loadUsers();
  if (name === 'settings') loadSettings();
  if (name === 'studio') setTimeout(() => state.codeEditor?.refresh(), 50);
}

function bindStudio() {
  document.getElementById('side-tabs').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-tab]');
    if (!btn) return;
    document.querySelectorAll('#side-tabs button').forEach((b) => b.classList.toggle('is-active', b === btn));
    document.querySelectorAll('.side-tab').forEach((tab) => {
      tab.classList.toggle('is-active', tab.id === `tab-${btn.dataset.tab}`);
    });
    if (btn.dataset.tab === 'write') renderWrite();
    if (btn.dataset.tab === 'code') {
      renderCode();
      setTimeout(() => state.codeEditor?.refresh(), 40);
    }
  });

  document.querySelectorAll('.preview-widths button').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.preview-widths button').forEach((b) => b.classList.remove('is-active'));
      btn.classList.add('is-active');
      document.getElementById('preview-frame').style.width = btn.dataset.width;
    });
  });

  window.addEventListener('message', (e) => {
    if (e.data?.type === 'sneeze:select') {
      selectBlock(e.data.id);
      const writeBtn = document.querySelector('[data-tab="write"]');
      writeBtn?.click();
    }
    if (e.data?.type === 'sneeze:hit') {
      state.previewDrop = {
        parentId: e.data.parentId || '',
        slot: e.data.slot || 'content',
        beforeId: e.data.beforeId || '',
      };
    }
  });

  ['page-title', 'page-slug', 'page-seo'].forEach((id) => {
    document.getElementById(id).addEventListener('input', () => {
      if (!state.page) return;
      state.page.title = document.getElementById('page-title').value;
      state.page.slug = document.getElementById('page-slug').value;
      state.page.seo_description = document.getElementById('page-seo').value;
      markDirty();
    });
  });

  document.getElementById('code-target').addEventListener('change', renderCode);
  document.getElementById('btn-save-code').addEventListener('click', saveCodeTarget);
  document.getElementById('btn-undo').addEventListener('click', undo);
  document.getElementById('btn-redo').addEventListener('click', redo);
  document.addEventListener('keydown', (e) => {
    if (!(e.metaKey || e.ctrlKey) || e.altKey) return;
    const key = e.key.toLowerCase();
    if (key !== 'z' && key !== 'y') return;
    const target = e.target;
    if (target?.closest?.('.CodeMirror, input, textarea, select, [contenteditable="true"]')) return;
    if (key === 'y' || (key === 'z' && e.shiftKey)) {
      e.preventDefault();
      redo();
    } else if (key === 'z') {
      e.preventDefault();
      undo();
    }
  });
  bindBuilderDrag();
}

async function loadPages() {
  const data = await api('api/pages');
  state.pages = data.pages;
  const select = document.getElementById('page-select');
  select.innerHTML = '';
  state.pages.forEach((page) => {
    const opt = document.createElement('option');
    opt.value = page.id;
    opt.textContent = page.title;
    if (state.page && page.id === state.page.id) opt.selected = true;
    select.appendChild(opt);
  });
}

async function loadTypes() {
  state.blockTypes = (await api('api/block-types')).block_types;
  renderPalette();
}

async function loadTemplates() {
  state.templates = (await api('api/templates')).templates;
}

async function openPage(id) {
  if (state.dirty && state.page) {
    await saveDraft(true);
  }
  const data = await api(`api/pages/${id}`);
  state.page = data.page;
  state.selectedId = state.page.blocks?.[0]?.id ?? null;
  state.dirty = false;
  document.getElementById('page-select').value = String(id);
  document.getElementById('page-title').value = state.page.title || '';
  document.getElementById('page-slug').value = state.page.slug || '';
  document.getElementById('page-seo').value = state.page.seo_description || '';
  const live = document.getElementById('btn-live');
  if (state.page.status === 'published') {
    live.href = publishedHref(state.page.slug === 'index' ? 'index.html' : `${state.page.slug}.html`);
    live.classList.remove('hidden');
  } else {
    live.classList.add('hidden');
  }
  setSaveState(state.page.status === 'published' ? 'Published' : 'Draft');
  resetHistory();
  renderPalette();
  renderOutline();
  renderWrite();
  await refreshPreview();
}

function renderPalette() {
  const el = document.getElementById('block-palette');
  el.innerHTML = '';
  state.blockTypes.forEach((type) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.dataset.typeId = String(type.id);
    btn.textContent = `${type.icon || ''} ${type.name}`.trim();
    btn.title = `Drag ${type.name} onto the page, or click to add it`;
    btn.addEventListener('click', () => addBlock(type.id));
    el.appendChild(btn);
  });
}

function parseParentId(value) {
  if (value === null || value === undefined || value === '') return null;
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

function isDescendant(node, id) {
  return (node.children || []).some((child) => String(child.id) === String(id) || isDescendant(child, id));
}

function makeNode(typeId, parentId, slot) {
  const type = state.blockTypes.find((t) => t.id === typeId);
  if (!type) return null;
  const node = {
    id: state.tempId,
    block_type_id: type.id,
    block_type: type,
    parent_id: parentId,
    slot,
    data: defaultData(type.schema),
    children: [],
    cms_source_id: null,
    cms_entry_key: null,
  };
  state.tempId -= 1;
  return node;
}

function targetList(parentId) {
  if (!state.page) return null;
  if (parentId === null) {
    state.page.blocks = state.page.blocks || [];
    return state.page.blocks;
  }
  const parent = findBlock(parentId);
  if (!parent) return null;
  parent.children = parent.children || [];
  return parent.children;
}

function addBlock(typeId, parent = null, slot = 'content') {
  const type = state.blockTypes.find((t) => t.id === typeId);
  if (!type || !state.page) return;

  if (!parent && state.selectedId) {
    const selected = findBlock(state.selectedId);
    const slots = selected?.block_type?.schema?.slots || [];
    if (selected && slots.length) {
      parent = selected;
      slot = slots[0];
    }
  }

  commitStructure(() => {
    const node = makeNode(type.id, parent?.id ?? null, slot);
    if (!node) return;
    if (parent) {
      parent.children = parent.children || [];
      parent.children.push(node);
    } else {
      state.page.blocks = state.page.blocks || [];
      state.page.blocks.push(node);
    }
    state.selectedId = node.id;
  });
  renderOutline();
  renderWrite();
}

function renderOutline() {
  const root = document.getElementById('block-outline');
  root.innerHTML = '';
  root.classList.add('outline-slot');
  root.dataset.slot = 'content';
  root.dataset.parent = '';
  const label = document.getElementById('selected-label');
  const selected = findBlock(state.selectedId);
  label.textContent = selected ? selected.block_type?.name || '' : '';
  (state.page?.blocks || []).forEach((node) => root.appendChild(outlineItem(node)));
}

function outlineItem(node) {
  const wrap = document.createElement('div');
  wrap.className = 'outline-item' + (String(node.id) === String(state.selectedId) ? ' is-selected' : '');
  wrap.dataset.id = String(node.id);
  const row = document.createElement('div');
  row.className = 'outline-item__row';
  const handle = document.createElement('span');
  handle.className = 'drag-handle';
  handle.title = 'Drag to reorder';
  handle.textContent = '⋮⋮';
  const pick = document.createElement('button');
  pick.type = 'button';
  pick.className = 'pick';
  pick.textContent = `${node.block_type?.icon || '■'} ${node.block_type?.name || 'Block'}`;
  pick.addEventListener('click', () => selectBlock(node.id));
  const ops = document.createElement('div');
  ops.className = 'outline-item__ops';
  [
    ['↑', () => moveBlock(node.id, -1)],
    ['↓', () => moveBlock(node.id, 1)],
    ['✕', () => removeBlock(node.id)],
  ].forEach(([text, fn]) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = text;
    b.addEventListener('click', (e) => {
      e.stopPropagation();
      fn();
    });
    ops.appendChild(b);
  });
  row.appendChild(handle);
  row.appendChild(pick);
  row.appendChild(ops);
  wrap.appendChild(row);

  const slots = node.block_type?.schema?.slots || [];
  if (slots.length) {
    const kids = document.createElement('div');
    kids.className = 'outline-children';
    slots.forEach((slot) => {
      const lab = document.createElement('div');
      lab.className = 'slot-label';
      lab.textContent = slot;
      const add = document.createElement('button');
      add.type = 'button';
      add.className = 'btn btn--ghost btn--sm';
      add.textContent = '+';
      add.style.marginLeft = '0.35rem';
      add.addEventListener('click', () => {
        const first = state.blockTypes.find((t) => t.slug === 'richtext') || state.blockTypes[0];
        if (first) addBlock(first.id, node, slot);
      });
      lab.appendChild(add);
      kids.appendChild(lab);
      const slotEl = document.createElement('div');
      slotEl.className = 'outline-slot';
      slotEl.dataset.slot = slot;
      slotEl.dataset.parent = String(node.id);
      const children = (node.children || []).filter((c) => c.slot === slot);
      children.forEach((child) => slotEl.appendChild(outlineItem(child)));
      if (!children.length) {
        const empty = document.createElement('div');
        empty.className = 'slot-empty';
        empty.textContent = 'Drop a block here';
        slotEl.appendChild(empty);
      }
      kids.appendChild(slotEl);
    });
    wrap.appendChild(kids);
  }
  return wrap;
}

function applyDrop({ parentId, slot, beforeId }) {
  const drag = state.drag;
  if (!drag || !state.page) return;
  const parent = parseParentId(parentId);
  const list = targetList(parent);
  if (!list) return;
  let index = list.length;
  if (beforeId) {
    const at = list.findIndex((n) => String(n.id) === String(beforeId));
    if (at >= 0) index = at;
  }
  commitStructure(() => {
    if (drag.kind === 'type') {
      const node = makeNode(drag.typeId, parent, slot || 'content');
      if (!node) return;
      list.splice(index, 0, node);
      state.selectedId = node.id;
      return;
    }
    const found = locate(state.page.blocks || [], drag.id);
    if (!found) return;
    if (parent !== null && (String(parent) === String(drag.id) || isDescendant(found.node, parent))) return;
    const sameList = found.list === list;
    const [node] = found.list.splice(found.index, 1);
    if (sameList && found.index < index) index -= 1;
    node.slot = slot || 'content';
    node.parent_id = parent;
    list.splice(Math.max(0, Math.min(index, list.length)), 0, node);
    state.selectedId = node.id;
  });
  renderOutline();
  renderWrite();
}

function beforeIdFromPoint(container, clientY) {
  const items = [...container.querySelectorAll(':scope > .outline-item')];
  for (const item of items) {
    const rect = item.getBoundingClientRect();
    if (clientY < rect.top + rect.height / 2) return item.dataset.id || '';
  }
  return '';
}

function notifyPreviewDrag(active) {
  const frame = document.getElementById('preview-frame');
  if (!frame) return;
  frame.style.pointerEvents = active ? 'none' : '';
  try {
    frame.contentDocument?.body?.classList.toggle('is-builder-drag', !!active);
    if (!active) {
      frame.contentDocument?.querySelectorAll('.is-drop-target, .is-drop-before, .is-drop-after').forEach((el) => {
        el.classList.remove('is-drop-target', 'is-drop-before', 'is-drop-after');
      });
    }
  } catch (err) {
    frame.contentWindow?.postMessage({ type: 'sneeze:drag', active: !!active }, '*');
  }
}

function hitTestPreview(clientX, clientY) {
  const frame = document.getElementById('preview-frame');
  const rect = frame.getBoundingClientRect();
  const x = clientX - rect.left;
  const y = clientY - rect.top;
  try {
    const hit = frame.contentDocument?.defaultView?.sneezeHitTest?.(x, y);
    if (hit) return hit;
  } catch (err) {
    /* opaque preview frame */
  }
  frame.contentWindow?.postMessage({ type: 'sneeze:hittest', x, y }, '*');
  return state.previewDrop;
}

function finishDrag() {
  document.querySelectorAll('.is-dragging, .is-drop-target').forEach((el) => {
    el.classList.remove('is-dragging', 'is-drop-target');
  });
  notifyPreviewDrag(false);
  const payload = state.drag;
  setTimeout(() => {
    if (state.drag === payload) state.drag = null;
  }, 50);
}

let pointerDrag = null;
let suppressNextClick = false;

function bindBuilderDrag() {
  document.addEventListener('pointerdown', onBuilderPointerDown);
  document.addEventListener('pointermove', onBuilderPointerMove);
  document.addEventListener('pointerup', onBuilderPointerUp);
  document.addEventListener('pointercancel', onBuilderPointerUp);
  document.addEventListener('click', (e) => {
    if (!suppressNextClick) return;
    suppressNextClick = false;
    e.preventDefault();
    e.stopPropagation();
  }, true);
}

function onBuilderPointerDown(e) {
  if (e.button !== 0 || pointerDrag) return;
  const handle = e.target.closest?.('.drag-handle');
  const chip = !handle ? e.target.closest?.('#block-palette [data-type-id]') : null;
  if (!handle && !chip) return;
  const item = handle?.closest('.outline-item');
  pointerDrag = {
    pointerId: e.pointerId,
    kind: handle ? 'block' : 'type',
    id: item?.dataset.id || '',
    typeId: chip ? Number(chip.dataset.typeId) : null,
    label: (handle ? item?.querySelector('.pick')?.textContent : chip?.textContent) || 'Block',
    startX: e.clientX,
    startY: e.clientY,
    active: false,
  };
  (handle || chip).setPointerCapture?.(e.pointerId);
}

function onBuilderPointerMove(e) {
  if (!pointerDrag || e.pointerId !== pointerDrag.pointerId) return;
  const distance = Math.hypot(e.clientX - pointerDrag.startX, e.clientY - pointerDrag.startY);
  if (!pointerDrag.active) {
    if (distance < 5) return;
    pointerDrag.active = true;
    state.drag = pointerDrag.kind === 'block'
      ? { kind: 'block', id: pointerDrag.id }
      : { kind: 'type', typeId: pointerDrag.typeId };
    state.previewDrop = { parentId: '', slot: 'content', beforeId: '' };
    document.querySelectorAll('.outline-item').forEach((el) => {
      el.classList.toggle('is-dragging', pointerDrag.kind === 'block' && el.dataset.id === String(pointerDrag.id));
    });
    notifyPreviewDrag(true);
  }
  e.preventDefault();
  moveDragGhost(e.clientX, e.clientY, pointerDrag.label);
  showDropHint(pointerDropTarget(e.clientX, e.clientY));
}

function onBuilderPointerUp(e) {
  if (!pointerDrag || e.pointerId !== pointerDrag.pointerId) return;
  const active = pointerDrag.active;
  const x = e.clientX;
  const y = e.clientY;
  pointerDrag = null;
  hideDragGhost();
  if (!active) return;
  suppressNextClick = true;
  const hit = pointerDropTarget(x, y);
  if (hit && state.drag) applyDrop(hit);
  clearDropHints();
  finishDrag();
}

function pointerDropTarget(x, y) {
  const el = document.elementFromPoint(x, y);
  if (!el) return null;
  const slot = el.closest('.outline-slot');
  const outline = document.getElementById('block-outline');
  if (slot && outline?.contains(slot)) {
    clearPreviewMarks();
    return {
      where: 'outline',
      parentId: slot.dataset.parent || '',
      slot: slot.dataset.slot || 'content',
      beforeId: beforeIdFromPoint(slot, y),
    };
  }
  const stage = document.getElementById('preview-stage');
  if (stage && stage.contains(el)) {
    const hit = hitTestPreview(x, y) || { parentId: '', slot: 'content', beforeId: '' };
    return { ...hit, where: 'preview' };
  }
  clearPreviewMarks();
  return null;
}

function clearPreviewMarks() {
  try {
    document.getElementById('preview-frame')?.contentDocument
      ?.querySelectorAll('.is-drop-target, .is-drop-before, .is-drop-after')
      .forEach((node) => node.classList.remove('is-drop-target', 'is-drop-before', 'is-drop-after'));
  } catch (err) {
    /* preview frame is not readable */
  }
}

function showDropHint(hit) {
  clearDropHints();
  if (!hit || hit.where !== 'outline') return;
  const outline = document.getElementById('block-outline');
  const parent = hit.parentId || '';
  const slotName = hit.slot || 'content';
  const slot = [...outline.querySelectorAll('.outline-slot')].find((el) => (
    (el.dataset.parent || '') === String(parent) && (el.dataset.slot || 'content') === slotName
  ));
  slot?.classList.add('is-drop-target');
}

function clearDropHints() {
  document.querySelectorAll('.outline-slot.is-drop-target').forEach((el) => el.classList.remove('is-drop-target'));
}

function moveDragGhost(x, y, label) {
  let ghost = document.getElementById('drag-ghost');
  if (!ghost) {
    ghost = document.createElement('div');
    ghost.id = 'drag-ghost';
    document.body.appendChild(ghost);
  }
  ghost.textContent = String(label).trim();
  ghost.style.left = `${x + 14}px`;
  ghost.style.top = `${y + 14}px`;
  ghost.hidden = false;
}

function hideDragGhost() {
  const ghost = document.getElementById('drag-ghost');
  if (ghost) ghost.hidden = true;
}

function selectBlock(id) {
  state.selectedId = id;
  renderOutline();
  renderWrite();
  const frame = document.getElementById('preview-frame');
  frame.contentWindow?.postMessage({ type: 'sneeze:highlight', id: String(id) }, '*');
}

function moveBlock(id, dir) {
  const found = locate(state.page.blocks || [], id);
  if (!found) return;
  const next = found.index + dir;
  if (next < 0 || next >= found.list.length) return;
  commitStructure(() => {
    const [item] = found.list.splice(found.index, 1);
    found.list.splice(next, 0, item);
  });
  renderOutline();
}

function removeBlock(id) {
  const found = locate(state.page.blocks || [], id);
  if (!found) return;
  commitStructure(() => {
    found.list.splice(found.index, 1);
    if (String(state.selectedId) === String(id)) state.selectedId = null;
  });
  renderOutline();
  renderWrite();
}

function renderWrite() {
  const box = document.getElementById('write-fields');
  const node = findBlock(state.selectedId);
  if (!node) {
    box.innerHTML = '<p class="muted">Select a block in the outline or preview.</p>';
    return;
  }
  renderFields(box, node.block_type?.schema, node.data || {}, (name, value) => {
    node.data = node.data || {};
    node.data[name] = value;
    markDirty();
  }, { pickMedia });
}

function renderCode() {
  const wrap = document.getElementById('code-wrap');
  const target = document.getElementById('code-target').value;
  const mode = target.endsWith('css')
    ? 'css'
    : target.endsWith('js')
      ? { name: 'javascript', typescript: true }
      : 'htmlmixed';
  const value = codeValue(target);
  if (!state.codeEditor) {
    state.codeEditor = window.CodeMirror(wrap, {
      value,
      mode,
      lineNumbers: true,
      lineWrapping: true,
    });
    state.codeEditor.on('change', () => {
      applyCodeValue(document.getElementById('code-target').value, state.codeEditor.getValue());
    });
  } else {
    state.codeEditor.setOption('mode', mode);
    state.codeEditor.setValue(value);
  }
  setTimeout(() => state.codeEditor.refresh(), 30);
}

function codeValue(target) {
  const node = findBlock(state.selectedId);
  const template = state.templates.find((t) => t.id === state.page?.template_id) || state.templates[0];
  switch (target) {
    case 'block-html': return node?.block_type?.html || '';
    case 'block-css': return node?.block_type?.css || '';
    case 'block-js': return node?.block_type?.js || '';
    case 'template-html': return template?.html || '';
    case 'template-css': return template?.css || '';
    case 'template-js': return template?.js || '';
    case 'page-css': return state.page?.custom_css || '';
    case 'page-js': return state.page?.custom_js || '';
    default: return '';
  }
}

function applyCodeValue(target, value) {
  const node = findBlock(state.selectedId);
  const template = state.templates.find((t) => t.id === state.page?.template_id);
  if (target === 'page-css' && state.page) {
    state.page.custom_css = value;
    markDirty();
  } else if (target === 'page-js' && state.page) {
    state.page.custom_js = value;
    markDirty();
  } else if (target.startsWith('block-') && node?.block_type) {
    const key = target.replace('block-', '');
    node.block_type[key] = value;
    const t = state.blockTypes.find((x) => x.id === node.block_type.id);
    if (t) t[key] = value;
    markDirty();
  } else if (target.startsWith('template-') && template) {
    template[target.replace('template-', '')] = value;
    markDirty();
  }
}

async function saveCodeTarget() {
  if (!isAdmin()) return;
  const target = document.getElementById('code-target').value;
  const node = findBlock(state.selectedId);
  const template = state.templates.find((t) => t.id === state.page?.template_id);
  try {
    if (target.startsWith('block-') && node) {
      await api(`api/block-types/${node.block_type.id}`, {
        method: 'PUT',
        body: JSON.stringify(node.block_type),
      });
      await loadTypes();
    } else if (target.startsWith('template-') && template) {
      await api(`api/templates/${template.id}`, {
        method: 'PUT',
        body: JSON.stringify(template),
      });
      await loadTemplates();
    }
    await saveDraft(false);
    showStatus(toast, 'Saved definition');
  } catch (e) {
    showStatus(toast, e.message, true);
  }
}

async function refreshPreview() {
  if (!state.page) return;
  try {
    const data = await api(`api/pages/${state.page.id}/preview`, {
      method: 'POST',
      body: JSON.stringify({
        title: state.page.title,
        slug: state.page.slug,
        seo_description: state.page.seo_description,
        template_id: state.page.template_id,
        custom_css: state.page.custom_css,
        custom_js: state.page.custom_js,
        template: state.templates.find((t) => t.id === state.page.template_id) || null,
        blocks: state.page.blocks,
      }),
    });
    const frame = document.getElementById('preview-frame');
    frame.srcdoc = data.html;
    frame.onload = () => {
      if (state.selectedId) {
        frame.contentWindow?.postMessage({ type: 'sneeze:highlight', id: String(state.selectedId) }, '*');
      }
    };
  } catch (e) {
    showStatus(toast, e.message, true);
  }
}

async function saveDraft(silent) {
  if (!state.page || state.saving) return;
  state.saving = true;
  setSaveState('Saving…');
  try {
    const data = await api(`api/pages/${state.page.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        title: state.page.title,
        slug: state.page.slug,
        seo_title: state.page.seo_title || state.page.title,
        seo_description: state.page.seo_description,
        template_id: state.page.template_id,
        custom_css: state.page.custom_css,
        custom_js: state.page.custom_js,
        blocks: state.page.blocks,
      }),
    });
    const oldBlocks = state.page.blocks;
    const prevSelected = state.selectedId;
    state.page = data.page;
    applyLocalTypes(state.page.blocks || []);
    rememberServerIds(oldBlocks);
    if (!findBlock(state.selectedId)) {
      state.selectedId = findBlock(prevSelected) ? prevSelected : (state.page.blocks?.[0]?.id ?? null);
      if (history.stack.length) history.stack[history.index] = pageSnapshot();
    }
    state.dirty = false;
    setSaveState('Saved');
    await loadPages();
    renderOutline();
    if (!silent) showStatus(toast, 'Draft saved');
  } catch (e) {
    setSaveState('Save failed');
    showStatus(toast, e.message, true);
  } finally {
    state.saving = false;
  }
}

async function publishPage() {
  if (!state.page) return;
  await saveDraft(true);
  try {
    const data = await api(`api/pages/${state.page.id}/publish`, { method: 'POST' });
    state.page.status = 'published';
    setSaveState('Published');
    const live = document.getElementById('btn-live');
    live.href = publishedHref(data.url);
    live.classList.remove('hidden');
    showStatus(toast, `Published to ${data.path}`);
  } catch (e) {
    showStatus(toast, e.message, true);
  }
}

function pickMedia(cb) {
  state.mediaPicker = cb;
  document.getElementById('modal-media-pick').showModal();
  fillMediaGrid(document.getElementById('media-pick-grid'), true);
}

function bindLibrary() {
  document.getElementById('btn-import-template')?.addEventListener('click', () => openImportModal());
  document.getElementById('btn-new-template')?.addEventListener('click', async () => {
    const tpl = (await api('api/templates', {
      method: 'POST',
      body: JSON.stringify({
        name: 'New template',
        slug: `template-${Date.now()}`,
        html: '<!DOCTYPE html><html><head><!-- sneeze:css --></head><body>{{slot:content}}<!-- sneeze:js --></body></html>',
      }),
    })).template;
    await loadTemplates();
    renderLibrary(tpl, 'template');
  });
  document.getElementById('btn-new-block-type')?.addEventListener('click', async () => {
    const type = (await api('api/block-types', {
      method: 'POST',
      body: JSON.stringify({
        name: 'New block',
        slug: `block-${Date.now()}`,
        schema: { fields: [{ name: 'text', label: 'Text', type: 'text' }], slots: [] },
        html: '<div class="wrap">{{text}}</div>',
      }),
    })).block_type;
    await loadTypes();
    renderLibrary(type, 'block');
  });
}

function renderLibrary(selected = null, kind = null) {
  const tList = document.getElementById('template-list');
  const bList = document.getElementById('block-type-list');
  tList.innerHTML = '';
  bList.innerHTML = '';
  state.templates.forEach((tpl) => {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'event';
    btn.innerHTML = `<div class="event__details"><h3>${escapeHtml(tpl.name)}</h3><p>${escapeHtml(tpl.slug)}</p></div>`;
    btn.addEventListener('click', () => renderLibrary(tpl, 'template'));
    li.appendChild(btn);
    tList.appendChild(li);
  });
  state.blockTypes.forEach((type) => {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'event';
    btn.innerHTML = `<div class="event__details"><h3>${escapeHtml(type.name)}</h3><p>${escapeHtml(type.category)} · ${escapeHtml(type.slug)}</p></div>`;
    btn.addEventListener('click', () => renderLibrary(type, 'block'));
    li.appendChild(btn);
    bList.appendChild(li);
  });
  const form = document.getElementById('library-form');
  const title = document.getElementById('library-title');
  if (!selected) {
    title.textContent = 'Select an item';
    form.innerHTML = '<p class="muted">Templates are page layouts. Block types are reusable pieces with a field schema, HTML, CSS and JS — all stored in the database.</p>';
    return;
  }
  title.textContent = selected.name;
  form.innerHTML = libraryForm(selected, kind);
  form.querySelector('form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const payload = readLibraryForm(form, selected, kind);
      if (kind === 'template') {
        await api(`api/templates/${selected.id}`, { method: 'PUT', body: JSON.stringify(payload) });
        await loadTemplates();
      } else {
        await api(`api/block-types/${selected.id}`, { method: 'PUT', body: JSON.stringify(payload) });
        await loadTypes();
      }
      showStatus(toast, 'Library item saved');
      renderLibrary();
    } catch (err) {
      showStatus(toast, err.message, true);
    }
  });
}

function libraryForm(item, kind) {
  const schema = kind === 'block' ? JSON.stringify(item.schema || {}, null, 2) : '';
  return `
    <form>
      <div class="contact-form__field"><label>Name</label><input name="name" value="${escapeAttr(item.name)}"></div>
      <div class="contact-form__field"><label>Slug</label><input name="slug" value="${escapeAttr(item.slug)}"></div>
      ${kind === 'block' ? `
        <div class="contact-form__field"><label>Category</label><input name="category" value="${escapeAttr(item.category || 'content')}"></div>
        <div class="contact-form__field"><label>Icon</label><input name="icon" value="${escapeAttr(item.icon || '')}"></div>
        <div class="contact-form__field"><label>Schema JSON</label><textarea name="schema" rows="8">${escapeHtml(schema)}</textarea></div>
      ` : ''}
      <div class="contact-form__field"><label>HTML</label><textarea name="html" rows="10">${escapeHtml(item.html || '')}</textarea></div>
      <div class="contact-form__field"><label>CSS</label><textarea name="css" rows="8">${escapeHtml(item.css || '')}</textarea></div>
      <div class="contact-form__field"><label>JS</label><textarea name="js" rows="6">${escapeHtml(item.js || '')}</textarea></div>
      <div class="contact-form__actions"><button class="btn btn--primary" type="submit">Save</button></div>
    </form>
  `;
}

function readLibraryForm(root, item, kind) {
  const get = (name) => root.querySelector(`[name="${name}"]`)?.value ?? '';
  const payload = { ...item, name: get('name'), slug: get('slug'), html: get('html'), css: get('css'), js: get('js') };
  if (kind === 'block') {
    payload.category = get('category');
    payload.icon = get('icon');
    try {
      payload.schema = JSON.parse(get('schema') || '{}');
    } catch {
      throw new Error('Schema must be valid JSON');
    }
  }
  return payload;
}

async function loadDesign() {
  state.design = await api('api/design');
  const box = document.getElementById('token-fields');
  box.innerHTML = '';
  const flat = flattenTokens(state.design.tokens || {});
  Object.entries(flat).forEach(([path, value]) => {
    const field = document.createElement('div');
    field.className = 'contact-form__field';
    field.innerHTML = `<label>${escapeHtml(path)}</label><input data-token="${escapeAttr(path)}" value="${escapeAttr(String(value))}">`;
    box.appendChild(field);
  });
  document.getElementById('btn-save-tokens').onclick = async () => {
    const next = {};
    box.querySelectorAll('[data-token]').forEach((input) => {
      next[input.dataset.token] = input.value;
    });
    try {
      await api('api/design/tokens', { method: 'PUT', body: JSON.stringify({ tokens: unflattenTokens(next) }) });
      showStatus(toast, 'Tokens saved');
      schedulePreview();
    } catch (e) {
      showStatus(toast, e.message, true);
    }
  };
  renderAssetEditors('stylesheet-list', state.design.stylesheets, 'css', 'stylesheet');
  renderAssetEditors('script-list', state.design.scripts, 'js', 'script');
  const tool = state.design.toolchain || {};
  const tsEl = document.getElementById('ts-enabled');
  const twEl = document.getElementById('tw-enabled');
  const cfgEl = document.getElementById('tw-config');
  if (tsEl) tsEl.checked = tool.typescript !== false;
  if (twEl) twEl.checked = tool.tailwind !== false;
  if (cfgEl) cfgEl.value = tool.tailwind_config_ts || '';
  const engines = document.getElementById('toolchain-engines');
  if (engines) {
    const es = tool.engines?.esbuild ? 'esbuild' : 'built-in TypeScript';
    const tw = tool.engines?.tailwindcss ? 'Tailwind CLI' : 'built-in Tailwind';
    engines.textContent = `Compilers: ${es}, ${tw}.`;
  }
}

function renderAssetEditors(containerId, items, field, kind) {
  const el = document.getElementById(containerId);
  el.innerHTML = '';
  items.forEach((item) => {
    const wrap = document.createElement('div');
    wrap.className = 'contact-form__field';
    wrap.innerHTML = `<label>${escapeHtml(item.name)}</label><textarea rows="12">${escapeHtml(item[field] || '')}</textarea>
      <div class="contact-form__actions"><button type="button" class="btn btn--primary btn--sm">Save</button></div>`;
    wrap.querySelector('button').addEventListener('click', async () => {
      const value = wrap.querySelector('textarea').value;
      const url = kind === 'stylesheet' ? `api/design/stylesheets/${item.id}` : `api/design/scripts/${item.id}`;
      const body = { ...item, [field]: value, enabled: true };
      try {
        await api(url, { method: 'PUT', body: JSON.stringify(body) });
        showStatus(toast, 'Saved');
        schedulePreview();
      } catch (e) {
        showStatus(toast, e.message, true);
      }
    });
    el.appendChild(wrap);
  });
}

function bindDesign() {
  document.getElementById('btn-save-toolchain')?.addEventListener('click', async () => {
    try {
      const data = await api('api/design/toolchain', {
        method: 'PUT',
        body: JSON.stringify({
          typescript: document.getElementById('ts-enabled').checked,
          tailwind: document.getElementById('tw-enabled').checked,
          tailwind_config_ts: document.getElementById('tw-config').value,
        }),
      });
      state.design.toolchain = data.toolchain;
      showStatus(toast, 'Toolchain saved');
      schedulePreview();
    } catch (e) {
      showStatus(toast, e.message, true);
    }
  });
}

function bindMedia() {
  document.getElementById('media-upload').addEventListener('change', async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const body = new FormData();
    body.append('file', file);
    try {
      await api('api/media', { method: 'POST', body });
      await loadMedia();
    } catch (err) {
      showStatus(toast, err.message, true);
    }
    e.target.value = '';
  });
}

async function loadMedia() {
  const data = await api('api/media');
  fillMediaGrid(document.getElementById('media-grid'), false, data.items);
}

async function fillMediaGrid(grid, picking, items) {
  if (!items) items = (await api('api/media')).items;
  grid.innerHTML = '';
  items.forEach((item) => {
    const div = document.createElement('button');
    div.type = 'button';
    div.className = 'media-item';
    const img = item.mime?.startsWith('image/')
      ? `<img src="${escapeAttr(item.thumb ? apiUrl(item.thumb) : item.url)}" alt="">`
      : '<div class="media-item__name">File</div>';
    const draft = item.published ? '' : ' <span class="muted">draft</span>';
    div.innerHTML = `${img}<div class="media-item__name">${escapeHtml(item.filename)}${draft}</div>`;
    div.addEventListener('click', () => {
      if (picking && state.mediaPicker) {
        state.mediaPicker(item.path || item.url);
        document.getElementById('modal-media-pick').close();
      }
    });
    grid.appendChild(div);
  });
}

function bindCms() {
  document.getElementById('cms-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    let mapping = {};
    const raw = document.getElementById('cms-mapping').value.trim();
    if (raw) {
      try { mapping = JSON.parse(raw); } catch { showStatus(toast, 'Mapping must be JSON', true); return; }
    }
    const payload = {
      id: document.getElementById('cms-id').value ? Number(document.getElementById('cms-id').value) : undefined,
      name: document.getElementById('cms-name').value,
      adapter: 'http_json',
      config: {
        url: document.getElementById('cms-url').value,
        list_path: document.getElementById('cms-list-path').value,
        id_path: document.getElementById('cms-id-path').value || 'id',
        mapping,
      },
    };
    try {
      const data = await api(payload.id ? `api/cms/sources/${payload.id}` : 'api/cms/sources', {
        method: payload.id ? 'PUT' : 'POST',
        body: JSON.stringify(payload),
      });
      document.getElementById('cms-id').value = data.source.id;
      showStatus(toast, 'Source saved');
      await loadCms();
    } catch (err) {
      showStatus(toast, err.message, true);
    }
  });

  document.getElementById('btn-cms-sync').addEventListener('click', async () => {
    const id = document.getElementById('cms-id').value;
    if (!id) { showStatus(toast, 'Save a source first', true); return; }
    try {
      const data = await api(`api/cms/sources/${id}/sync`, { method: 'POST' });
      document.getElementById('cms-entries').textContent = JSON.stringify(data.entries, null, 2);
      showStatus(toast, `Synced ${data.fetched} entries, applied to ${data.applied} blocks`);
    } catch (err) {
      showStatus(toast, err.message, true);
    }
  });
}

async function loadCms() {
  const data = await api('api/cms/sources');
  const list = document.getElementById('cms-source-list');
  list.innerHTML = '';
  data.sources.forEach((source) => {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'event';
    btn.innerHTML = `<div class="event__details"><h3>${escapeHtml(source.name)}</h3><p>${escapeHtml(source.config?.url || '')}</p></div>`;
    btn.addEventListener('click', async () => {
      document.getElementById('cms-id').value = source.id;
      document.getElementById('cms-name').value = source.name;
      document.getElementById('cms-url').value = source.config?.url || '';
      document.getElementById('cms-list-path').value = source.config?.list_path || '';
      document.getElementById('cms-id-path').value = source.config?.id_path || 'id';
      document.getElementById('cms-mapping').value = JSON.stringify(source.config?.mapping || {}, null, 2);
      const detail = await api(`api/cms/sources/${source.id}`);
      document.getElementById('cms-entries').textContent = JSON.stringify(detail.entries, null, 2);
    });
    li.appendChild(btn);
    list.appendChild(li);
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
      await loadUsers();
    } catch (err) {
      showStatus(toast, err.message, true);
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
        await api(`api/users/${u.id}`, { method: 'DELETE' });
        await loadUsers();
      });
      tr.lastChild.appendChild(btn);
    }
    tbody.appendChild(tr);
  });
}

let importFilePath = null;

function openImportModal() {
  importFilePath = null;
  const fileInput = document.getElementById('import-file');
  if (fileInput) fileInput.value = '';
  document.getElementById('import-name').value = '';
  document.getElementById('import-selected').textContent = 'No site file selected.';
  document.getElementById('modal-import').showModal();
  loadImportBrowse();
}

async function loadImportBrowse(path) {
  const q = path ? `?path=${encodeURIComponent(path)}` : '';
  const data = await api(`api/settings/browse${q}`);
  document.getElementById('import-breadcrumb').textContent = data.path;
  const list = document.getElementById('import-dir-list');
  list.innerHTML = '';
  if (data.parent) {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'dir-up';
    btn.textContent = '↑ Parent folder';
    btn.addEventListener('click', () => loadImportBrowse(data.parent));
    li.appendChild(btn);
    list.appendChild(li);
  }
  data.directories.forEach((dir) => {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = `📁 ${dir.name}`;
    btn.addEventListener('click', () => loadImportBrowse(dir.path));
    li.appendChild(btn);
    list.appendChild(li);
  });
  (data.html_files || []).forEach((file) => {
    const name = typeof file === 'string' ? file : file.name;
    const full = typeof file === 'string' ? `${data.path}/${file}` : file.path;
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = name;
    btn.addEventListener('click', () => {
      importFilePath = full;
      document.getElementById('import-selected').textContent = `Selected: ${name}`;
      if (!document.getElementById('import-name').value) {
        document.getElementById('import-name').value = name.replace(/\.html?$/i, '');
      }
    });
    li.appendChild(btn);
    list.appendChild(li);
  });
}

function bindImport() {
  document.getElementById('import-file')?.addEventListener('change', (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    importFilePath = null;
    document.getElementById('import-selected').textContent = `Upload: ${file.name}`;
    if (!document.getElementById('import-name').value) {
      document.getElementById('import-name').value = file.name.replace(/\.html?$/i, '');
    }
  });
  document.getElementById('btn-import-run')?.addEventListener('click', async () => {
    const name = document.getElementById('import-name').value.trim();
    const payload = {
      name: name || undefined,
      create_template: document.getElementById('import-create-template').checked,
      create_page: document.getElementById('import-create-page').checked,
      copy_assets: document.getElementById('import-copy-assets').checked,
      template_id: state.templates[0]?.id,
    };
    try {
      let data;
      const file = document.getElementById('import-file').files?.[0];
      if (file) {
        const body = new FormData();
        body.append('file', file);
        if (payload.name) body.append('name', payload.name);
        body.append('create_template', payload.create_template ? '1' : '0');
        body.append('create_page', payload.create_page ? '1' : '0');
        body.append('copy_assets', payload.copy_assets ? '1' : '0');
        if (payload.template_id) body.append('template_id', String(payload.template_id));
        data = await api('api/import/html', { method: 'POST', body });
      } else if (importFilePath) {
        data = await api('api/import/html', {
          method: 'POST',
          body: JSON.stringify({ ...payload, path: importFilePath }),
        });
      } else {
        showStatus(toast, 'Choose an HTML file to import', true);
        return;
      }
      document.getElementById('modal-import').close();
      await Promise.all([loadTypes(), loadTemplates(), loadPages()]);
      if (data.page?.id) {
        switchWorkspace('studio');
        await openPage(data.page.id);
      } else {
        switchWorkspace('library');
        renderLibrary();
      }
      const copied = data.media?.length ? `, ${data.media.length} assets` : '';
      showStatus(toast, `Imported${copied}`);
    } catch (err) {
      showStatus(toast, err.message, true);
    }
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
          backup_retention: Number(document.getElementById('settings-retention').value),
          inline_image_max_bytes: Number(document.getElementById('settings-inline-bytes').value),
          site_title: document.getElementById('settings-title').value,
          site_root: document.getElementById('settings-site-root').value,
        }),
      });
      showStatus(toast, 'Settings saved');
    } catch (err) {
      showStatus(toast, err.message, true);
    }
  });
  document.getElementById('btn-publish-all').addEventListener('click', async () => {
    try {
      const data = await api('api/publish-all', { method: 'POST' });
      showStatus(toast, `Published ${data.pages.length} pages`);
    } catch (err) {
      showStatus(toast, err.message, true);
    }
  });
  let browsePath = null;
  document.getElementById('btn-browse-root').addEventListener('click', () => {
    document.getElementById('modal-browse').showModal();
    loadBrowse();
  });
  document.getElementById('modal-select-dir').addEventListener('click', () => {
    if (browsePath) document.getElementById('settings-site-root').value = browsePath;
    document.getElementById('modal-browse').close();
  });

  async function loadBrowse(path) {
    const q = path ? `?path=${encodeURIComponent(path)}` : '';
    const data = await api(`api/settings/browse${q}`);
    browsePath = data.valid ? data.path : null;
    document.getElementById('modal-select-dir').disabled = !data.valid;
    const crumb = document.getElementById('modal-breadcrumb');
    crumb.textContent = data.path;
    const list = document.getElementById('modal-dir-list');
    list.innerHTML = '';
    if (data.parent) {
      const li = document.createElement('li');
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'dir-up';
      btn.textContent = '↑ Parent folder';
      btn.addEventListener('click', () => loadBrowse(data.parent));
      li.appendChild(btn);
      list.appendChild(li);
    }
    data.directories.forEach((dir) => {
      const li = document.createElement('li');
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = dir.name;
      btn.addEventListener('click', () => loadBrowse(dir.path));
      li.appendChild(btn);
      list.appendChild(li);
    });
  }
}

async function loadSettings() {
  const data = await api('api/settings');
  const s = data.settings;
  document.getElementById('settings-site-root').value = s.site_root || '';
  document.getElementById('settings-web-base').value = s.site_web_base || '/';
  document.getElementById('settings-upload-dir').value = s.upload_dir || 'uploads';
  document.getElementById('settings-retention').value = s.backup_retention || 20;
  document.getElementById('settings-inline-bytes').value = s.inline_image_max_bytes ?? 8192;
  document.getElementById('settings-title').value = s.site_title || '';
}

function applyLocalTypes(nodes) {
  nodes.forEach((node) => {
    const type = state.blockTypes.find((t) => t.id === node.block_type_id);
    if (type) node.block_type = type;
    applyLocalTypes(node.children || []);
  });
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function escapeAttr(str) {
  return escapeHtml(str).replace(/"/g, '&quot;');
}

init();
