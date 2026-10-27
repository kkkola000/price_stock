/* Админка: показываем только поля, нужные выбранному типу источника,
   и запоминаем значения маппинга отдельно для таблиц и для YML. */
(function () {
  'use strict';

  var typeSelect = document.getElementById('js-type');
  var fetchSelect = document.getElementById('js-fetch');
  if (!typeSelect || !fetchSelect) { return; }

  var mapInputs = Array.prototype.slice.call(document.querySelectorAll('.js-map-input'));
  var previousMode = typeSelect.value === 'yml' ? 'yml' : 'table';

  /* Исходные значения кладём в «свою» ячейку памяти, вторую заполняем значением по умолчанию. */
  mapInputs.forEach(function (input) {
    input.dataset.valueTable = previousMode === 'table' ? input.value : '';
    input.dataset.valueYml = previousMode === 'yml' ? input.value : (input.getAttribute('data-default-yml') || '');
  });

  function matches(node, value) {
    return (node.getAttribute('data-type') || '').split(/\s+/).indexOf(value) !== -1;
  }

  function applyType() {
    var type = typeSelect.value;
    var mode = type === 'yml' ? 'yml' : 'table';

    document.querySelectorAll('.js-type-block, .js-type-field').forEach(function (node) {
      node.classList.toggle('is-hidden', !matches(node, type));
    });

    if (mode !== previousMode) {
      mapInputs.forEach(function (input) {
        /* Запоминаем то, что админ ввёл для прежнего типа, и подставляем значение для нового. */
        input.dataset[previousMode === 'yml' ? 'valueYml' : 'valueTable'] = input.value;
        input.value = mode === 'yml' ? input.dataset.valueYml : input.dataset.valueTable;
      });
      previousMode = mode;
    }

    mapInputs.forEach(function (input) {
      input.placeholder = mode === 'yml'
        ? (input.getAttribute('data-placeholder-yml') || '')
        : (input.getAttribute('data-placeholder-table') || '');
    });
  }

  function applyFetch() {
    var method = fetchSelect.value;
    document.querySelectorAll('.js-fetch-block').forEach(function (node) {
      node.classList.toggle('is-hidden', node.getAttribute('data-fetch') !== method);
    });
  }

  typeSelect.addEventListener('change', applyType);
  fetchSelect.addEventListener('change', applyFetch);
  applyType();
  applyFetch();
})();

/* Виртуальный склад хранит постоянный остаток; файл сохраняется при смене типа. */
(function () {
  var form = document.getElementById('warehouse-settings');
  if (!form) return;
  var kind = form.querySelector('[name="kind"]');
  function updateWarehouseKind() {
    var virtual = kind.value === 'virtual';
    var file = form.querySelector('.warehouse-file-settings');
    file.hidden = virtual;
    file.disabled = virtual;
    form.querySelector('[name="default_stock"]').closest('.field').hidden = !virtual;
    form.querySelectorAll('.warehouse-import-button').forEach(function (button) { button.hidden = virtual; });
    var remove = document.querySelector('.warehouse-file-delete');
    if (remove) remove.hidden = virtual;
  }
  kind.addEventListener('change', updateWarehouseKind);
  updateWarehouseKind();
})();

/* Native dialogs provide focus containment and Escape handling. */
(function () {
  document.querySelectorAll('[data-open-dialog]').forEach(function (button) {
    button.addEventListener('click', function () {
      document.getElementById(button.dataset.openDialog).showModal();
    });
  });
  document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
    button.addEventListener('click', function () { button.closest('dialog').close(); });
  });
  var picker = document.getElementById('match-picker');
  if (picker) {
    picker.addEventListener('close', function () {
      var url = new URL(window.location.href);
      ['match_id', 'q', 'field'].forEach(function (key) { url.searchParams.delete(key); });
      window.history.replaceState(null, '', url);
    });
    picker.showModal();
    var input = picker.querySelector('[name="q"]');
    if (input) input.focus();
  }
})();
