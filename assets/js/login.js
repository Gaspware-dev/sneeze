import { api, setCsrf, showStatus } from './api.js';

const form = document.getElementById('login-form');
const statusEl = document.getElementById('login-status');

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  statusEl.classList.add('hidden');
  try {
    const data = await api('api/auth/login', {
      method: 'POST',
      body: JSON.stringify({
        username: document.getElementById('username').value,
        password: document.getElementById('password').value,
      }),
    });
    setCsrf(data.csrf);
    window.location.href = '.';
  } catch (err) {
    showStatus(statusEl, err.message, true);
  }
});
