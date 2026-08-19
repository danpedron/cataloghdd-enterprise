document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.notice.success').forEach((notice) => {
    window.setTimeout(() => {
      notice.classList.add('is-dismissing');
      window.setTimeout(() => notice.remove(), 380);
    }, 5000);
  });

  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const button = form.querySelector('button[type="submit"]');
      if (!button || button.dataset.confirmed === 'yes') return;
      if (button.dataset.danger === 'yes') {
        const message = button.dataset.confirmMessage || 'Deseja realmente continuar?';
        if (!window.confirm(message)) event.preventDefault();
      }
    });
  });

  let explorer = document.querySelector('#file-explorer');
  let activeRequest = null;

  const isExplorerLocation = (url) => {
    const route = url.searchParams.get('r');
    return route === 'disk' && url.searchParams.has('id') && !url.searchParams.has('q');
  };

  const refreshExplorer = async (href, historyMode = 'push') => {
    const target = new URL(href, window.location.href);
    if (!explorer || !isExplorerLocation(target)) {
      window.location.assign(target.href);
      return;
    }

    if (activeRequest) activeRequest.abort();
    activeRequest = new AbortController();
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;
    explorer.classList.add('is-loading');
    explorer.setAttribute('aria-busy', 'true');

    try {
      const response = await window.fetch(target.href, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'CatalogHDDExplorer' },
        signal: activeRequest.signal,
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);

      const documentResponse = new DOMParser().parseFromString(await response.text(), 'text/html');
      const nextExplorer = documentResponse.querySelector('#file-explorer');
      if (!nextExplorer) throw new Error('Explorador não encontrado na resposta');

      explorer.replaceWith(nextExplorer);
      explorer = nextExplorer;
      if (historyMode === 'push') window.history.pushState({ explorer: true }, '', target.href);
      document.title = documentResponse.title || document.title;
      window.requestAnimationFrame(() => {
        window.scrollTo(scrollX, scrollY);
        explorer.classList.add('is-refreshed');
        window.setTimeout(() => explorer?.classList.remove('is-refreshed'), 180);
      });
    } catch (error) {
      if (error.name !== 'AbortError') window.location.assign(target.href);
    } finally {
      if (explorer) {
        explorer.classList.remove('is-loading');
        explorer.removeAttribute('aria-busy');
      }
      activeRequest = null;
    }
  };

  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('#file-explorer a[href]');
    if (!link || link.target || link.hasAttribute('download')) return;
    const target = new URL(link.href, window.location.href);
    if (!isExplorerLocation(target)) return;
    event.preventDefault();
    refreshExplorer(target.href);
  });

  window.addEventListener('popstate', () => {
    const target = new URL(window.location.href);
    if (isExplorerLocation(target)) refreshExplorer(target.href, 'pop');
  });
});
