export function defaultData(schema) {
  const data = {};
  for (const field of schema?.fields || []) {
    if (!field.name) continue;
    if (field.type === 'number') data[field.name] = field.name === 'height' ? 48 : 0;
    else data[field.name] = '';
  }
  return data;
}

export function renderFields(container, schema, data, onChange, helpers) {
  container.innerHTML = '';
  const fields = schema?.fields || [];
  if (!fields.length) {
    container.innerHTML = '<p class="muted">This block has no fields. Use the Code tab or add child blocks.</p>';
    return;
  }

  for (const field of fields) {
    const wrap = document.createElement('div');
    wrap.className = 'contact-form__field';
    const label = document.createElement('label');
    label.textContent = field.label || field.name;
    wrap.appendChild(label);

    const value = data[field.name] ?? '';
    const type = field.type || 'text';

    if (type === 'textarea') {
      const el = document.createElement('textarea');
      el.rows = 4;
      el.value = value;
      el.addEventListener('input', () => onChange(field.name, el.value));
      wrap.appendChild(el);
    } else if (type === 'richtext') {
      wrap.appendChild(richText(value, (html) => onChange(field.name, html), helpers));
    } else if (type === 'select') {
      const el = document.createElement('select');
      for (const opt of field.options || []) {
        const o = document.createElement('option');
        o.value = opt;
        o.textContent = opt;
        if (String(opt) === String(value)) o.selected = true;
        el.appendChild(o);
      }
      el.addEventListener('change', () => onChange(field.name, el.value));
      wrap.appendChild(el);
    } else if (type === 'image') {
      const row = document.createElement('div');
      row.className = 'image-field';
      const el = document.createElement('input');
      el.type = 'url';
      el.value = value;
      el.placeholder = '/uploads/…';
      el.addEventListener('input', () => onChange(field.name, el.value));
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn--ghost btn--sm';
      btn.textContent = 'Library';
      btn.addEventListener('click', () => helpers.pickMedia((url) => {
        el.value = url;
        onChange(field.name, url);
      }));
      row.appendChild(el);
      row.appendChild(btn);
      wrap.appendChild(row);
    } else if (type === 'number') {
      const el = document.createElement('input');
      el.type = 'number';
      el.value = value;
      el.addEventListener('input', () => onChange(field.name, el.value === '' ? 0 : Number(el.value)));
      wrap.appendChild(el);
    } else if (type === 'code') {
      const el = document.createElement('textarea');
      el.rows = 10;
      el.value = value;
      el.style.fontFamily = 'ui-monospace, monospace';
      el.addEventListener('input', () => onChange(field.name, el.value));
      wrap.appendChild(el);
    } else {
      const el = document.createElement('input');
      el.type = type === 'url' ? 'url' : 'text';
      el.value = value;
      el.addEventListener('input', () => onChange(field.name, el.value));
      wrap.appendChild(el);
    }

    container.appendChild(wrap);
  }
}

function richText(html, onChange, helpers) {
  const box = document.createElement('div');
  const bar = document.createElement('div');
  bar.className = 'rich-toolbar';
  const cmds = [
    ['bold', 'B'],
    ['italic', 'I'],
    ['createLink', 'Link'],
    ['insertImage', 'Image'],
  ];
  const canvas = document.createElement('div');
  canvas.className = 'rich-canvas';
  canvas.contentEditable = 'true';
  canvas.innerHTML = html || '';
  canvas.addEventListener('input', () => onChange(canvas.innerHTML));

  for (const [cmd, label] of cmds) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn--ghost btn--sm';
    btn.innerHTML = label;
    btn.addEventListener('click', () => {
      if (cmd === 'createLink') {
        const url = prompt('URL:');
        if (url) document.execCommand('createLink', false, url);
      } else if (cmd === 'insertImage') {
        helpers.pickMedia((url) => document.execCommand('insertImage', false, url));
      } else {
        document.execCommand(cmd, false, null);
      }
      onChange(canvas.innerHTML);
    });
    bar.appendChild(btn);
  }

  box.appendChild(bar);
  box.appendChild(canvas);
  return box;
}

export function unflattenTokens(flat) {
  const out = {};
  for (const [path, value] of Object.entries(flat)) {
    const parts = path.split('.');
    let cur = out;
    parts.forEach((part, i) => {
      if (i === parts.length - 1) {
        cur[part] = value;
      } else {
        cur[part] = cur[part] && typeof cur[part] === 'object' ? cur[part] : {};
        cur = cur[part];
      }
    });
  }
  return out;
}

export function flattenTokens(obj, prefix = '', acc = {}) {
  for (const [key, value] of Object.entries(obj || {})) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      flattenTokens(value, path, acc);
    } else {
      acc[path] = value ?? '';
    }
  }
  return acc;
}
