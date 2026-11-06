<?php
use App\Support\Csrf;
use App\Import\ColumnRef;
use App\YmlExport\{UploadRepository,WarehouseRepository,FeedRepository,Matcher,FeedBuilder};
?>

  <!-- ================= Сопоставление ================= -->
  <div class="tabs" style="margin-bottom:14px" aria-label="Статусы сопоставлений">
    <a href="yml.php?tab=matches&sub=queue&warehouse_id=<?= $warehouseId ?>" class="<?= $matchesSub === 'queue' ? 'is-active' : '' ?>">К сопоставлению
      <?php if ($pendingCount > 0): ?><span class="pill pill--running"><?= (int) $pendingCount ?></span><?php endif; ?></a>
    <a href="yml.php?tab=matches&sub=items&warehouse_id=<?= $warehouseId ?>" class="<?= $matchesSub === 'items' ? 'is-active' : '' ?>">Подтверждённые
      <?php if ((int) $matchStats['confirmed'] > 0): ?><span class="pill pill--ok"><?= (int) $matchStats['confirmed'] ?></span><?php endif; ?></a>
  </div>

  <div class="toolbar">
    <form method="get" class="form__actions match-search">
      <input type="hidden" name="warehouse_id" value="<?= $warehouseId ?>"><input type="hidden" name="tab" value="matches"><input type="hidden" name="sub" value="<?= e($matchesSub) ?>">
      <input class="input" name="list_q" value="<?= e($listQuery) ?>" placeholder="Артикул или название" aria-label="Поиск сопоставлений">
      <select class="input match-search-field" name="list_field" aria-label="Искать в">
        <?php foreach (['all'=>'По всем полям','sku'=>'По артикулу','name'=>'По названию'] as $key=>$label): ?>
          <option value="<?= e($key) ?>"<?= sel($key,$listField) ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn--primary">Найти</button>
      <?php if ($listQuery !== ''): ?><a class="btn btn--ghost" href="?tab=matches&sub=<?= e($matchesSub) ?>&warehouse_id=<?= $warehouseId ?>">Сбросить</a><?php endif; ?>
    </form>
    <button type="button" class="btn btn--danger" data-open-dialog="match-clear"<?= $matchStats['confirmed'] === 0 ? ' disabled' : '' ?>>Очистить список товаров</button>
  </div>
  <dialog class="match-dialog match-dialog--small" id="match-clear" aria-labelledby="match-clear-title">
    <h2 class="card__title" id="match-clear-title">Очистить список товаров?</h2>
    <p><?= $warehouseId>0?'Подтверждённые сопоставления этого склада будут сняты, включая скрытые поиском.':'Все подтверждённые сопоставления будут сняты, включая скрытые поиском.' ?> Позиции вернутся в очередь «К сопоставлению».</p>
    <p class="muted">Файлы складов и товары каталога сохранятся. Эти позиции не попадут в фиды до повторного подтверждения.</p>
    <form method="post" class="form__actions">
      <?= Csrf::field() ?><input type="hidden" name="action" value="match-clear"><input type="hidden" name="warehouse_id" value="<?= $warehouseId ?>">
      <button type="button" class="btn btn--ghost" data-close-dialog>Отмена</button>
      <button class="btn btn--danger">Очистить список</button>
    </form>
  </dialog>
  <?php if ($manualMatch !== null): ?>
    <dialog class="match-dialog" id="match-picker" aria-labelledby="match-picker-title">
      <div class="match-dialog__header"><h2 class="card__title" id="match-picker-title">Выбрать другой товар</h2><button type="button" class="btn btn--ghost" data-close-dialog aria-label="Закрыть">×</button></div>
      <p class="card__hint">
        Позиция: <b><?= e((string) ($manualMatch['item_name'] ?? $manualMatch['item_sku'])) ?></b>
        <span class="mono small muted"><?= e((string) $manualMatch['item_sku']) ?></span>
        · склад «<?= e((string) $manualMatch['warehouse_name']) ?>»
      </p>
      <form method="get" class="form__actions" style="margin-bottom:14px">
        <input type="hidden" name="warehouse_id" value="<?= $warehouseId ?>"><input type="hidden" name="tab" value="matches">
        <input type="hidden" name="sub" value="<?= e($matchesSub) ?>">
        <input type="hidden" name="match_id" value="<?= (int) $manualMatch['id'] ?>">
        <input type="hidden" name="list_q" value="<?= e($listQuery) ?>">
        <input type="hidden" name="list_field" value="<?= e($listField) ?>">
        <input class="input" style="max-width:320px" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>" placeholder="Поиск по названию или артикулу каталога">
        <select class="input match-search-field" name="field" aria-label="Искать в">
          <?php foreach (['all'=>'По всем полям','sku'=>'По артикулу','name'=>'По названию'] as $key=>$label): ?>
            <option value="<?= e($key) ?>"<?= sel($key,$candidateField) ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn--primary">Найти</button>
        <button class="btn btn--ghost" type="button" data-close-dialog>Отмена</button>
      </form>
      <?php if (trim((string) ($_GET['q'] ?? '')) !== ''): ?>
        <?php if ($candidates === []): ?>
          <p class="muted">Ничего не найдено — попробуйте другой запрос.</p>
        <?php else: ?>
          <div class="table-wrap"><table class="table table--compact"><thead><tr><th>Наименование / артикул</th><th>Поставщик</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($candidates as $candidate): ?>
              <tr>
                <td><b><?= e((string) $candidate['name']) ?></b><br><span class="mono small muted"><?= e((string) $candidate['sku']) ?></span></td>
                <td><?= e((string) $candidate['supplier_name']) ?></td>
                <td class="row-actions">
                  <form method="post" class="inline">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="match-confirm"><input type="hidden" name="warehouse_id" value="<?= $warehouseId ?>">
                    <input type="hidden" name="match_id" value="<?= (int) $manualMatch['id'] ?>">
        <input type="hidden" name="list_q" value="<?= e($listQuery) ?>">
        <input type="hidden" name="list_field" value="<?= e($listField) ?>">
                    <input type="hidden" name="product_id" value="<?= (int) $candidate['id'] ?>">
                    <input type="hidden" name="return_sub" value="<?= e($matchesSub) ?>">
                    <input type="hidden" name="list_q" value="<?= e($listQuery) ?>">
                    <input type="hidden" name="list_field" value="<?= e($listField) ?>">
                    <button class="btn btn--small btn--primary">Выбрать</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      <?php endif; ?>
    </dialog>
  <?php endif; ?>

