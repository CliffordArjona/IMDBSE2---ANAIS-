// Robust AJAX form handler for ANAIS PHP/PDO endpoints.
document.addEventListener('submit', async function(e) {
  const form = e.target.closest('form.ajax-form');
  if (!form) return;

  e.preventDefault();

  const btn = form.querySelector('[type="submit"]');
  if (btn) btn.disabled = true;

  const showMessage = (type, msg) => {
    if (typeof window.showToast === 'function') {
      window.showToast(type, msg);
    } else {
      alert(msg);
    }
  };

  try {
    const res = await fetch(form.getAttribute('action'), {
      method: (form.getAttribute('method') || 'POST').toUpperCase(),
      body: new FormData(form),
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      credentials: 'same-origin'
    });

    const raw = await res.text();
    let data;

    try {
      data = JSON.parse(raw);
    } catch (jsonErr) {
      console.error('Non-JSON response from AJAX endpoint:', raw);
      throw new Error(raw ? raw.substring(0, 250) : 'Empty server response.');
    }

    const ok = data.ok === true || data.success === true;
    showMessage(ok ? 'success' : 'danger', data.message || (ok ? 'Done.' : 'Request failed.'));

    if (ok && form.dataset.reload === '1') {
      setTimeout(() => location.reload(), 800);
    }
  } catch (err) {
    console.error(err);
    const details = err && err.message ? err.message : 'Please check the API file path and database columns.';
    showMessage('danger', 'AJAX request failed: ' + details);
  } finally {
    if (btn) btn.disabled = false;
  }
});
