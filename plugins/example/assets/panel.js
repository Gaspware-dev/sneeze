export default function boot(sneeze) {
  const pageLine = document.getElementById('example-page');
  const out = document.getElementById('example-out');

  const show = (page) => {
    if (!pageLine) return;
    pageLine.textContent = page ? `${page.title} (${page.slug})` : 'No page open';
  };

  show(sneeze.studio.getState().page);
  sneeze.studio.on('page', show);
  sneeze.studio.on('saved', show);

  document.getElementById('example-ping')?.addEventListener('click', async () => {
    try {
      const data = await sneeze.api(`api/plugin/${sneeze.plugin.id}/ping`);
      if (out) out.textContent = JSON.stringify(data, null, 2);
      sneeze.studio.notify('Example plugin pinged');
    } catch (error) {
      sneeze.studio.notify(error.message || 'Ping failed', true);
    }
  });

  document.getElementById('example-apply')?.addEventListener('click', () => {
    const applied = sneeze.studio.applyPage({ title: 'Example title' });
    sneeze.studio.notify(applied ? 'Applied title' : 'No page open', !applied);
  });

  document.getElementById('example-patch')?.addEventListener('click', () => {
    const page = sneeze.studio.getState().page;
    const blocks = page?.blocks || [];
    const block = blocks[0];
    if (!block) {
      sneeze.studio.notify('No block to patch', true);
      return;
    }
    const data = block.data || {};
    const key = Object.keys(data)[0] || 'text';
    const sibling = blocks[1];
    const patched = sneeze.studio.patchBlockData(block.id, { [key]: 'Patched by example' });
    if (out) {
      const next = sneeze.studio.getState().page?.blocks || [];
      out.textContent = JSON.stringify({
        patched,
        sibling: sibling ? next.find((item) => String(item.id) === String(sibling.id))?.data : null,
      }, null, 2);
    }
    sneeze.studio.notify(patched ? 'Patched block' : 'Patch failed', !patched);
  });

  document.getElementById('example-undo')?.addEventListener('click', () => {
    const restored = sneeze.studio.undoCheckpoint();
    sneeze.studio.notify(restored ? 'Undid last change' : 'Nothing to undo', !restored);
  });

  document.getElementById('example-stream')?.addEventListener('click', async () => {
    try {
      const events = await sneeze.stream(`api/plugin/${sneeze.plugin.id}/slow`);
      if (out) out.textContent = JSON.stringify(events, null, 2);
      sneeze.studio.notify('Stream finished');
    } catch (error) {
      sneeze.studio.notify(error.message || 'Stream failed', true);
    }
  });
}