<?php if ($matchesSub === 'queue'): ?>
  <div class="toolbar">
    <div class="toolbar__stats">
      <span class="stat"><b><?= (int) $matchStats['suggested'] ?></b> ждут подтверждения</span>
      <span class="stat"><b><?= (int) $matchStats['unmatched'] ?></b> без пары</span>
      <span class="stat"><b><?= (int) $matchStats['confirmed'] ?></b> подтверждено</span>
      <span class="stat"><b><?= (int) $matchStats['rejected'] ?></b> отклонено</span>
    </div>
    <div class="toolbar__actions">
      <form method="post" class="inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="match-bulk"><input type="hidden" name="warehouse_id" value="<?= $warehouseId ?>">
        <input class="input" style="width:90px" type="number" name="min_score" value="80" min="50" max="100" title="Минимальное совпадение, %">
        <button class="btn btn--primary">Подтвердить все ≥ %</button>
      </form>
    </div>
  </div>

  <div class="card table-wrap">
    <table class="table">
      <thead>
        <tr><th>Моя позиция</th><th></th><th>Кандидат из каталога</th><th>Совпадение</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($queue === []): ?>
        <tr><td colspan="5" class="muted"><?= $listQuery !== '' ? 'Ничего не найдено — измените запрос.' : 'Очередь пуста: все позиции сопоставлены и подтверждены.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($queue as $row): ?>
        <tr>
          <td>
            <b><?= e((string) ($row['item_name'] ?? $row['item_sku'])) ?></b><br>
            <span class="muted small mono"><?= e((string) $row['item_sku']) ?> · <?= e((string) $row['warehouse_name']) ?><?= $row['stock_qty'] !== null ? ' · остаток ' . (int) $row['stock_qty'] : '' ?></span>
          </td>
          <td class="match-arrow">→</td>
          <td>
            <?php if ($row['product_id'] !== null && $row['product_name'] !== null): ?>
              <b><?= e((string) $row['product_name']) ?></b><br>
              <span class="muted small mono"><?= e((string) $row['product_sku_live']) ?></span>
            <?php elseif ($row['product_id'] !== null): ?>
              <span class="muted">Товар удалён из каталога (был <?= e((string) $row['product_sku']) ?>)</span>
            <?php else: ?>
              <span class="muted">Автоматически пара не найдена</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['method'] === 'sku'): ?>
              <span class="pill pill--ok">артикул <?= (int) $row['score'] ?>%</span>
            <?php elseif ($row['method'] === 'name'): ?>
              <span class="pill pill--running">название <?= (int) $row['score'] ?>%</span>
            <?php else: ?>
              <span class="pill pill--never">—</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <?php if ($row['product_id'] !== null): ?>
              <form method="post" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="match-confirm"><input type="hidden" name="warehouse_id" value="<?= $warehouseId ?>">
                <input type="hidden" name="match_id" value="<?= (int) $row['id'] ?>">
                <button class="btn btn--small btn--primary">Подтвердить</button>
              </form>
            <?php endif; ?>
            <a class="btn btn--small btn--ghost" href="yml.php?tab=matches&match_id=<?= (int) $row['id'] ?>&list_q=<?= e(rawurlencode($listQuery)) ?>&list_field=<?= e($listField) ?>&warehouse_id=<?= $warehouseId ?>"><?= $row['product_id'] !== null ? 'Другой' : 'Сопоставить' ?></a>
            <?php if ($row['product_id'] !== null): ?>
              <form method="post" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="match-reject"><input type="hidden" name="warehouse_id" value="<?= $warehouseId ?>">
                <input type="hidden" name="match_id" value="<?= (int) $row['id'] ?>">
                <button class="btn btn--small btn--danger">Не сопоставлять</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php else: ?>
  <!-- ================= Товары (подтверждённые сопоставления) ================= -->
  <div class="card table-wrap">
    <table class="table">
      <thead>
        <tr><th>Моя позиция</th><th></th><th>Товар каталога</th><th>Совпадение</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($confirmedRows === []): ?>
        <tr><td colspan="5" class="muted">Подтверждённых сопоставлений пока нет — они появятся здесь после подтверждения в очереди «К сопоставлению».</td></tr>
      <?php endif; ?>
      <?php foreach ($confirmedRows as $row): ?>
        <tr>
          <td>
            <b><?= e((string) ($row['item_name'] ?? $row['item_sku'])) ?></b><br>
            <span class="muted small mono"><?= e((string) $row['item_sku']) ?> · <?= e((string) $row['warehouse_name']) ?><?= $row['stock_qty'] !== null ? ' · остаток ' . (int) $row['stock_qty'] : '' ?></span>
          </td>
          <td class="match-arrow">→</td>
          <td>
            <?php if ($row['product_name'] !== null): ?>
              <b><?= e((string) $row['product_name']) ?></b><br>
              <span class="muted small mono"><?= e((string) $row['product_sku_live']) ?></span>
            <?php else: ?>
              <span class="muted">Товар удалён из каталога (был <?= e((string) $row['product_sku']) ?>)</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['method'] === 'sku'): ?>
              <span class="pill pill--ok">артикул <?= (int) $row['score'] ?>%</span>
            <?php elseif ($row['method'] === 'name'): ?>
              <span class="pill pill--running">название <?= (int) $row['score'] ?>%</span>
            <?php elseif ($row['method'] === 'manual'): ?>
              <span class="pill pill--ok">вручную</span>
            <?php else: ?>
              <span class="pill pill--never">—</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <a class="btn btn--small btn--ghost" href="yml.php?tab=matches&sub=items&match_id=<?= (int) $row['id'] ?>&list_q=<?= e(rawurlencode($listQuery)) ?>&list_field=<?= e($listField) ?>&warehouse_id=<?= $warehouseId ?>">Другой</a>
            <form method="post" class="inline" onsubmit="return confirm('Удалить сопоставление? Позиция вернётся в очередь «К сопоставлению».')">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="match-unmatch"><input type="hidden" name="warehouse_id" value="<?= $warehouseId ?>">
              <input type="hidden" name="match_id" value="<?= (int) $row['id'] ?>">
              <button class="btn btn--small btn--danger">Удалить сопоставление</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($confirmedRows) >= 500): ?>
    <p class="muted small">Показаны первые 500 подтверждённых сопоставлений.</p>
  <?php endif; ?>
<?php endif; ?>

