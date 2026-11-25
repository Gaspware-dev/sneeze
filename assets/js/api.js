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

export async function stream(path, options = {}, onEvent) {
  const headers = { Accept: 'text/event-stream', ...(options.headers || {}) };
  if (options.body && !(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }
  if (csrf && options.method && options.method !== 'GET') {
    headers['X-CSRF-Token'] = csrf;
  }

  const res = await fetch(apiUrl(path), { ...options, headers });
  if (!res.ok || !res.body) {
    const text = res.body ? await res.text() : '';
    let message = `Request failed (${res.status})`;
    try {
      const data = JSON.parse(text);
      if (data.error) message = data.error;
    } catch {
      if (text) message = text;
    }
    throw new Error(message);
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  const events = [];
  const take = (raw) => {
    const dataLines = raw.split('\n')
      .filter((line) => line.startsWith('data:'))
      .map((line) => line.slice(5).replace(/^ /, ''));
    if (!dataLines.length) return;
    const payload = dataLines.join('\n');
    let event = payload;
    try {
      event = JSON.parse(payload);
    } catch {
      event = payload;
    }
    events.push(event);
    if (typeof onEvent === 'function') onEvent(event);
  };

  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    buffer = buffer.replace(/\r\n/g, '\n');
    let split = buffer.indexOf('\n\n');
    while (split !== -1) {
      take(buffer.slice(0, split));
      buffer = buffer.slice(split + 2);
      split = buffer.indexOf('\n\n');
    }
  }
  if (buffer.trim()) take(buffer);
  return events;
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
