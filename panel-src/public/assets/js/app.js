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

  // Generic "copy to clipboard" button. navigator.clipboard only exists in
  // a secure context (HTTPS or localhost) - this panel's vhost itself only
  // ever listens on plain HTTP (TLS, if any, is terminated upstream, e.g.
  // Cloudflare - see currentScheme() in app/helpers/response.php), so
  // admins reaching it directly over http:// had navigator.clipboard as
  // undefined, throwing synchronously before .then()/.catch() ever ran -
  // the button just silently did nothing. Fall back to the older
  // execCommand('copy') technique (works in any context) when the async
  // Clipboard API isn't available.
  function copyToClipboard(value) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(value);
    }
    return new Promise(function (resolve, reject) {
      var textarea = document.createElement('textarea');
      textarea.value = value;
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.focus();
      textarea.select();
      try {
        document.execCommand('copy') ? resolve() : reject();
      } catch (err) {
        reject(err);
      } finally {
        document.body.removeChild(textarea);
      }
    });
  }

  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var value = btn.getAttribute('data-copy');
      if (!value) return;
      copyToClipboard(value).then(function () {
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

  // ---------------------------------------------------------------------
  // Shared real-time Node.js/PM2 stats renderer - used by both nodejs.php
  // (the full Managed-by-Panel table) and dashboard.php's smaller
  // Node.js widget. Both wire it up themselves via a
  // data-refresh-url="/ajax_pm2" element + a panel:refresh listener (see
  // app.js's existing data-refresh-url polling below) - this function
  // only does the DOM patching, so it works against either table's own
  // subset of columns (a row simply has no data-stat="x" element for any
  // column that page doesn't show, which this silently skips).
  // ---------------------------------------------------------------------
  window.PanelNodeStats = {
    apply: function (container, managedItems) {
      var byId = {};
      (managedItems || []).forEach(function (item) {
        byId[String(item.meta.id)] = item;
      });
      container.querySelectorAll('[data-app-row]').forEach(function (row) {
        var item = byId[row.getAttribute('data-app-row')];
        if (!item) return;
        var rt = item.runtime;
        setStat(row, 'status', function (el) {
          el.innerHTML = '<span class="status-dot ' + item.status + '"></span>' + item.status;
        });
        setStat(row, 'cpu', function (el) { el.textContent = rt ? rt.cpu_percent + '%' : '-'; });
        setStat(row, 'ram', function (el) { el.textContent = rt ? (Math.round(rt.memory_bytes / 1048576 * 10) / 10) + ' MB' : '-'; });
        setStat(row, 'uptime', function (el) { el.textContent = (rt && rt.uptime_ms) ? formatUptime(rt.uptime_ms) : '-'; });
        setStat(row, 'restarts', function (el) { el.textContent = rt ? rt.restart_count : '-'; });
      });
      function setStat(row, name, fn) {
        var el = row.querySelector('[data-stat="' + name + '"]');
        if (el) fn(el);
      }
      function formatUptime(ms) {
        var s = Math.floor(ms / 1000);
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        return pad(Math.floor(s / 3600)) + ':' + pad(Math.floor((s % 3600) / 60)) + ':' + pad(s % 60);
      }
    }
  };

  // ---------------------------------------------------------------------
  // Global "it's working" feedback - this panel is a classic multi-page
  // app (real <form> POSTs and <a> navigations, not a SPA), so between a
  // click and the next page finishing load there was previously NO visual
  // feedback at all - a slow operation (deploy, backup, install) just left
  // the old page looking frozen, making users click again thinking nothing
  // registered. window.PanelLoading is also exposed for pages doing their
  // own fetch()-based actions (File Manager, PM2 buttons, etc.) that don't
  // cause a real navigation, so they can opt into the same bar manually.
  // ---------------------------------------------------------------------
  var loadingBar = document.getElementById('panelLoadingBar');
  var loadingBarTimer = null;
  var loadingBarWidth = 0;
  var loadingSafetyTimer = null;

  function loadingStart() {
    if (!loadingBar) return;
    window.clearTimeout(loadingSafetyTimer);
    window.clearInterval(loadingBarTimer);
    loadingBarWidth = 15;
    loadingBar.style.width = loadingBarWidth + '%';
    loadingBar.classList.add('is-active');
    loadingBarTimer = window.setInterval(function () {
      // Creep towards 90% but never reach it on its own - real completion
      // (loadingDone) or the safety timeout snaps it the rest of the way.
      loadingBarWidth += (90 - loadingBarWidth) * 0.1;
      loadingBar.style.width = loadingBarWidth + '%';
    }, 300);
    // Escape valve: a fetch()-based caller that forgets to call done(), or
    // a blocked navigation (validation error with no redirect), would
    // otherwise leave the bar stuck forever.
    loadingSafetyTimer = window.setTimeout(loadingDone, 20000);
  }

  function loadingDone() {
    if (!loadingBar) return;
    window.clearInterval(loadingBarTimer);
    window.clearTimeout(loadingSafetyTimer);
    loadingBar.style.width = '100%';
    window.setTimeout(function () {
      loadingBar.classList.remove('is-active');
      window.setTimeout(function () {
        loadingBar.style.width = '0%';
      }, 200);
    }, 150);
  }

  window.PanelLoading = { start: loadingStart, done: loadingDone };

  function isPlainLeftClick(e) {
    return e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey;
  }

  // Real top-level navigations (<a> clicks) - skip anything that won't
  // actually replace this page (external/new-tab/download/#anchor/js: link)
  // so the bar doesn't get stuck showing for a click that never navigates.
  document.addEventListener('click', function (e) {
    var link = e.target.closest('a[href]');
    if (!link || !isPlainLeftClick(e)) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;
    var href = link.getAttribute('href') || '';
    if (href === '' || href.charAt(0) === '#' || href.indexOf('javascript:') === 0 || href.indexOf('mailto:') === 0) return;
    if (link.origin && link.origin !== window.location.origin) return;
    var btn = link.classList.contains('btn') ? link : null;
    if (btn && !btn.classList.contains('is-loading')) btn.classList.add('is-loading');
    loadingStart();
  });

  // Form submits - runs after the data-confirm handler above (registered
  // earlier in this same DOMContentLoaded block, so it already had a
  // chance to call preventDefault() on cancel) - if the event was
  // cancelled, e.defaultPrevented is already true here and nothing shows.
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    var submitter = e.submitter;
    if (submitter && submitter.tagName === 'BUTTON') {
      submitter.classList.add('is-loading');
      submitter.disabled = true;
    } else {
      form.querySelectorAll('button[type="submit"]:not([type="button"])').forEach(function (b) {
        b.classList.add('is-loading');
        b.disabled = true;
      });
    }
    loadingStart();
  });

  // Back/forward via bfcache restores the OLD page (with buttons still
  // mid-loading-state from before navigation) without re-running
  // DOMContentLoaded - reset everything visible so it doesn't look stuck.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    loadingDone();
    document.querySelectorAll('.btn.is-loading').forEach(function (b) {
      b.classList.remove('is-loading');
      b.disabled = false;
    });
  });
});
