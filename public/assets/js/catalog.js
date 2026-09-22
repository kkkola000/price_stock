/* Публичный каталог: поиск с задержкой, фильтры, подгрузка по скроллу. */
(function () {
  'use strict';

  var state = window.CATALOG_STATE || { page: 1, pages: 1, hasMore: false, api: 'api/products.php' };
  var defaultSort = state.defaultSort || 'price_desc';

  var form         = document.getElementById('js-filter-form');
  var searchInput  = document.getElementById('js-search');
  var searchClear  = document.getElementById('js-search-clear');
  var sortSelect   = document.getElementById('js-sort');
  var grid         = document.getElementById('js-grid');
  var moreBtn      = document.getElementById('js-more');
  var spinner      = document.getElementById('js-spinner');
  var sentinel     = document.getElementById('js-sentinel');
  var totalTop     = document.getElementById('js-total');
  var totalInline  = document.getElementById('js-total-inline');
  var resetInline  = document.getElementById('js-reset-inline');
  var filtersPanel = document.getElementById('js-filters');
  var filtersToggle= document.getElementById('js-filters-toggle');
  var filtersClose = document.getElementById('js-filters-close');
  var filtersApply = document.getElementById('js-filters-apply');
  var filtersReset = document.getElementById('js-filters-reset');
  var filtersDot   = document.getElementById('js-filters-dot');
  var backdrop     = document.getElementById('js-backdrop');
  var topbar       = document.querySelector('.topbar');

  var controller = null;
  var loading = false;
  var debounceTimer = null;

  /* ---------- Сбор параметров ---------- */

  function checkedValues(name) {
    var nodes = filtersPanel.querySelectorAll('input[name="' + name + '"]:checked');
    return Array.prototype.map.call(nodes, function (node) { return node.value; });
  }

  function currentParams(page) {
    var params = new URLSearchParams();
    var q = (searchInput.value || '').trim();
    if (q) { params.set('q', q); }
    checkedValues('supplier[]').forEach(function (v) { params.append('supplier[]', v); });
    checkedValues('availability[]').forEach(function (v) { params.append('availability[]', v); });
    if (sortSelect && sortSelect.value && sortSelect.value !== defaultSort) { params.set('sort', sortSelect.value); }
    if (page > 1) { params.set('page', String(page)); }
    return params;
  }

  function isFiltered() {
    return (searchInput.value || '').trim() !== ''
      || checkedValues('supplier[]').length > 0
      || checkedValues('availability[]').length > 0
      || (sortSelect && sortSelect.value !== defaultSort);
  }

  function syncUrl() {
    var params = currentParams(1);
    var query = params.toString();
    var url = window.location.pathname + (query ? '?' + query : '');
    window.history.replaceState(null, '', url);
  }

  function syncIndicators() {
    var filtered = isFiltered();
    toggle(filtersDot, !filtered);
    toggle(resetInline, !filtered);
    if (filtersToggle) { filtersToggle.classList.toggle('is-active', filtered); }
    toggle(searchClear, (searchInput.value || '') === '');
  }

  function toggle(node, hidden) {
    if (node) { node.classList.toggle('is-hidden', !!hidden); }
  }

  /* ---------- Загрузка данных ---------- */

  function load(page, append) {
    if (controller) { controller.abort(); }
    controller = ('AbortController' in window) ? new AbortController() : null;

    loading = true;
    grid.setAttribute('aria-busy', 'true');
    toggle(spinner, false);
    toggle(moreBtn, true);

    var params = currentParams(page);
    params.set('format', 'html');

    fetch(state.api + '?' + params.toString(), {
      headers: { 'X-Requested-With': 'fetch' },
      signal: controller ? controller.signal : undefined
    })
      .then(function (response) {
        if (!response.ok) { throw new Error('HTTP ' + response.status); }
        return response.json();
      })
      .then(function (data) {
        if (!data.ok) { throw new Error(data.error || 'error'); }

        if (append) {
          grid.insertAdjacentHTML('beforeend', data.html || '');
          syncOrderButtons();
        } else {
          grid.innerHTML = data.html || '';
          /* Прокручиваем к началу списка, только когда шторка фильтров закрыта:
             иначе страница «уезжает» под открытой шторкой. */
          if (!filtersPanel.classList.contains('is-open')) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
          }
          syncOrderButtons();
        }

        state.page = data.page;
        state.pages = data.pages;
        state.hasMore = data.has_more;

        if (totalTop) { totalTop.textContent = data.label; }
        if (totalInline) { totalInline.textContent = data.label; }
        toggle(moreBtn, !data.has_more);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        if (!append) {
          grid.innerHTML = '<div class="empty"><div class="empty__icon">⚠️</div>'
            + '<p class="empty__title">Не удалось загрузить товары</p>'
            + '<p class="empty__text">Проверьте соединение и попробуйте ещё раз.</p></div>';
        }
        toggle(moreBtn, !state.hasMore);
      })
      .then(function () {
        loading = false;
        grid.setAttribute('aria-busy', 'false');
        toggle(spinner, true);
      });
  }

  function applyFilters() {
    syncUrl();
    syncIndicators();
    load(1, false);
  }

  function loadMore() {
    if (loading || !state.hasMore) { return; }
    load(state.page + 1, true);
  }

  /* ---------- Шторка фильтров (мобильные) ---------- */

  function openFilters() {
    filtersPanel.classList.add('is-open');
    if (backdrop) {
      backdrop.hidden = false;
      requestAnimationFrame(function () { backdrop.classList.add('is-visible'); });
    }
    document.body.style.overflow = 'hidden';
    if (filtersToggle) { filtersToggle.setAttribute('aria-expanded', 'true'); }
  }

  function closeFilters() {
    filtersPanel.classList.remove('is-open');
    if (backdrop) {
      backdrop.classList.remove('is-visible');
      window.setTimeout(function () { backdrop.hidden = true; }, 250);
    }
    document.body.style.overflow = '';
    if (filtersToggle) { filtersToggle.setAttribute('aria-expanded', 'false'); }
  }

  function resetAll() {
    searchInput.value = '';
    filtersPanel.querySelectorAll('input[type="checkbox"]').forEach(function (node) { node.checked = false; });
    if (sortSelect) { sortSelect.value = defaultSort; }
    applyFilters();
  }

  /* ---------- События ---------- */

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    window.clearTimeout(debounceTimer);
    searchInput.blur();
    applyFilters();
  });

  searchInput.addEventListener('input', function () {
    toggle(searchClear, (searchInput.value || '') === '');
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(applyFilters, 350);
  });

  if (searchClear) {
    searchClear.addEventListener('click', function () {
      searchInput.value = '';
      searchInput.focus();
      applyFilters();
    });
  }

  filtersPanel.addEventListener('change', function (event) {
    if (event.target.matches('input[type="checkbox"], select')) { applyFilters(); }
  });

  if (moreBtn) { moreBtn.addEventListener('click', loadMore); }
  if (filtersToggle) { filtersToggle.addEventListener('click', openFilters); }
  if (filtersClose) { filtersClose.addEventListener('click', closeFilters); }
  if (filtersApply) { filtersApply.addEventListener('click', closeFilters); }
  if (backdrop) { backdrop.addEventListener('click', closeFilters); }
  if (filtersReset) { filtersReset.addEventListener('click', resetAll); }
  if (resetInline) { resetInline.addEventListener('click', resetAll); }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && filtersPanel.classList.contains('is-open')) { closeFilters(); }
  });

  /* Подгрузка по скроллу */
  if ('IntersectionObserver' in window && sentinel) {
    new IntersectionObserver(function (entries) {
      if (entries[0].isIntersecting) { loadMore(); }
    }, { rootMargin: '400px 0px' }).observe(sentinel);
  }

  /* Реальная высота шапки — чтобы липкие блоки становились точно под ней,
     без щелей, в которые просвечивают карточки. */
  function syncHeaderHeight() {
    if (!topbar) { return; }
    var height = Math.round(topbar.getBoundingClientRect().height);
    document.documentElement.style.setProperty('--header-h', height + 'px');
  }
  syncHeaderHeight();
  window.addEventListener('resize', syncHeaderHeight);
  if ('ResizeObserver' in window && topbar) {
    new ResizeObserver(syncHeaderHeight).observe(topbar);
  }

  /* Тень у липкой шапки */
  if (topbar && 'IntersectionObserver' in window) {
    var marker = document.createElement('div');
    marker.style.cssText = 'position:absolute;top:0;height:1px;width:1px;';
    document.body.prepend(marker);
    new IntersectionObserver(function (entries) {
      topbar.classList.toggle('is-stuck', !entries[0].isIntersecting);
    }).observe(marker);
  }

  /* ---------- Кнопка «+»: добавить товар в список заказа ---------- */

  var ordersCount = document.getElementById('js-orders-count');

  function syncOrderButtons() {
    if (!window.OrderStore) { return; }
    grid.querySelectorAll('.js-order-toggle').forEach(function (button) {
      var added = window.OrderStore.has(button.getAttribute('data-order-id'));
      button.classList.toggle('is-added', added);
      button.setAttribute('aria-pressed', added ? 'true' : 'false');
      button.setAttribute('title', added ? 'Убрать из списка' : 'Добавить в список');
      button.setAttribute('aria-label', added ? 'Убрать из списка' : 'Добавить в список');
    });
  }

  function syncOrdersCount() {
    if (!ordersCount || !window.OrderStore) { return; }
    var total = window.OrderStore.count();
    ordersCount.textContent = String(total);
    ordersCount.classList.toggle('is-hidden', total === 0);
  }

  grid.addEventListener('click', function (event) {
    var button = event.target.closest('.js-order-toggle');
    if (!button || !window.OrderStore) { return; }

    var card = button.closest('.card');
    if (!card) { return; }

    window.OrderStore.toggle({
      id: card.getAttribute('data-id'),
      sku: card.getAttribute('data-sku') || '',
      name: card.getAttribute('data-name') || '',
      price: card.getAttribute('data-price') || '',
      stock: card.getAttribute('data-stock') || '',
      supplier: card.getAttribute('data-supplier') || '',
      supplier_id: card.getAttribute('data-supplier-id') || '0'
    });
    syncOrderButtons();
    syncOrdersCount();
  });

  if (window.OrderStore) {
    window.OrderStore.onChange(function () {
      syncOrderButtons();
      syncOrdersCount();
    });
    syncOrderButtons();
    syncOrdersCount();
  }

  /* ---------- Окно «Поставщики» по кнопке «?» ---------- */

  var infoModal = document.getElementById('js-info');
  var infoOpen = document.getElementById('js-info-open');

  function toggleInfo(show) {
    if (!infoModal) { return; }
    infoModal.classList.toggle('is-hidden', !show);
    document.body.style.overflow = show ? 'hidden' : '';
    if (show) {
      var close = infoModal.querySelector('.modal__close');
      if (close) { close.focus(); }
    } else if (infoOpen) {
      infoOpen.focus();
    }
  }

  if (infoOpen && infoModal) {
    infoOpen.addEventListener('click', function () { toggleInfo(true); });

    /* Закрываем крестиком и кликом по затемнению */
    infoModal.addEventListener('click', function (event) {
      if (event.target.closest('[data-info-close]')) { toggleInfo(false); }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !infoModal.classList.contains('is-hidden')) { toggleInfo(false); }
    });
  }

  syncIndicators();
})();
