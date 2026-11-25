import { api, apiUrl, stream } from './api.js';

const studioListeners = {
  page: new Set(),
  selection: new Set(),
  saved: new Set(),
};

function resetStudioEvents() {
  Object.values(studioListeners).forEach((set) => set.clear());
}

export function emitStudio(event, payload) {
  let copy = payload;
  try {
    copy = payload == null ? payload : JSON.parse(JSON.stringify(payload));
  } catch {
    copy = payload;
  }
  studioListeners[event]?.forEach((fn) => {
    try {
      fn(copy);
    } catch (error) {
      console.error(error);
    }
  });
}

function studioBridge(host) {
  return {
    getState() {
      const raw = host.getState();
      try {
        return JSON.parse(JSON.stringify(raw));
      } catch {
        return raw;
      }
    },
    applyPage(partial, options) {
      return host.applyPage(partial, options);
    },
    insertBlock(spec, options) {
      return host.insertBlock(spec, options);
    },
    updateBlockData(id, data, options) {
      return host.updateBlockData(id, data, options);
    },
    patchBlockData(id, partial, options) {
      return host.patchBlockData(id, partial, options);
    },
    removeBlock(id, options) {
      return host.removeBlock(id, options);
    },
    selectBlock(id) {
      host.selectBlock(id);
    },
    openPage(id) {
      return host.openPage(id);
    },
    openSideTab(name) {
      return host.openSideTab(name);
    },
    openWorkspace(name) {
      return host.openWorkspace(name);
    },
    pushCheckpoint() {
      return host.pushCheckpoint();
    },
    undoCheckpoint() {
      return host.undoCheckpoint();
    },
    notify(message, isError = false) {
      host.notify(message, !!isError);
    },
    on(event, fn) {
      const set = studioListeners[event];
      if (!set || typeof fn !== 'function') return () => {};
      set.add(fn);
      return () => set.delete(fn);
    },
  };
}

function clearPluginUi() {
  document.querySelectorAll('[data-plugin-ui]').forEach((el) => el.remove());
}

async function bootScript(contribution, studio) {
  if (!contribution.script_url) return;
  try {
    const mod = await import(apiUrl(contribution.script_url));
    if (typeof mod.default === 'function') {
      await mod.default({
        api,
        stream,
        plugin: { id: contribution.plugin_id, name: contribution.plugin_name },
        studio,
      });
    }
  } catch (error) {
    console.error(error);
    studio.notify(error.message || 'Plugin script failed', true);
  }
}

export async function mountPlugins(host) {
  resetStudioEvents();
  clearPluginUi();
  const studio = studioBridge(host);
  window.Sneeze = { api, stream, studio };

  let payload;
  try {
    payload = await api('api/plugins');
  } catch (error) {
    console.error(error);
    return;
  }

  const nav = document.getElementById('workspace-nav');
  const tabs = document.getElementById('side-tabs');
  const seenStyles = new Set();

  for (const plugin of payload.plugins || []) {
    if (!plugin.enabled) continue;
    const contributions = plugin.contributions || {};
    for (const workspace of contributions.workspaces || []) {
      if (workspace.admin && !host.isAdmin) continue;
      mountWorkspace(nav, workspace);
      if (workspace.style_url) seenStyles.add(workspace.style_url);
      await bootScript(workspace, studio);
    }
    for (const tab of contributions.side_tabs || []) {
      if (tab.admin && !host.isAdmin) continue;
      mountSideTab(tabs, tab);
      if (tab.style_url) seenStyles.add(tab.style_url);
      await bootScript(tab, studio);
    }
  }

  seenStyles.forEach((href) => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = apiUrl(href);
    link.dataset.pluginUi = '1';
    document.head.appendChild(link);
  });
}

function mountWorkspace(nav, workspace) {
  if (!nav) return;
  const name = `plugin-${workspace.plugin_id}-${workspace.id}`;
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'studio-workspaces__btn';
  button.dataset.workspace = name;
  button.dataset.pluginUi = '1';
  button.textContent = workspace.label;
  nav.appendChild(button);

  const panel = document.createElement('div');
  panel.className = 'workspace workspace--scroll';
  panel.id = `workspace-${name}`;
  panel.dataset.pluginUi = '1';
  panel.innerHTML = workspace.html || '';
  document.body.appendChild(panel);
}

function mountSideTab(tabs, tab) {
  if (!tabs) return;
  const name = `plugin-${tab.plugin_id}-${tab.id}`;
  const button = document.createElement('button');
  button.type = 'button';
  button.dataset.tab = name;
  button.dataset.pluginUi = '1';
  button.textContent = tab.label;
  tabs.appendChild(button);

  const panel = document.createElement('div');
  panel.className = 'side-tab';
  panel.id = `tab-${name}`;
  panel.dataset.pluginUi = '1';
  panel.innerHTML = tab.html || '';
  const side = tabs.parentElement;
  side?.appendChild(panel);
}
