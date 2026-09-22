/**
 * admin/js/admin_main.js
 * Admin panel client-side logic.
 */

const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

function showMsg(boxEl, type, text) {
  if (!boxEl) return;
  boxEl.className = `msg-box show ${type}`;
  const icons = { success: '✔', error: '✖', info: 'ℹ' };
  boxEl.innerHTML = `<span>${icons[type] || 'ℹ'}</span><span>${text}</span>`;
  boxEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearMsg(boxEl) {
  if (!boxEl) return;
  boxEl.className = 'msg-box';
  boxEl.innerHTML = '';
}

function setLoading(btn, loading) {
  if (!btn) return;
  const spinner = btn.querySelector('.spinner');
  const label   = btn.querySelector('.btn-label');
  btn.disabled  = loading;
  if (spinner) spinner.classList.toggle('show', loading);
  if (label)   label.style.display = loading ? 'none' : '';
}

// Modal handling
function openModal(id) {
  const m = $(`#${id}`);
  if (m) m.classList.add('active');
}

function closeModal(id) {
  const m = $(`#${id}`);
  if (m) m.classList.remove('active');
}

$$('.modal-close, [data-dismiss="modal"]').forEach(btn => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    const m = btn.closest('.modal-overlay');
    if (m) m.classList.remove('active');
  });
});

/* ============================================================
   Admin Login
   ============================================================ */
function initAdminLogin() {
  const form = $('#adminLoginForm');
  if (!form) return;
  const msgBox = $('#msgBox');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearMsg(msgBox);

    const btn = form.querySelector('[type=submit]');
    setLoading(btn, true);

    try {
      const resp = await fetch('actions/login_action.php', {
        method: 'POST',
        body: new FormData(form),
      });
      const data = await resp.json();
      if (data.success) {
        showMsg(msgBox, 'success', 'Login successful! Redirecting...');
        // replace() removes the login page from browser history stack
        window.location.replace(data.redirect || 'index.php');
      } else {
        showMsg(msgBox, 'error', data.message || 'Login failed.');
      }
    } catch {
      showMsg(msgBox, 'error', 'Network error. Please try again.');
    } finally {
      setLoading(btn, false);
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initAdminLogin();

  // Show flash messages
  const params = new URLSearchParams(location.search);
  const msgBox = $('#msgBox');
  if (params.get('logout') === '1') {
    showMsg(msgBox, 'info', 'Logged out successfully.');
  }
  if (params.get('expired') === '1') {
    showMsg(msgBox, 'error', 'Session expired. Please login again.');
  }
});
