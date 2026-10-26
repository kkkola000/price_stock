<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use App\Models\PriceChangeRepository;
use App\Models\ProductRepository;
use App\Support\Config;

header('Cache-Control: no-store');
try {
    $result = PriceChangeRepository::recent(max(1, (int) ($_GET['page'] ?? 1)));
    $total = ProductRepository::totalCount();
} catch (Throwable $exception) {
    error_log('[price-changes] ' . $exception->getMessage());
    http_response_code(503);
    exit('История цен временно недоступна.');
}
$label = static function (array $item, string $side, string $field): string {
    $value = $item[$side . '_' . $field];
    $text = (string) ($item[$side . '_' . $field . '_text'] ?? '');
    return $value === null && trim($text) === '' ? '—'
        : price_label($text, $value === null ? null : (float) $value, $item[$side . '_currency']);
};
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Последние изменения цен — <?= e((string) Config::get('site_name', 'Каталог товаров')) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/catalog.css')) ?>">
</head>
<body>
<header class="topbar"><a class="topbar__logo" href="index.php"><?= e((string) Config::get('site_name', 'Каталог товаров')) ?></a></header>
<main class="price-history">
  <div class="price-history__toolbar"><span><?= e(\App\View\Catalog::foundLabel($total)) ?></span><a class="price-changes-link" href="index.php">Вернуться к каталогу</a></div>
  <section class="price-history__panel" aria-labelledby="history-title">
    <header class="price-history__header"><h1 id="history-title">Последние изменения цен</h1><p>За последние 7 дней · Сначала свежие</p></header>
    <?php if ($result['items'] === []): ?>
      <p class="price-history__empty">За последние 7 дней изменений цен нет.</p>
    <?php else: ?>
      <div class="price-history__scroll">
      <table class="price-history__table">
        <thead><tr><th>Наименование</th><th>Артикул</th><th>Поставщик</th><th>Изменения цен</th></tr></thead>
        <tbody>
        <?php foreach ($result['items'] as $item): ?>
          <tr>
            <td data-label="Наименование"><?= e($item['name']) ?><time datetime="<?= e(str_replace(' ', 'T', $item['changed_at'])) ?>"><?= e(format_datetime($item['changed_at'])) ?></time></td>
            <td data-label="Артикул"><?= e($item['sku']) ?></td>
            <td data-label="Поставщик"><?= e($item['supplier_name']) ?></td>
            <td class="price-history__prices">
              <?php foreach (['price' => 'Цена', 'promo_price' => 'Промо цена'] as $field => $caption): ?>
              <div class="price-history__price<?= $field === 'promo_price' ? ' price-history__price--promo' : '' ?>">
                <span><?= e($caption) ?></span><span class="price-history__old"><?= e($label($item, 'old', $field)) ?></span><span aria-label="теперь">→</span><strong><?= e($label($item, 'new', $field)) ?></strong>
              </div>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <nav class="price-history__pagination" aria-label="Страницы истории">
        <?php if ($result['page'] > 1): ?><a href="?page=<?= $result['page'] - 1 ?>">← Новее</a><?php endif; ?>
        <span>Страница <?= $result['page'] ?> из <?= $result['pages'] ?></span>
        <?php if ($result['page'] < $result['pages']): ?><a href="?page=<?= $result['page'] + 1 ?>">Старее →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
