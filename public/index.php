<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Models\ProductRepository;
use App\Models\SourceRepository;
use App\Support\CatalogRequest;
use App\Support\Config;
use App\Support\Db;
use App\View\Catalog;

try {
    if (!Db::tableExists('products')) {
        redirect('install.php');
    }
} catch (PDOException $exception) {
    error_log('[catalog] ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    exit('<h1>Каталог временно недоступен</h1><p>Нет соединения с базой данных. '
        . 'Проверьте настройки в config.php.</p>');
}

$filters   = CatalogRequest::fromQuery($_GET);
$result    = ProductRepository::search($filters);
$suppliers = SourceRepository::listForCatalog();
$siteName  = (string) Config::get('site_name', 'Каталог товаров');
$isFiltered = CatalogRequest::isFiltered($filters);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<title><?= e($siteName) ?></title>
<meta name="description" content="Объединённый каталог товаров поставщиков: поиск по названию и артикулу, фильтры по поставщику и наличию.">
<link rel="stylesheet" href="<?= e(asset('assets/css/catalog.css')) ?>">
</head>
<body>
<header class="topbar">
  <div class="topbar__inner">
    <a class="topbar__logo" href="index.php"><?= e($siteName) ?></a>
    <div class="topbar__count" id="js-total"><?= e(Catalog::foundLabel($result['total'])) ?></div>
    <div class="topbar__actions">
      <button type="button" class="help-btn" id="js-info-open"
              title="Поставщики и даты обновления" aria-label="Поставщики и даты обновления">?</button>
      <a class="orders-link" href="orders.php" id="js-orders-link">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h15l-1.5 9h-12L6 6Zm0 0L5 3H2m6 18a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm10 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>Заказы</span>
        <span class="orders-link__count is-hidden" id="js-orders-count">0</span>
      </a>
    </div>
  </div>

  <form class="searchbar" id="js-filter-form" method="get" action="index.php" role="search">
    <div class="searchbar__field">
      <svg class="searchbar__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 3a7.5 7.5 0 1 1-4.9 13.2l-3.3 3.3a1 1 0 0 1-1.4-1.4l3.3-3.3A7.5 7.5 0 0 1 10.5 3Zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z"/></svg>
      <input type="search" name="q" id="js-search" value="<?= e($filters['q']) ?>"
             placeholder="Название или артикул" autocomplete="off" enterkeyhint="search">
      <button type="button" class="searchbar__clear<?= $filters['q'] === '' ? ' is-hidden' : '' ?>" id="js-search-clear" aria-label="Очистить поиск">×</button>
    </div>
    <button type="button" class="filters-toggle<?= $isFiltered ? ' is-active' : '' ?>" id="js-filters-toggle" aria-expanded="false" aria-controls="js-filters">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M6 12h12M10 18h4" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
      <span>Фильтры</span>
      <span class="filters-toggle__dot<?= $isFiltered ? '' : ' is-hidden' ?>" id="js-filters-dot"></span>
    </button>
  </form>
</header>

