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

/* Visual XML tree; form names and persisted mapping values remain unchanged. */
(function () {
  var table = document.getElementById('xml-tree');
  if (!table) return;
  var body = table.tBodies[0], serial = Date.now(), collapsed = new Set();
  var roots = {_catalog: null, _shop: '_catalog', _offers: '_shop', offer: '_offers'};
  var choices = {
    field: {sku:'Артикул',name:'Название',price:'Цена',oldprice:'Старая цена',min_price:'Минимальная цена',stock:'Суммарный остаток'},
    catalog: {sku:'Артикул',name:'Название',price:'Цена',promo_price:'Промоцена',effective_price:'Промоцена, иначе обычная цена',price_text:'Цена как на витрине',promo_price_text:'Промоцена как на витрине',stock:'Числовой остаток',stock_text:'Текст остатка'},
    resolved: {stock:'Остаток с учётом соответствий',total_stock:'Суммарный остаток'},
    warehouse: {name:'Название склада'}
  };
  function rows() { return Array.from(body.querySelectorAll('[data-xml-node]')); }
  function field(row, name) { return row.querySelector('[name^="' + name + '["]'); }
  function key(row) { return row.dataset.xmlWrapper || field(row,'map_key').value; }
  function parent(row) { return row.hasAttribute('data-xml-wrapper') ? roots[key(row)] : field(row,'map_parent').value; }
  function refreshField(row) {
    var type = field(row,'map_type').value, old = field(row,'map_ref'), value = old.value;
    var previous = row.dataset.sourceType;
    if (previous === type) return;
    row.xmlValues = row.xmlValues || {};
    if (previous && previous !== type) {
      row.xmlValues[previous] = value;
      value = row.xmlValues[type] === undefined ? (choices[type] ? Object.keys(choices[type])[0] : '') : row.xmlValues[type];
    }
    row.dataset.sourceType = type;
    var control;
    if (choices[type]) {
      control = document.createElement('select');
      Object.entries(choices[type]).forEach(function (entry) { control.add(new Option(entry[1],entry[0])); });
      if (!(value in choices[type])) control.add(new Option(value ? 'Сохранённое поле: ' + value : 'Выберите поле',value));
      control.value = value;
    } else {
      control = document.createElement('input'); control.value = value;
      control.placeholder = type === 'column' ? 'Буква столбца, например A' : 'Постоянное значение';
    }
    control.name = old.name; control.className = 'input'; control.setAttribute('aria-label','Поле или значение');
    old.replaceWith(control);
  }
  function refresh() {
    var list = rows();
    list.forEach(function (row) {
      var own = key(row), select = field(row,'map_parent'), selected = select.value;
      select.replaceChildren(new Option('Элемент товара (offer)','offer'),new Option('Корневой элемент','_catalog'),new Option('Магазин (shop)','_shop'),new Option('Список товаров (offers)','_offers'));
      list.forEach(function (candidate) {
        if (key(candidate) !== own && field(candidate,'map_kind').value === 'block') select.add(new Option(field(candidate,'map_name').value,key(candidate)));
      });
      select.value = selected;
      if (!select.value) { select.add(new Option('Родитель недоступен: ' + selected,selected)); select.value = selected; }
      var block = field(row,'map_kind').value === 'block';
      field(row,'map_repeat').hidden = !block;
      if (!block) field(row,'map_repeat').value = 'once';
      refreshField(row);
      field(row,'map_type').hidden = block; field(row,'map_ref').hidden = block;
      row.querySelector('[data-xml-collapse]').hidden = !block;
      row.querySelector('[data-xml-label]').textContent = (field(row,'map_kind').value === 'attribute' ? '@' : '') + field(row,'map_name').value;
      row.querySelector('.xml-repeat-label').textContent = block && field(row,'map_repeat').value === 'warehouse' ? 'Для каждого склада' : '';
    });
    body.querySelectorAll('[data-xml-wrapper]').forEach(function (row) { row.querySelector('[data-xml-label]').textContent = row.querySelector('input').value; });
    var all = Array.from(body.children), byKey = new Map(all.map(function (row) { return [key(row),row]; })), used = new Set(), ordered = [];
    function append(row, depth, hidden) {
      if (used.has(key(row))) return;
      used.add(key(row)); ordered.push(row); row.hidden = hidden;
      row.style.setProperty('--xml-depth',Math.min(depth,16));
      var toggle = row.querySelector('[data-xml-collapse]');
      toggle.textContent = collapsed.has(key(row)) ? '›' : '⌄'; toggle.setAttribute('aria-expanded',String(!collapsed.has(key(row))));
      all.filter(function (child) { return parent(child) === key(row); }).sort(function (a,b) { return Number(a.hasAttribute('data-xml-wrapper')) - Number(b.hasAttribute('data-xml-wrapper')); }).forEach(function (child) { append(child,depth+1,hidden || collapsed.has(key(row))); });
    }
    append(byKey.get('_catalog'),0,false);
    all.forEach(function (row) { if (!used.has(key(row))) append(row,0,false); });
    ordered.forEach(function (row,index) { if (body.children[index] !== row) body.insertBefore(row,body.children[index] || null); });
  }
  document.querySelectorAll('[data-xml-add]').forEach(function (button) {
    button.addEventListener('click',function () {
      var index = 'n' + (++serial);
      var html = document.getElementById('xml-node-template').innerHTML.replaceAll('__INDEX__',index).replaceAll('__KEY__',index);
      body.insertAdjacentHTML('beforeend',html);
      var row = body.lastElementChild;
      field(row,'map_kind').value = button.dataset.xmlAdd;
      field(row,'map_name').value = button.dataset.xmlAdd === 'attribute' ? 'new_attribute' : button.dataset.xmlAdd === 'block' ? 'new_block' : 'new_tag';
      refresh(); field(row,'map_name').focus();
    });
  });
  body.addEventListener('change',refresh);
  body.addEventListener('click',function (event) {
    var button = event.target.closest('button'); if (!button) return;
    var row = button.closest('tr');
    if (button.hasAttribute('data-xml-collapse')) {
      if (collapsed.has(key(row))) collapsed.delete(key(row)); else collapsed.add(key(row)); refresh(); return;
    }
    if (!row.hasAttribute('data-xml-node')) return;
    if (button.hasAttribute('data-xml-remove')) {
      var keys = new Set([key(row)]), changed = true;
      while (changed) {
        changed = false;
        rows().forEach(function (candidate) { if (keys.has(parent(candidate)) && !keys.has(key(candidate))) { keys.add(key(candidate)); changed = true; } });
      }
      if (keys.size > 1 && !window.confirm('Удалить блок вместе с вложенными элементами?')) return;
      rows().forEach(function (candidate) { if (keys.has(key(candidate))) candidate.remove(); });
    } else if (button.dataset.xmlMove) {
      var siblings = rows().filter(function (candidate) { return parent(candidate) === parent(row); });
      var index = siblings.indexOf(row), other = siblings[index + (button.dataset.xmlMove === 'up' ? -1 : 1)];
      if (other) {
        if (button.dataset.xmlMove === 'up') body.insertBefore(row,other);
        else body.insertBefore(other,row);
      }
    }
    refresh();
  });
  refresh();
})();
