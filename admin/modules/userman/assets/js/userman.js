/* SPDX-License-Identifier: GPL-3.0-or-later */
(() => {
  const data = document.getElementById('userman-data');
  if (!data) return;
  const csrf = data.dataset.csrf || '';

  async function request(command, values) {
    const body = new URLSearchParams(values);
    const response = await fetch(`ajax.php?module=userman&command=${encodeURIComponent(command)}`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-Atum-CSRF': csrf,
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || `HTTP ${response.status}`);
    return result;
  }

  const create = document.getElementById('user-create-form');
  create?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = create.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    const form = new FormData(create);
    try {
      await request('create', {
        username: String(form.get('username') || ''),
        role: String(form.get('role') || 'viewer'),
        password: String(form.get('password') || '')
      });
      window.location.reload();
    } catch (error) {
      window.alert(`Account creation failed: ${error.message}`);
      if (button) button.disabled = false;
    }
  });

  document.querySelectorAll('tr[data-user-id]').forEach((row) => {
    const id = row.dataset.userId || '';
    const toggle = row.querySelector('.user-toggle');
    toggle?.addEventListener('click', async () => {
      toggle.disabled = true;
      try {
        await request(toggle.dataset.action || '', {id});
        window.location.reload();
      } catch (error) {
        window.alert(`Account change failed: ${error.message}`);
        toggle.disabled = false;
      }
    });

    const passwordButton = row.querySelector('.user-password');
    const passwordInput = row.querySelector('.user-new-password');
    passwordButton?.addEventListener('click', async () => {
      const password = passwordInput?.value || '';
      if (password.length < 12) {
        window.alert('Password must be at least 12 characters.');
        return;
      }
      passwordButton.disabled = true;
      try {
        await request('password', {id, password});
        if (passwordInput) passwordInput.value = '';
        window.alert('Password changed. Existing sessions for that account will be invalidated.');
      } catch (error) {
        window.alert(`Password change failed: ${error.message}`);
      } finally {
        passwordButton.disabled = false;
      }
    });

    const remove = row.querySelector('.user-delete');
    remove?.addEventListener('click', async () => {
      if (!window.confirm('Delete this Atum account?')) return;
      remove.disabled = true;
      try {
        await request('delete', {id});
        window.location.reload();
      } catch (error) {
        window.alert(`Account deletion failed: ${error.message}`);
        remove.disabled = false;
      }
    });
  });
})();
