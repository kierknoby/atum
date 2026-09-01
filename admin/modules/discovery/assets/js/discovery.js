/* SPDX-License-Identifier: GPL-3.0-or-later */
(() => {
  const button = document.getElementById('rescan');
  if (!button) return;

  button.addEventListener('click', async () => {
    button.disabled = true;
    button.textContent = 'Scanning…';
    try {
      const response = await fetch('ajax.php?module=discovery&command=scan', {
        headers: {'Accept': 'application/json'}
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      await response.json();
      window.location.reload();
    } catch (error) {
      window.alert(`Discovery failed: ${error.message}`);
    } finally {
      button.disabled = false;
      button.textContent = 'Rescan';
    }
  });
})();
