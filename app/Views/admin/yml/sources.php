<?php
use App\Support\Csrf;
use App\Import\ColumnRef;
use App\YmlExport\{UploadRepository,WarehouseRepository,FeedRepository,Matcher,FeedBuilder};
?>

  <!-- ================= Склады и загрузка ================= -->
  <div class="toolbar">
    <div class="toolbar__stats">
      <span class="stat"><b><?= count($warehouses) ?></b> складов</span>
      <span class="stat"><b><?= (int) array_sum(array_column($warehouses, 'items_count')) ?></b> позиций</span>
      <span class="stat"><b><?= (int) $matchStats['confirmed'] ?></b> сопоставлено</span>
    </div>
    <div class="toolbar__actions">
      <a class="btn btn--primary" href="yml.php?tab=warehouses&wh_form=new">+ Добавить источник</a>
    </div>
  </div>

  <div class="card table-wrap">
    <table class="table">
      <thead>
        <tr><th>Активен</th><th>Склад</th><th>Код</th><th>Файл / ссылка</th><th class="num">Позиций</th><th class="num">Сопоставлено</th><th>Обновлён</th><th>Статус</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($warehouses === []): ?>
        <tr><td colspan="9" class="muted">Складов пока нет — создайте первый.</td></tr>
      <?php endif; ?>
      <?php foreach ($warehouses as $wh): ?>
        <?php $isVirtual = (string) ($wh['kind'] ?? 'file') === 'virtual'; $up = $uploadsByWarehouse[(int) $wh['id']] ?? null; ?>
        <tr>
          <td><form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="action" value="warehouse-toggle"><input type="hidden" name="id" value="<?= (int)$wh['id'] ?>"><input type="hidden" name="active" value="<?= $wh['is_active']?0:1 ?>"><input type="checkbox" data-submit-change aria-label="Активен: <?= e($wh['name']) ?>"<?= chk((int)$wh['is_active']===1) ?>></form></td>
          <td>
            <a class="yml-source-name" href="yml.php?tab=warehouses&wh_form=<?= (int)$wh['id'] ?>"><b><?= e((string) $wh['name']) ?></b></a>
            <?php if ($isVirtual): ?>
              <span class="pill pill--running">виртуальный · <?= (int) ($wh['default_stock'] ?? 0) ?></span>
            <?php endif; ?>
          </td>
          <td class="mono small"><?= e((string) $wh['code']) ?></td>
          <td class="small warehouse-file"><?= $isVirtual ? '—' : e($up === null ? 'Не настроен' : (string) ($up['fetch_method'] === 'url' ? $up['source_url'] : ($up['original_filename'] ?? '—'))) ?></td>
          <td class="num"><?= $isVirtual ? '—' : (int) $wh['items_count'] ?></td>
          <td class="num"><?= $isVirtual ? '—' : (int) $wh['confirmed_count'] ?></td>
          <td class="small"><?= $isVirtual || $up === null ? '—' : e(format_datetime($up['last_run_at'] ?? null)) ?></td>
          <td>
            <?php if (!$isVirtual && $up !== null): ?>
              <span class="pill <?= $up['last_status'] === 'error' ? 'pill--error' : ($up['last_status'] === 'ok' ? 'pill--ok' : 'pill--never') ?>"><?= e(['ok'=>'OK', 'error'=>'Ошибка', 'running'=>'Импорт…', 'never'=>'Ещё не загружался'][$up['last_status']] ?? $up['last_status']) ?></span>
              <?php if (!empty($up['last_error'])): ?><div class="small error-text"><?= e(mb_substr((string) $up['last_error'], 0, 200)) ?></div><?php endif; ?>
            <?php endif; ?>
            <?php if ((int) $wh['is_active'] === 1): ?>
              <span class="pill pill--ok">активен</span>
            <?php else: ?>
              <span class="pill pill--disabled">отключён</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <a class="btn btn--small btn--ghost" href="yml.php?tab=warehouses&wh_form=<?= (int) $wh['id'] ?>">Открыть</a>
            <details class="yml-actions"><summary aria-label="Действия склада">•••</summary><div>
            <?php if (!$isVirtual && $up !== null): ?>
              <form method="post" class="inline">
                <?= Csrf::field() ?><input type="hidden" name="action" value="upload-import"><input type="hidden" name="id" value="<?= (int) $up['id'] ?>">
                <button class="btn btn--small btn--primary">Загрузить</button>
              </form>
            <?php endif; ?>
            <form method="post" class="inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="warehouse-toggle">
              <input type="hidden" name="id" value="<?= (int) $wh['id'] ?>">
              <input type="hidden" name="active" value="<?= (int) $wh['is_active'] === 1 ? 0 : 1 ?>">
              <button class="btn btn--small btn--ghost"><?= (int) $wh['is_active'] === 1 ? 'Отключить' : 'Включить' ?></button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('Удалить склад вместе с позициями и сопоставлениями?')">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="warehouse-delete">
              <input type="hidden" name="id" value="<?= (int) $wh['id'] ?>">
              <button class="btn btn--small btn--danger">Удалить</button>
            </form>
            </div></details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

