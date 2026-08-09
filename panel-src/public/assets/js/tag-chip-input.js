/**
 * Turns a plain comma-separated text input into a live chip/pill preview
 * below it, without changing what actually gets submitted - the input
 * itself always stays the source of truth (still just "a,b,c"), this
 * only adds a nicer visual on top and a way to remove one value by
 * clicking its chip instead of hand-editing the raw text.
 *
 * Usage: <input class="tag-chip-source"> immediately followed by
 * <div class="tag-chip-preview"></div> as a sibling inside the same
 * parent element.
 */
(function () {
  function refreshPreview(input) {
    var preview = input.parentElement.querySelector('.tag-chip-preview');
    if (!preview) {
      return;
    }
    preview.innerHTML = '';
    var parts = input.value.split(',')
      .map(function (s) { return s.trim(); })
      .filter(function (s) { return s !== ''; });

    parts.forEach(function (part, idx) {
      var chip = document.createElement('span');
      chip.className = 'badge text-bg-light border me-1 mb-1 d-inline-flex align-items-center';
      chip.style.gap = '4px';

      var text = document.createElement('span');
      text.textContent = part;
      chip.appendChild(text);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn-close';
      btn.style.fontSize = '0.55rem';
      btn.setAttribute('aria-label', 'Hapus ' + part);
      btn.addEventListener('click', function () {
        parts.splice(idx, 1);
        input.value = parts.join(',');
        refreshPreview(input);
      });
      chip.appendChild(btn);

      preview.appendChild(chip);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tag-chip-source').forEach(function (input) {
      refreshPreview(input);
      input.addEventListener('input', function () { refreshPreview(input); });
    });
  });
})();
