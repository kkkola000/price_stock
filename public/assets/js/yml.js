/* Compact YML workspace. Help remains available by mouse, keyboard and touch. */
(function () {
  'use strict';
  document.querySelectorAll('[data-submit-change]').forEach(function (input) {
    input.addEventListener('change', function () { input.form.requestSubmit(); });
  });
  document.querySelectorAll('[data-copy-text]').forEach(function (button) {
    button.addEventListener('click', async function () {
      try { await navigator.clipboard.writeText(button.getAttribute('data-copy-text')); button.textContent = 'Скопировано'; }
      catch (_) { window.prompt('Скопируйте ссылку', button.getAttribute('data-copy-text')); }
    });
  });
  var stockSettings = document.getElementById('yml-stock-settings');
  if (stockSettings) { stockSettings.addEventListener('toggle', function () {
    document.querySelector('[name=settings_panel]').value = stockSettings.open ? 'stock' : 'structure';
  }); }
  // Move existing descriptions, never inputs or operational warnings, into accessible help.
  document.querySelectorAll('main p.card__hint, main p.field__hint, main span.field__hint').forEach(function (hint) {
    if (hint.closest('dialog') || hint.querySelector('input,select,button,form') || !hint.textContent.trim()) { return; }
    var help = document.createElement('details'); help.className = 'yml-help';
    var summary = document.createElement('summary'); summary.textContent = '?'; summary.setAttribute('aria-label', 'Показать пояснение');
    var panel = document.createElement('div'); panel.className = 'yml-help__panel';
    while (hint.firstChild) { panel.appendChild(hint.firstChild); }
    help.append(summary, panel);
    help.addEventListener('toggle', function () {
      if (!help.open) { return; }
      var rect = summary.getBoundingClientRect();
      panel.style.position = 'fixed';
      panel.style.left = Math.max(12, Math.min(rect.left, window.innerWidth - 350)) + 'px';
      panel.style.top = Math.min(rect.bottom + 7, window.innerHeight - Math.min(250, panel.scrollHeight) - 12) + 'px';
      panel.style.bottom = 'auto'; panel.style.right = 'auto';
    });
    var label = hint.closest('label');
    if (label) { label.appendChild(help); hint.remove(); }
    else {
      var previous = hint.previousElementSibling;
      var card = hint.closest('.card, .yml-advanced-section');
      if (card) { previous = card.querySelector('h2,h3') || previous; }
      if (previous && /^(H2|H3|LEGEND)$/.test(previous.tagName)) {
        var existing = previous.querySelector('.yml-help__panel');
        if (existing) { existing.appendChild(document.createElement('hr')); while (panel.firstChild) { existing.appendChild(panel.firstChild); } }
        else { previous.appendChild(help); }
        hint.remove();
      }
      else { hint.replaceWith(help); }
    }
  });
  document.addEventListener('click', function (event) {
    document.querySelectorAll('.yml-help[open], .yml-actions[open]').forEach(function (node) { if (!node.contains(event.target)) { node.open = false; } });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { document.querySelectorAll('.yml-help[open], .yml-actions[open]').forEach(function (node) { node.open = false; }); }
  });
})();
