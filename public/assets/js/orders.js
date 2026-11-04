/* Страница «Заказы»: списки по поставщикам, скачивание и очистка. */
(function () {
  'use strict';

  var store = window.OrderStore;
  if (!store) { return; }

  var list = document.getElementById('js-orders-list');
  var totalLabel = document.getElementById('js-orders-total');
  var clearAllBtn = document.getElementById('js-clear-all');
  var hint = document.getElementById('js-orders-hint');
  var access = document.getElementById('js-purchase-access');
  var showPriceBtn = document.getElementById('js-purchase-show');
  var hidePriceBtn = document.getElementById('js-purchase-hide');
  var codeForm = document.getElementById('js-purchase-form');
  var codeInput = document.getElementById('purchase-code');
  var message = document.getElementById('js-purchase-message');
  var unlocked = false, prices = {}, purchaseDetails = {}, priceRequest = 0;
  var purchaseTotal = document.getElementById('js-orders-purchase-total');

  function orderItems(items) {
    return items.map(function (item) { return {id: item.id, name: item.name, qty: store.qtyOf(item)}; });
  }
  function request(action, fields) {
    var body = new URLSearchParams();
    body.set('action', action); body.set('csrf_token', access.getAttribute('data-csrf'));
    Object.keys(fields || {}).forEach(function (key) { body.set(key, fields[key]); });
    return fetch('api/order.php', {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body});
  }
  async function api(action, fields) {
    var response = await request(action, fields);
    var data = await response.json();
    if (!response.ok || !data.ok) {
      var error = new Error(data.error || 'Не удалось выполнить запрос.');
      error.status = response.status; throw error;
    }
    return data;
  }
  function setAccess(value) {
    unlocked = value; prices = {}; purchaseDetails = {}; priceRequest++;
    showPriceBtn.classList.toggle('is-hidden', unlocked);
    hidePriceBtn.classList.toggle('is-hidden', !unlocked);
    codeForm.classList.add('is-hidden'); codeInput.value = '';
    render();
  }
  async function refreshPrices() {
    if (!unlocked) { return; }
    var serial = ++priceRequest;
    try {
      var data = await api('prices', {items: JSON.stringify(orderItems(store.read()))});
      if (serial !== priceRequest || !unlocked) { return; }
      prices = data.prices; purchaseDetails = data.details; render();
    } catch (error) {
      if (serial !== priceRequest) { return; }
      setAccess(false); message.textContent = error.message;
    }
  }
  showPriceBtn.addEventListener('click', function () {
    codeForm.classList.remove('is-hidden'); codeInput.focus(); message.textContent = '';
  });
  purchaseTotal.addEventListener('click', function (event) {
    if (!event.target.closest('.js-total-unlock')) { return; }
    codeForm.classList.remove('is-hidden');
    codeForm.scrollIntoView({behavior: 'smooth', block: 'center'});
    codeInput.focus(); message.textContent = '';
  });
  codeForm.addEventListener('submit', async function (event) {
    event.preventDefault(); var button = codeForm.querySelector('button'); button.disabled = true;
    try {
      await api('unlock', {code: codeInput.value});
      setAccess(true); message.textContent = ''; await refreshPrices();
    } catch (error) { message.textContent = error.message; }
    finally { codeInput.value = ''; button.disabled = false; }
  });
  hidePriceBtn.addEventListener('click', async function () {
    // Hide prices after the server revokes access; export verifies the same session.
    hidePriceBtn.disabled = true;
    try { await api('hide'); setAccess(false); message.textContent = ''; }
    catch (error) { message.textContent = error.message; }
    finally { hidePriceBtn.disabled = false; }
  });
  // Another tab may hide prices or the administrator may change the code.
  window.addEventListener('focus', syncAccess);
  async function syncAccess() {
    try {
      var state = await api('status'); setAccess(state.unlocked);
      if (state.unlocked) { await refreshPrices(); }
    } catch (error) { setAccess(false); message.textContent = error.message; }
  }


  function money(cents, symbol) {
    var whole = String(Math.floor(cents / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return whole + (cents % 100 ? '.' + String(cents % 100).padStart(2, '0') : '') + ' ' + symbol;
  }
  function totalsHtml(items, grand) {
    if (!unlocked) { return ''; }
    var sums = Object.create(null), symbols = Object.create(null), missing = 0;
    items.forEach(function (item) {
      var detail = purchaseDetails[String(item.id)];
      if (!detail || detail.cents === null) { missing++; return; }
      sums[detail.currency] = (sums[detail.currency] || 0) + detail.cents * store.qtyOf(item);
      symbols[detail.currency] = detail.symbol;
    });
    var labels = Object.keys(sums).map(function (currency) { return money(sums[currency], symbols[currency]); });
    return '<strong>' + (grand ? 'Итого по всем позициям: ' : 'Итого по закупке: ') + escapeHtml(labels.join(' + ') || '—') + '</strong>'
      + (missing ? '<div class="order-item__purchase">Без закупочной цены: ' + missing + ' поз. Не включены в итог.</div>' : '');
  }
  function lineTotal(item) {
    var detail = purchaseDetails[String(item.id)];
    return detail && detail.cents !== null ? money(detail.cents * store.qtyOf(item), detail.symbol) : '—';
  }

  function positionsLabel(count) {
    var mod10 = count % 10;
    var mod100 = count % 100;
    var word = 'позиций';
    if (mod10 === 1 && mod100 !== 11) { word = 'позиция'; }
    else if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) { word = 'позиции'; }
    return count + ' ' + word;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /** Какие группы сейчас раскрыты — чтобы перерисовка их не схлопывала. */
  function openSupplierIds() {
    return Array.prototype.map.call(
      list.querySelectorAll('.order-group[open]'),
      function (node) { return node.getAttribute('data-supplier-id'); }
    );
  }

  function render() {
    var groups = store.bySupplier();
    var total = store.count();
    var opened = openSupplierIds();
    purchaseTotal.classList.toggle('is-hidden', total === 0);
    purchaseTotal.innerHTML = unlocked ? totalsHtml(store.read(), true)
      : '<strong>Итого по всем позициям</strong><p>Сумма по закупке скрыта. Введите код доступа.</p>'
        + '<button type="button" class="btn btn--ghost js-total-unlock">Показать сумму</button>';

    totalLabel.textContent = total === 0
      ? 'Список пуст'
      : positionsLabel(total) + ' · ' + store.totalQty() + ' шт.';
    clearAllBtn.classList.toggle('is-hidden', total === 0);
    hint.classList.toggle('is-hidden', total === 0);

    if (total === 0) {
      list.innerHTML = '<div class="empty">'
        + '<div class="empty__icon">📋</div>'
        + '<p class="empty__title">Список пуст</p>'
        + '<p class="empty__text">Добавляйте товары кнопкой «+» в каталоге — здесь они соберутся по поставщикам.</p>'
        + '<p><a class="btn btn--primary" href="index.php">Перейти в каталог</a></p>'
        + '</div>';
      return;
    }

    list.innerHTML = groups.map(function (group) {
      var rows = group.items.map(function (item) {
        var qty = store.qtyOf(item);
        var stock = String(item.stock || '');

        /* Название — отдельной строкой во всю ширину, под ним наличие и количество:
           так на телефоне ничего не уезжает вбок. */
        return '<div class="order-item">'
          + '<div class="order-item__name">' + escapeHtml(item.name) + '</div>'
          + (unlocked ? '<div class="order-item__purchase">Закупочная цена: ' + escapeHtml(prices[String(item.id)] || '—') + ' · Сумма: ' + escapeHtml(lineTotal(item)) + '</div>' : '')
          + '<div class="order-item__row">'
          + (stock !== '' ? '<span class="order-item__stock">' + escapeHtml(stock) + '</span>' : '<span></span>')
          + '<div class="qty">'
          + '<button type="button" class="qty__btn js-qty-minus" data-id="' + escapeHtml(item.id) + '"'
          + (qty <= 1 ? ' disabled' : '') + ' aria-label="Уменьшить количество">−</button>'
          + '<input class="qty__value js-qty-input" type="text" inputmode="numeric" value="' + qty + '"'
          + ' data-id="' + escapeHtml(item.id) + '" aria-label="Количество">'
          + '<button type="button" class="qty__btn js-qty-plus" data-id="' + escapeHtml(item.id) + '"'
          + ' aria-label="Увеличить количество">+</button>'
          + '</div>'
          + '<button type="button" class="order-row__remove js-remove" data-id="' + escapeHtml(item.id)
          + '" title="Убрать из списка" aria-label="Убрать из списка">×</button>'
          + '</div></div>';
      }).join('');

      /* Список свёрнут по умолчанию: <details> без атрибута open */
      return '<details class="order-group" data-supplier-id="' + escapeHtml(group.id) + '">'
        + '<summary class="order-group__summary">'
        + '<span class="order-group__name">' + escapeHtml(group.supplier) + '</span>'
        + '<span class="order-group__count">' + positionsLabel(group.items.length)
        + ' · ' + group.items.reduce(function (sum, item) { return sum + store.qtyOf(item); }, 0) + ' шт.</span>'
        + '</summary>'
        + '<div class="order-group__body">'
        + '<div class="order-items">' + rows + '</div>'
        + (unlocked ? '<div class="order-purchase-total">' + totalsHtml(group.items) + '</div>' : '')
        + '<div class="order-group__actions">'
        + '<button type="button" class="btn btn--primary js-download">Скачать</button>'
        + '<button type="button" class="btn btn--ghost js-clear">Очистить</button>'
        + '</div></div></details>';
    }).join('');

    opened.forEach(function (id) {
      var group = list.querySelector('.order-group[data-supplier-id="' + id + '"]');
      if (group) { group.open = true; }
    });
  }

  /* ---------- Скачивание ---------- */

  /* Имя файла делаем латиницей: часть браузеров молча отбрасывает кириллицу
     в атрибуте download и сохраняет файл как «download». Содержимое при этом
     остаётся на русском. */
  var TRANSLIT = {
    а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i',
    й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't',
    у: 'u', ф: 'f', х: 'h', ц: 'c', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y', ь: '',
    э: 'e', ю: 'yu', я: 'ya'
  };

  function latin(value) {
    var source = String(value || '');
    var result = '';
    for (var i = 0; i < source.length; i++) {
      var char = source[i];
      var lower = char.toLowerCase();
      if (Object.prototype.hasOwnProperty.call(TRANSLIT, lower)) {
        var replacement = TRANSLIT[lower];
        result += char === lower ? replacement
          : replacement.charAt(0).toUpperCase() + replacement.slice(1);
      } else {
        result += char;
      }
    }

    return result.replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '');
  }

  async function download(group) {
    var blob;
    try {
      var response = await request('export', {items: JSON.stringify(orderItems(group.items))});
      if (!response.ok) { var error = await response.json(); throw new Error(error.error || 'Не удалось скачать таблицу.'); }
      blob = await response.blob();
    } catch (error) { message.textContent = error.message; return; }
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    var date = new Date().toISOString().slice(0, 10);
    var name = latin(group.supplier) || ('supplier-' + group.id);

    link.href = url;
    link.download = 'zakaz_' + name + '_' + date + '.csv';
    link.rel = 'noopener';
    document.body.appendChild(link);
    link.click();

    /* Ссылку и объект убираем не сразу: браузер читает имя файла уже после клика */
    window.setTimeout(function () {
      if (link.parentNode) { link.parentNode.removeChild(link); }
      URL.revokeObjectURL(url);
    }, 1500);
  }

  function groupById(id) {
    return store.bySupplier().filter(function (group) { return String(group.id) === String(id); })[0];
  }

  /* ---------- События ---------- */

  list.addEventListener('click', function (event) {
    var details = event.target.closest('.order-group');
    if (!details) { return; }
    var supplierId = details.getAttribute('data-supplier-id');

    if (event.target.closest('.js-download')) {
      var group = groupById(supplierId);
      if (group) { download(group); }
      return;
    }

    if (event.target.closest('.js-clear')) {
      var current = groupById(supplierId);
      var name = current ? current.supplier : 'этого поставщика';
      if (window.confirm('Очистить список по поставщику «' + name + '»?')) {
        store.clearSupplier(supplierId);
      }
      return;
    }

    var minus = event.target.closest('.js-qty-minus');
    if (minus) {
      store.changeQty(minus.getAttribute('data-id'), -1);
      return;
    }

    var plus = event.target.closest('.js-qty-plus');
    if (plus) {
      store.changeQty(plus.getAttribute('data-id'), 1);
      return;
    }

    var remove = event.target.closest('.js-remove');
    if (remove) {
      store.remove(remove.getAttribute('data-id'));
    }
  });

  /* Количество можно ввести руками */
  list.addEventListener('change', function (event) {
    var input = event.target.closest('.js-qty-input');
    if (input) {
      store.setQty(input.getAttribute('data-id'), input.value);
    }
  });

  clearAllBtn.addEventListener('click', function () {
    if (window.confirm('Очистить списки по всем поставщикам?')) {
      store.clearAll();
    }
  });

  store.onChange(function () { render(); refreshPrices(); });
  render();
  syncAccess();
})();
