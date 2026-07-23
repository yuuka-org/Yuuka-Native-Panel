document.addEventListener('DOMContentLoaded', function () {
  // Dark mode toggle - the theme itself is already applied synchronously
  // by the inline script in partials/header.php's <head> (before first
  // paint, so there's never a flash of the wrong theme); this just wires
  // the button, updates its icon, and persists the choice for next time.
  var themeToggleBtn = document.getElementById('themeToggle');
  if (themeToggleBtn) {
    var updateThemeIcon = function () {
      var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
      themeToggleBtn.innerHTML = isDark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
    };
    updateThemeIcon();
    themeToggleBtn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', next);
      localStorage.setItem('yuuka-theme', next);
      updateThemeIcon();
    });
  }

  var toggle = document.getElementById('sidebarToggle');
  var sidebar = document.getElementById('appSidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('show');
    });
  }

  // Desktop-only sidebar minisize toggle (icon-only). The collapsed state
  // itself is already applied to <html> synchronously by the inline
  // script in partials/header.php's <head> (avoids a flash of the wide
  // sidebar on load); this just wires the button and persists the choice.
  var collapseToggle = document.getElementById('sidebarCollapseToggle');
  if (collapseToggle) {
    collapseToggle.addEventListener('click', function () {
      var collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
      localStorage.setItem('yuuka-sidebar-collapsed', collapsed ? '1' : '0');
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

  // Generic show/hide toggle for a <input type=password> field (as
  // opposed to data-toggle-secret above, which toggles a masked <span> of
  // an already-known stored value).
  document.querySelectorAll('[data-toggle-password-input]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.getElementById(btn.getAttribute('data-toggle-password-input'));
      if (!target) return;
      var show = target.type === 'password';
      target.type = show ? 'text' : 'password';
      btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    });
  });

  // Generic "generate a strong random password into this field" button.
  // Uses crypto.getRandomValues (not Math.random, which is not
  // cryptographically secure) and reveals the field (type=text) so the
  // admin can see/copy what was just generated before submitting.
  document.querySelectorAll('[data-generate-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.getElementById(btn.getAttribute('data-generate-password'));
      if (!target) return;
      target.type = 'text';
      target.value = generateStrongPassword();
      target.dispatchEvent(new Event('input', { bubbles: true }));
      var toggleBtn = document.querySelector('[data-toggle-password-input="' + target.id + '"]');
      if (toggleBtn) toggleBtn.innerHTML = '<i class="bi bi-eye-slash"></i>';
    });
  });

  function generateStrongPassword(length) {
    length = length || 20;
    var charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^&*-_+=';
    var values = new Uint32Array(length);
    (window.crypto || window.msCrypto).getRandomValues(values);
    var out = '';
    for (var i = 0; i < length; i++) {
      out += charset[values[i] % charset.length];
    }
    return out;
  }

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