<div class="layout">
  <!-- Панель фильтров: сайдбар на десктопе, шторка снизу на мобильных -->
  <aside class="filters" id="js-filters" aria-label="Фильтры каталога">
    <div class="filters__sheet">
      <div class="filters__header">
        <h2>Фильтры</h2>
        <button type="button" class="filters__close" id="js-filters-close" aria-label="Закрыть фильтры">×</button>
      </div>

      <div class="filters__body">
        <section class="filter-group">
          <h3 class="filter-group__title">Поставщик</h3>
          <?php if ($suppliers === []): ?>
            <p class="filter-group__empty">Пока нет загруженных товаров.</p>
          <?php else: ?>
            <?php foreach ($suppliers as $supplier): ?>
              <label class="check">
                <input type="checkbox" name="supplier[]" value="<?= (int) $supplier['id'] ?>"
                  <?= in_array((int) $supplier['id'], $filters['suppliers'], true) ? 'checked' : '' ?>>
                <span class="check__box" aria-hidden="true"></span>
                <span class="check__label"><?= e($supplier['name']) ?></span>
                <span class="check__count"><?= (int) $supplier['products_count'] ?></span>
              </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </section>

        <section class="filter-group">
          <h3 class="filter-group__title">Сортировка</h3>
          <select name="sort" id="js-sort" class="select">
            <?php foreach (CatalogRequest::SORTS as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $filters['sort'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </section>
      </div>

      <div class="filters__footer">
        <button type="button" class="btn btn--ghost" id="js-filters-reset">Сбросить всё</button>
        <button type="button" class="btn btn--primary filters__apply" id="js-filters-apply">Показать</button>
      </div>
    </div>
  </aside>

  <main class="catalog">
    <div class="catalog__head">
      <span class="catalog__total" id="js-total-inline"><?= e(Catalog::foundLabel($result['total'])) ?></span>
      <button type="button" class="link-reset<?= $isFiltered ? '' : ' is-hidden' ?>" id="js-reset-inline">Сбросить фильтры</button>
    </div>

    <!-- Шапка списка: видна только на широких экранах, колонки совпадают с карточкой -->
    <div class="list-head" aria-hidden="true">
      <span></span>
      <span>Товар</span>
      <span>Артикул</span>
      <span>Наличие</span>
      <span>Цена</span>
      <span>Поставщик</span>
      <span></span>
    </div>

    <div class="grid" id="js-grid" aria-busy="false">
      <?= $result['items'] === [] ? Catalog::emptyState() : Catalog::cards($result['items']) ?>
    </div>

    <div class="catalog__more">
      <button type="button" class="btn btn--more<?= $result['has_more'] ? '' : ' is-hidden' ?>" id="js-more">Показать ещё</button>
      <div class="spinner is-hidden" id="js-spinner" role="status" aria-label="Загрузка"></div>
    </div>
    <div id="js-sentinel" class="sentinel"></div>
  </main>
</div>

<div class="backdrop" id="js-backdrop" hidden></div>

<?php
// Самая свежая дата среди поставщиков — ею подписываем каталог целиком
$lastUpdate = null;
foreach ($suppliers as $supplier) {
    if (!empty($supplier['last_success_at']) && ($lastUpdate === null || $supplier['last_success_at'] > $lastUpdate)) {
        $lastUpdate = $supplier['last_success_at'];
    }
}
?>
<div class="modal is-hidden" id="js-info">
  <div class="modal__backdrop" data-info-close></div>
  <div class="modal__box" role="dialog" aria-modal="true" aria-labelledby="js-info-title">
    <div class="modal__head">
      <h2 id="js-info-title">Поставщики</h2>
      <button type="button" class="modal__close" data-info-close aria-label="Закрыть">×</button>
    </div>

    <div class="modal__body">
      <?php if ($suppliers === []): ?>
        <p class="modal__empty">Пока нет загруженных прайсов.</p>
      <?php else: ?>
        <?php foreach ($suppliers as $supplier): ?>
          <div class="supplier-row">
            <div class="supplier-row__main">
              <div class="supplier-row__name"><?= e($supplier['name']) ?></div>
              <?php if (!empty($supplier['last_success_at'])): ?>
                <div class="supplier-row__date">файл обновлён <?= e(format_datetime($supplier['last_success_at'])) ?></div>
              <?php else: ?>
                <div class="supplier-row__date supplier-row__date--never">файл ещё не загружался</div>
              <?php endif; ?>
            </div>
            <div class="supplier-row__count"><?= e(Catalog::foundLabel((int) $supplier['products_count'])) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($lastUpdate !== null): ?>
      <div class="modal__foot">Каталог обновлён: <?= e(format_datetime($lastUpdate)) ?></div>
    <?php endif; ?>
  </div>
</div>

<footer class="footer">
  <span>Товаров в каталоге: <?= e(number_format(ProductRepository::totalCount(), 0, ',', ' ')) ?></span>
  <a href="admin/index.php">Админ-панель</a>
</footer>

<script>
  window.CATALOG_STATE = <?= json_encode([
      'page'    => $result['page'],
      'pages'   => $result['pages'],
      'hasMore' => $result['has_more'],
      'perPage' => $result['per_page'],
      'api'     => 'api/products.php',
      'defaultSort' => CatalogRequest::DEFAULT_SORT,
  ], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(asset('assets/js/order-store.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/catalog.js')) ?>" defer></script>
</body>
</html>
