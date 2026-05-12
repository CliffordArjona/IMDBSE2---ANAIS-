// ── ANAIS Modal Outside Click Protection ───────────────────
// This must run in capture phase so older click handlers cannot close the modal.
// Clicking the dark outside area/backdrop will NOT close the form.
// Only the X button or Cancel button should close it.
document.addEventListener('click', function(e) {
  if (e.target && e.target.classList && e.target.classList.contains('modal-backdrop')) {
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();
    return false;
  }
}, true);

// ANAIS – app.js
'use strict';

// ── Modal helpers ──────────────────────────────────────────
function openModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.remove('hidden'); }
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.add('hidden'); }
}
// Close on backdrop click
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal-backdrop')) {
    e.target.classList.add('hidden');
  }
});

// ── Confirm delete ─────────────────────────────────────────
function confirmDelete(formId, msg) {
  msg = msg || 'Are you sure you want to delete this record? This cannot be undone.';
  if (confirm(msg)) {
    document.getElementById(formId).submit();
  }
}

// ── Auto-dismiss alerts ────────────────────────────────────
document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function(el) {
  setTimeout(function() {
    el.style.transition = 'opacity .4s';
    el.style.opacity = '0';
    setTimeout(function() { el.remove(); }, 400);
  }, 3500);
});

// ── Live search / filter table ─────────────────────────────
const searchInput = document.getElementById('tableSearch');
if (searchInput) {
  searchInput.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('tbody tr').forEach(function(row) {
      const text = row.textContent.toLowerCase();
      row.style.display = text.includes(q) ? '' : 'none';
    });
  });
}

// ── Branch filter ──────────────────────────────────────────
const branchFilter = document.getElementById('branchFilter');
if (branchFilter) {
  branchFilter.addEventListener('change', function() {
    const val = this.value.toLowerCase();
    document.querySelectorAll('tbody tr').forEach(function(row) {
      if (!val) { row.style.display = ''; return; }
      const branch = (row.dataset.branch || '').toLowerCase();
      row.style.display = branch.includes(val) ? '' : 'none';
    });
  });
}

// ── Populate edit modals ───────────────────────────────────
document.addEventListener('click', function(e) {
  const btn = e.target.closest('[data-edit]');
  if (!btn) return;
  const modalId = btn.dataset.modal;
  const data = JSON.parse(btn.dataset.edit || '{}');
  const modal = document.getElementById(modalId);
  if (!modal) return;
  Object.keys(data).forEach(function(key) {
    const el = modal.querySelector('[name="' + key + '"]');
    if (el) el.value = data[key];
  });
  openModal(modalId);
});


// ── Dark Mode Toggle Fix ───────────────────────────────────
function applyDarkModeState() {
  const isDark = localStorage.getItem('anais_theme') === 'dark';
  document.documentElement.classList.toggle('dark-mode', isDark);
  document.body.classList.toggle('dark-mode', isDark);

  const icon = document.getElementById('darkModeIcon');
  const text = document.getElementById('darkModeText');

  if (icon) icon.textContent = isDark ? '☀️' : '🌙';
  if (text) text.textContent = isDark ? 'Light Mode' : 'Dark Mode';
}

function toggleDarkMode() {
  const isDark = localStorage.getItem('anais_theme') === 'dark';
  localStorage.setItem('anais_theme', isDark ? 'light' : 'dark');
  applyDarkModeState();
}

window.toggleDarkMode = toggleDarkMode;
window.applyDarkModeState = applyDarkModeState;

document.addEventListener('DOMContentLoaded', applyDarkModeState);
applyDarkModeState();


// ── Upper-right Notification Box Restore ───────────────────
function showToast(type, msg) {
  type = type || 'info';

  let box = document.getElementById('toastBox');
  if (!box) {
    box = document.createElement('div');
    box.id = 'toastBox';
    box.className = 'toast-box';
    document.body.appendChild(box);
  }

  const item = document.createElement('div');
  item.className = 'toast-item toast-' + type;

  item.innerHTML = `
    <div class="toast-icon">${type === 'success' ? '✅' : type === 'danger' ? '⚠️' : type === 'warning' ? '⚠️' : 'ℹ️'}</div>
    <div class="toast-message">${msg}</div>
    <button type="button" class="toast-close" aria-label="Close">&times;</button>
  `;

  item.querySelector('.toast-close').addEventListener('click', function () {
    item.remove();
  });

  box.appendChild(item);

  // Stays visible for 20 seconds.
  setTimeout(function () {
    if (!item.isConnected) return;
    item.classList.add('toast-hide');
    setTimeout(function () {
      if (item.isConnected) item.remove();
    }, 280);
  }, 20000);
}

window.showToast = showToast;

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function (el) {
    let type = 'info';
    if (el.classList.contains('alert-success')) type = 'success';
    if (el.classList.contains('alert-danger')) type = 'danger';
    if (el.classList.contains('alert-warning')) type = 'warning';

    showToast(type, el.innerHTML);
    el.remove();
  });
});
