let enabled = true;

const CODE_MODES = {
  html: 'htmlmixed',
  css: 'css',
  js: { name: 'javascript', typescript: true },
};

export function setVisualEditors(on) {
  enabled = on !== false;
}

export function visualEditorsEnabled() {
  return enabled;
}

export function normalizeQuillHtml(html) {
  const trimmed = String(html || '').trim();
  const compact = trimmed.replace(/\s+/g, ' ');
  if (compact === '' || compact === '<p></p>' || compact === '<p><br></p>' || compact === '<p><br/></p>') {
    return '';
  }
  return trimmed;
}

function readQuillHtml(quill) {
  if (typeof quill.getSemanticHTML === 'function') {
    return quill.getSemanticHTML();
  }
  return quill.root?.innerHTML || '';
}

export function createQuillEditor(html, onChange, pickImage) {
  if (!enabled || typeof window.Quill !== 'function') {
    return null;
  }
  const box = document.createElement('div');
  box.className = 'quill-field';
  const host = document.createElement('div');
  box.appendChild(host);
  const quill = new window.Quill(host, {
    theme: 'snow',
    modules: {
      toolbar: {
        container: [
          [{ header: [2, 3, false] }],
          ['bold', 'italic'],
          [{ list: 'ordered' }, { list: 'bullet' }],
          ['link', 'blockquote', 'image'],
        ],
        handlers: {
          image() {
            const editor = this.quill;
            if (typeof pickImage !== 'function' || !editor) return;
            const range = editor.getSelection(true);
            pickImage((url) => {
              if (!url) return;
              const index = range ? range.index : Math.max(editor.getLength() - 1, 0);
              editor.insertEmbed(index, 'image', url, 'user');
            });
          },
        },
      },
    },
  });
  let ready = false;
  quill.on('text-change', () => {
    if (!ready) return;
    onChange(normalizeQuillHtml(readQuillHtml(quill)));
  });
  const initial = String(html || '');
  if (initial !== '') {
    quill.clipboard.dangerouslyPasteHTML(initial);
  }
  ready = true;
  return box;
}

export function codeMode(mode) {
  const key = String(mode || 'html').toLowerCase();
  if (key === 'css') return 'css';
  if (key === 'js' || key === 'javascript' || key === 'ts' || key === 'typescript') return 'js';
  return 'html';
}

export function mountCodeEditor(textarea, mode) {
  if (!textarea) return null;
  if (!enabled || typeof window.CodeMirror !== 'function') {
    unmountCodeEditor(textarea);
    return null;
  }
  const resolved = codeMode(mode);
  if (textarea._sneezeCm && textarea.dataset.codeMode === resolved) {
    if (textarea._sneezeCm.getValue() !== textarea.value) {
      textarea._sneezeCm.setValue(textarea.value);
    }
    return textarea._sneezeCm;
  }
  unmountCodeEditor(textarea);
  textarea.dataset.codeMode = resolved;
  textarea.classList.add('is-editor-source');
  const host = document.createElement('div');
  host.className = 'codemirror-wrap codemirror-wrap--compact';
  textarea.insertAdjacentElement('afterend', host);
  const cm = window.CodeMirror(host, {
    value: textarea.value,
    mode: CODE_MODES[resolved],
    lineNumbers: true,
    lineWrapping: true,
  });
  cm.on('change', (instance, change) => {
    if (change.origin === 'setValue') return;
    textarea.value = instance.getValue();
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  });
  textarea._sneezeCm = cm;
  textarea._sneezeCmHost = host;
  return cm;
}

export function unmountCodeEditor(textarea) {
  if (!textarea) return;
  if (textarea._sneezeCm) {
    textarea.value = textarea._sneezeCm.getValue();
  }
  textarea._sneezeCmHost?.remove();
  textarea._sneezeCm = null;
  textarea._sneezeCmHost = null;
  delete textarea.dataset.codeMode;
  textarea.classList.remove('is-editor-source');
}

export function setCodeValue(textarea, value) {
  if (!textarea) return;
  const next = value == null ? '' : String(value);
  textarea.value = next;
  const cm = textarea._sneezeCm;
  if (cm && cm.getValue() !== next) {
    cm.setValue(next);
  }
}

export function refreshCodeEditor(textarea) {
  const cm = textarea?._sneezeCm;
  if (!cm) return;
  cm.refresh();
}

export function bindCodeTextarea(textarea, mode) {
  if (!textarea) return;
  if (enabled) mountCodeEditor(textarea, mode);
  else unmountCodeEditor(textarea);
}
