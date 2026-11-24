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
}
