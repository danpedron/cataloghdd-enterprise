document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.notice.success').forEach((notice) => {
    window.setTimeout(() => {
      notice.style.transition = 'opacity .35s ease, transform .35s ease';
      notice.style.opacity = '0';
      notice.style.transform = 'translateY(-4px)';
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
});
