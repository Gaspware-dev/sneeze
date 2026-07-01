const moduleUrl = new URL(import.meta.url);
const assetBase = moduleUrl.pathname.replace(/\/js\/[^/]+$/, '');
const appRoot = assetBase.replace(/\/assets$/, '');

export function apiUrl(path) {
  return `${appRoot}/${path.replace(/^\//, '')}`;
}

let csrf = '';

export function setCsrf(token) {
  csrf = token || '';
}

export async function api(path, options = {}) {
  const headers = { ...(options.headers || {}) };
  if (options.body && !(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }
  if (csrf && options.method && options.method !== 'GET') {
    headers['X-CSRF-Token'] = csrf;
  }

  const res = await fetch(apiUrl(path), { ...options, headers });
  const contentType = res.headers.get('Content-Type') || '';

  if (contentType.includes('application/json')) {
    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || `Request failed (${res.status})`);
    }
    return data;
  }

  if (!res.ok) {
    throw new Error(`Request failed (${res.status})`);
  }
  return res.text();
}

export function showStatus(el, message, isError = false) {
  if (!el) return;
  el.textContent = message;
  el.classList.remove('hidden', 'contact-form__status--error');
  if (isError) el.classList.add('contact-form__status--error');
}

export function hideStatus(el) {
  el?.classList.add('hidden');
}
