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

/* XML template editor: local insertion and highlighting; server validates before writing. */
(function () {
  var editor = document.getElementById('xml-template'), table = document.getElementById('template-variables');
  if (!editor || !table) return;
  var body = table.tBodies[0], serial = Date.now(), cursor = 0, end = 0;
  var choices = {
    file: {sku:'Артикул',name:'Название',price:'Цена',oldprice:'Старая цена',min_price:'Минимальная цена',stock:'Суммарный остаток',warehouse_name:'Название приоритетного склада',__column__:'Столбец файла (A, B, AA)'},
    catalog: {sku:'Артикул',name:'Название',price:'Цена',promo_price:'Промоцена',effective_price:'Промоцена, иначе обычная цена',price_text:'Цена как на витрине',promo_price_text:'Промоцена как на витрине',stock:'Остаток с соответствиями',total_stock:'Суммарный остаток',raw_stock:'Числовой остаток без соответствий',stock_text:'Текст остатка'},
    warehouse: {name:'Название'}
  };
  function field(row,name) { return row.querySelector('[name^="var_' + name + '["]'); }
  function refreshRow(row) {
    var type = field(row,'type').value, old = field(row,'ref'), value = old.value;
    var previous = row.dataset.sourceType;
    if (previous !== type) {
      row.sourceValues = row.sourceValues || {};
      if (previous) { row.sourceValues[previous] = value; value = row.sourceValues[type] === undefined ? (choices[type] ? Object.keys(choices[type])[0] : '') : row.sourceValues[type]; }
      var control;
      if (choices[type]) {
        control = document.createElement('select');
        Object.entries(choices[type]).forEach(function (entry) { control.add(new Option(entry[1],entry[0])); });
        if (!(value in choices[type])) control.add(new Option(value ? 'Сохранённое поле: ' + value : 'Выберите поле',value));
        control.value = value;
      } else { control = document.createElement('input'); control.value = value; control.placeholder = 'Постоянное значение'; }
      control.name = old.name; control.className = 'input'; control.setAttribute('aria-label','Поле или константа');
      old.replaceWith(control); row.dataset.sourceType = type;
    }
    field(row,'extra').hidden = !(type === 'file' && field(row,'ref').value === '__column__');
    field(row,'extra').placeholder = 'Буква столбца, например AA';
  }
  function refresh() {
    var picker = document.getElementById('template-insert-variable'), selected = picker.value;
    picker.replaceChildren(new Option('Выберите переменную',''));
    body.querySelectorAll('[data-template-variable]').forEach(function (row) {
      refreshRow(row); var name = field(row,'name').value.trim(); if (name) picker.add(new Option(name,name));
    });
    picker.add(new Option('Дата сборки (системная)','дата_фида')); picker.value = selected;
  }
  function escaped(text) { return text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function paint() {
    var highlighted = escaped(editor.value).replace(/(\{\{[^{}]*\}\}|\{%[\s\S]*?%\}|&lt;[^\n]*?&gt;)/g,function (token) {
      if (token.startsWith('&lt;')) return '<span class="template-tag">' + token.replace(/(\{\{[^{}]*\}\})/g,'<span class="template-variable">$1</span>') + '</span>';
      return '<span class="' + (token.startsWith('{{') ? 'template-variable' : 'template-loop') + '">' + token + '</span>';
    });
    editor.parentElement.querySelector('.template-highlight').innerHTML = highlighted + '\n';
    editor.parentElement.querySelector('.template-lines').textContent = editor.value.split('\n').map(function (_,i) { return i+1; }).join('\n');
  }
  function insert(text) { editor.focus(); editor.setSelectionRange(cursor,end); editor.setRangeText(text,cursor,end,'end'); cursor = end = editor.selectionStart; paint(); }
  ['keyup','click','select','blur'].forEach(function (event) { editor.addEventListener(event,function () { cursor=editor.selectionStart;end=editor.selectionEnd; }); });
  editor.addEventListener('input',function () { cursor=end=editor.selectionStart;paint(); });
  editor.addEventListener('scroll',function () { ['.template-highlight','.template-lines'].forEach(function (selector) { var pre=editor.parentElement.querySelector(selector);pre.scrollTop=editor.scrollTop;if(selector!=='.template-lines')pre.scrollLeft=editor.scrollLeft; }); });
  editor.addEventListener('keydown',function (event) { if(event.key==='Tab'){event.preventDefault();cursor=editor.selectionStart;end=editor.selectionEnd;insert('  ');} });
  document.getElementById('template-add-variable').addEventListener('click',function () {
    body.insertAdjacentHTML('beforeend',document.getElementById('template-variable-row').innerHTML.replaceAll('__INDEX__','v'+(++serial)));
    refresh();field(body.lastElementChild,'name').focus();
  });
  body.addEventListener('change',refresh);
  body.addEventListener('click',function (event) {
    var row=event.target.closest('[data-template-variable]');if(!row)return;
    if(event.target.closest('[data-template-remove]')) {row.remove();refresh();}
    else if(event.target.closest('[data-template-insert]')) {var name=field(row,'name').value.trim();if(name)insert('{{'+name+'}}');else field(row,'name').focus();}
  });
  document.getElementById('template-insert').addEventListener('click',function () {var name=document.getElementById('template-insert-variable').value;if(name)insert('{{'+name+'}}');});
  document.querySelectorAll('[data-template-loop]').forEach(function (button) { button.addEventListener('click',function () {
    var loop=button.dataset.templateLoop==='warehouse'?'склада':'товара', selected=editor.value.slice(cursor,end);
    insert('{% для каждого '+loop+' %}\n'+selected+'\n{% конец %}');
  }); });
  var addStock=document.getElementById('template-add-stock');
  if(addStock)addStock.addEventListener('click',function () {
    var tbody=addStock.closest('.card').querySelector('tbody'), index='s'+(++serial), row=document.createElement('tr');
    row.innerHTML='<td><input class="input" name="stock_pattern['+index+']" maxlength="190" placeholder="Более 5"></td><td><input class="input" type="number" min="0" name="stock_qty['+index+']" value="0"></td><td><button class="btn btn--small btn--danger" type="button" data-remove-stock>×</button></td>';
    tbody.appendChild(row);row.querySelector('input').focus();
  });
  document.addEventListener('click',function(event){var button=event.target.closest('[data-remove-stock]');if(button)button.closest('tr').remove();});
  refresh();paint();
})();
