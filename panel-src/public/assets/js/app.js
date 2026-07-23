document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('sidebarToggle');
  var sidebar = document.getElementById('appSidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('show');
    });
  }

  // Generic show/hide toggle for masked secret values (env vars, etc.)
  document.querySelectorAll('[data-toggle-secret]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.getElementById(btn.getAttribute('data-toggle-secret'));
      if (!target) return;
      var isHidden = target.getAttribute('data-hidden') === '1';
      if (isHidden) {
        target.textContent = target.getAttribute('data-value');
        target.setAttribute('data-hidden', '0');
        btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
      } else {
        target.textContent = '••••••••';
        target.setAttribute('data-hidden', '1');
        btn.innerHTML = '<i class="bi bi-eye"></i>';
      }
    });
  });

  // Generic "copy to clipboard" button
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var value = btn.getAttribute('data-copy');
      if (!value) return;
      navigator.clipboard.writeText(value).then(function () {
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        setTimeout(function () { btn.innerHTML = original; }, 1200);
      }).catch(function () {});
    });
  });

  // Confirm dialogs for destructive actions
  document.querySelectorAll('[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!confirm(form.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  });

  // Auto-refresh live stats blocks (dashboard, nodejs list) via data-refresh-url
  document.querySelectorAll('[data-refresh-url]').forEach(function (el) {
    var url = el.getAttribute('data-refresh-url');
    var intervalMs = parseInt(el.getAttribute('data-refresh-interval') || '5000', 10);
    setInterval(function () {
      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          el.dispatchEvent(new CustomEvent('panel:refresh', { detail: data }));
        })
        .catch(function () {});
    }, intervalMs);
  });
});
