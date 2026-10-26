<?php
/** @var array<string,mixed> $source */
/** @var array{raw:list<list<string>>,mapped:list<array<string,mixed>>,tags:list<string>,errors:list<string>}|null $preview */
/** @var string|null $error */
/** @var list<array<string,mixed>> $sample */
use App\Import\ColumnRef;
use App\Models\ProductRepository;
?>
<p class="breadcrumbs">
  <a href="index.php">Источники</a> /
  <a href="index.php?page=source-form&id=<?= (int) $source['id'] ?>"><?= e($source['name']) ?></a> /
  Проверка маппинга
</p>

<?php if ($error !== null): ?>
  <div class="alert alert--error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($preview !== null): ?>

  <?php if ($preview['errors'] !== []): ?>
    <div class="alert alert--warning">
      Часть строк будет пропущена: <?= e(implode('; ', $preview['errors'])) ?>
    </div>
  <?php endif; ?>

  <h2 class="a-subtitle">Что прочитано из файла</h2>
  <div class="card table-wrap">
    <?php if ($source['type'] === 'yml'): ?>
      <table class="table table--compact">
        <thead><tr><th>№</th><th>Теги оффера</th></tr></thead>
        <tbody>
        <?php foreach ($preview['raw'] as $i => $line): ?>
          <tr><td><?= $i + 1 ?></td><td class="mono small"><?= e(implode(' · ', $line)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($preview['tags'] !== []): ?>
        <p class="card__hint">Доступные теги:
          <?= implode(', ', array_map(static fn (string $tag): string => '<code>' . e($tag) . '</code>', $preview['tags'])) ?>
        </p>
      <?php endif; ?>
    <?php else: ?>
      <?php $width = 0; foreach ($preview['raw'] as $row) { $width = max($width, count($row)); } ?>
      <table class="table table--compact">
        <thead>
          <tr>
            <th>Строка</th>
            <?php for ($i = 0; $i < $width; $i++): ?>
              <th><?= e(ColumnRef::toLetter($i)) ?><span class="muted"> (<?= $i + 1 ?>)</span></th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($preview['raw'] as $i => $row): ?>
          <tr class="<?= $i < (int) $source['skip_rows'] ? 'row--skipped' : '' ?>">
            <td><?= $i + 1 ?><?= $i < (int) $source['skip_rows'] ? ' <span class="muted small">(шапка)</span>' : '' ?></td>
            <?php for ($j = 0; $j < $width; $j++): ?>
              <td class="small"><?= e(mb_substr((string) ($row[$j] ?? ''), 0, 40)) ?></td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <h2 class="a-subtitle">Как это будет выглядеть в каталоге</h2>
  <?php if ($preview['mapped'] === []): ?>
    <div class="alert alert--error">Ни одна строка не разобралась. Проверьте номера столбцов/теги и количество пропускаемых строк.</div>
  <?php else: ?>
    <div class="card table-wrap">
      <table class="table">
        <thead><tr><th>Название</th><th>Артикул</th><th class="num">Цена</th><th class="num">Цена промо</th><th>Наличие</th><th>Фото</th></tr></thead>
        <tbody>
        <?php foreach ($preview['mapped'] as $product): ?>
          <tr>
            <td data-label="Название"><?= e($product['name']) ?></td>
            <td data-label="Артикул" class="mono"><?= e($product['sku']) ?></td>
            <td data-label="Цена" class="num">
              <?= e(price_label((string) ($product['price_text'] ?? ''), $product['price'], $product['currency'])) ?>
            </td>
            <td data-label="Цена промо" class="num">
              <?= isset($product['promo_price']) && (float) $product['promo_price'] > 0
                  ? e(price_label((string) ($product['promo_price_text'] ?? ''), (float) $product['promo_price'], (string) $product['currency'])) : '—' ?>
            </td>
            <td data-label="Наличие">
              <?= e($product['stock_text'] !== '' ? $product['stock_text'] : '—') ?>
              <span class="muted small">(<?= e(ProductRepository::AVAILABILITY[$product['availability']]) ?>)</span>
            </td>
            <td data-label="Фото"><?= $product['image_url'] ? '<span class="pill pill--ok">есть</span>' : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($sample !== []): ?>
  <h2 class="a-subtitle">Последние загруженные товары этого поставщика</h2>
  <div class="card table-wrap">
    <table class="table">
      <thead><tr><th>Название</th><th>Артикул</th><th class="num">Цена</th><th class="num">Цена промо</th><th>Наличие</th><th>Обновлён</th></tr></thead>
      <tbody>
      <?php foreach ($sample as $product): ?>
        <tr>
          <td data-label="Название"><?= e($product['name']) ?></td>
          <td data-label="Артикул" class="mono"><?= e($product['sku']) ?></td>
          <td data-label="Цена" class="num">
            <?= e(price_label(
                (string) ($product['price_text'] ?? ''),
                $product['price'] === null ? null : (float) $product['price'],
                (string) $product['currency']
            )) ?>
          </td>
          <td data-label="Цена промо" class="num">
              <?= isset($product['promo_price']) && (float) $product['promo_price'] > 0
                  ? e(price_label((string) ($product['promo_price_text'] ?? ''), (float) $product['promo_price'], (string) $product['currency'])) : '—' ?>
            </td>
          <td data-label="Наличие">
            <?= e(($product['stock_text'] ?? '') !== '' ? $product['stock_text'] : ProductRepository::AVAILABILITY[$product['availability']]) ?>
          </td>
          <td data-label="Обновлён"><?= e(format_datetime($product['updated_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<p class="form__actions">
  <a class="btn btn--ghost" href="index.php?page=source-form&id=<?= (int) $source['id'] ?>">К настройкам источника</a>
  <a class="btn btn--link" href="index.php">К списку</a>
</p>
