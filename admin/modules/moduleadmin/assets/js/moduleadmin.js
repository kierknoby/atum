/* SPDX-License-Identifier: GPL-3.0-or-later */
(() => {
  const root = document.getElementById('moduleadmin-data');
  if (!root) return;
  const csrf = root.dataset.csrf || '';
  document.querySelectorAll('.module-toggle').forEach((button) => {
    button.addEventListener('click', async () => {
      const action = button.dataset.action || '';
      if (action === 'uninstall' && !window.confirm(`Uninstall the ${button.dataset.module || ''} Atum module?`)) return;
      button.disabled = true;
      const body = new URLSearchParams({rawname: button.dataset.module || ''});
      try {
        const response = await fetch(`ajax.php?module=moduleadmin&command=${encodeURIComponent(action)}`, {
          method: 'POST',
          headers: {'Accept': 'application/json', 'X-Atum-CSRF': csrf, 'Content-Type': 'application/x-www-form-urlencoded'},
          body
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || `HTTP ${response.status}`);
        window.location.reload();
      } catch (error) {
        window.alert(`Module operation failed: ${error.message}`);
        button.disabled = false;
      }
    });
  });
})();
