<?php
use App\Support\Csrf;
use App\Import\ColumnRef;
use App\YmlExport\{UploadRepository,WarehouseRepository,FeedRepository,Matcher,FeedBuilder};
?>
  <?php if ($warehouseFormId !== null):
      $whEdit = $warehouseFormId > 0 ? WarehouseRepository::find($warehouseFormId) : null;
      $upEdit = $warehouseFormId > 0 ? UploadRepository::findByWarehouse($warehouseFormId) : null;
      if (is_array($formOld) && (int) ($formOld['id'] ?? -1) === $warehouseFormId) {
          $whEdit = array_merge($whEdit ?? [], $formOld);
          $upEdit = array_merge($upEdit ?? [], array_intersect_key($formOld, array_flip(['type', 'fetch_method', 'source_url', 'csv_delimiter', 'csv_encoding', 'skip_rows', 'sheet_index', 'skip_hidden'])));
          $upEdit['skip_hidden'] = isset($formOld['skip_hidden']) ? 1 : 0;
      }
      $upMapping = is_array($formOld) && (int) ($formOld['id'] ?? -1) === $warehouseFormId
          ? (array) ($formOld['mapping'] ?? []) : ($upEdit !== null ? UploadRepository::mappingOf($upEdit) : []);
  ?>
    <div class="card">
      <h2 class="card__title"><?= $warehouseFormId === 0 ? 'Новый склад' : 'Склад: ' . e((string) $whEdit['name']) ?></h2>
      <form method="post" enctype="multipart/form-data" id="warehouse-settings">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="warehouse-save">
        <input type="hidden" name="id" value="<?= $warehouseFormId ?>">
        <h3 class="a-subtitle">Параметры склада</h3>
        <label class="check-inline"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1"<?= chk((int)($whEdit['is_active']??1)===1) ?>> Активен</label>
        <div class="grid-3">
          <label class="field"><span class="field__label">Название <span class="req">*</span></span>
            <input class="input" name="name" required maxlength="190" value="<?= e((string) ($whEdit['name'] ?? '')) ?>" placeholder="склад 1">
            <span class="field__hint">Именно это название попадёт в атрибут warehouse_name фида.</span>
          </label>
          <label class="field"><span class="field__label">Код</span>
            <input class="input" name="code" maxlength="64" value="<?= e((string) ($whEdit['code'] ?? '')) ?>">
          </label>
          <label class="field"><span class="field__label">Приоритет (sort)</span>
            <input class="input" name="sort" type="number" value="<?= (int) ($whEdit['sort'] ?? 0) ?>">
            <span class="field__hint">Меньше = приоритетнее: цены оффера берутся с этого склада.</span>
          </label>
        </div>
        <div class="grid-3">
          <label class="field"><span class="field__label">Тип склада</span>
            <select class="input" name="kind">
              <option value="file"<?= sel('file', (string) ($whEdit['kind'] ?? 'file')) ?>>Из файла — остатки из прайса</option>
              <option value="virtual"<?= sel('virtual', (string) ($whEdit['kind'] ?? 'file')) ?>>Виртуальный — без файла</option>
            </select>
            <span class="field__hint">Виртуальный склад добавляется в outlets каждого оффера с постоянным остатком.</span>
          </label>
          <label class="field"><span class="field__label">Остаток виртуального склада</span>
            <input class="input" name="default_stock" type="number" min="0" value="<?= (int) ($whEdit['default_stock'] ?? 0) ?>">
            <span class="field__hint">Это число уйдёт в instock у всех офферов. В суммарный остаток для фильтра «исключать нулевые» не входит.</span>
          </label>
        </div>
        <fieldset class="warehouse-file-settings">
          <legend>Файл остатков</legend>
          <p class="card__hint">Excel, CSV или YML — файлом или по ссылке (Google Таблицы, Яндекс Диск).</p>
        <div class="grid-3">
          <label class="field"><span class="field__label">Тип файла</span>
            <select class="input" name="type">
              <?php foreach (UploadRepository::TYPES as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= sel($key, (string) ($upEdit['type'] ?? 'excel')) ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field"><span class="field__label">Способ получения</span>
            <select class="input" name="fetch_method">
              <option value="upload"<?= sel('upload', (string) ($upEdit['fetch_method'] ?? 'upload')) ?>>Загрузить файл</option>
              <option value="url"<?= sel('url', (string) ($upEdit['fetch_method'] ?? 'upload')) ?>>По ссылке</option>
            </select>
          </label>
          <label class="field"><span class="field__label">Ссылка на файл</span>
            <input class="input" name="source_url" value="<?= e((string) ($upEdit['source_url'] ?? '')) ?>" placeholder="https://…">
          </label>
        </div>
        <label class="field"><span class="field__label">Файл</span>
          <input class="input" type="file" name="price_file">
          <span class="field__hint">При ручной загрузке; новый файл заменяет прежний.</span>
        </label>

        <h3 class="a-subtitle">Маппинг полей</h3>
        <p class="card__hint"><b>Столбцы для объединения (Excel / CSV):</b> укажите буквы столбцов через запятую в нужном порядке. Значения объединяются через пробел.
          Например, <code>B,F</code> → Коляска ANEX AX-123. Можно указать <code>B,F,G</code>. Пропуск пустых ячеек настраивается галочкой ниже. Для YML укажите один тег.</p>
        <label class="check-inline">
          <input type="hidden" name="mapping[_merge_skip_empty]" value="0">
          <input type="checkbox" name="mapping[_merge_skip_empty]" value="1"<?= (string) ($upMapping['_merge_skip_empty'] ?? '1') !== '0' ? ' checked' : '' ?>>
          Пропускать пустые ячейки при объединении (Excel / CSV)
        </label>
        <p class="card__hint">Если галочка снята, место пустых ячеек сохраняется разделителями-пробелами.</p>
        <div class="grid-3">
          <?php foreach (UploadRepository::FIELDS as $field => $label): ?>
            <label class="field"><span class="field__label"><?= e($label) ?></span>
              <input class="input" name="mapping[<?= e($field) ?>]" value="<?= e((string) ($upMapping[$field] ?? '')) ?>" placeholder="B или B,F (для YML — тег)">
            </label>
          <?php endforeach; ?>
        </div>
        <div class="grid-3">
          <label class="field"><span class="field__label">Пропустить строк сверху</span>
            <input class="input" name="skip_rows" type="number" min="0" value="<?= (int) ($upEdit['skip_rows'] ?? 1) ?>">
          </label>
          <label class="field"><span class="field__label">Номер листа Excel</span>
            <input class="input" name="sheet_index" type="number" min="1" value="<?= (int) ($upEdit['sheet_index'] ?? 1) ?>">
          </label>
          <label class="field"><span class="field__label">Разделитель CSV</span>
            <input class="input" name="csv_delimiter" value="<?= e((string) ($upEdit['csv_delimiter'] ?? 'auto')) ?>">
          </label>
          <label class="field"><span class="field__label">Кодировка CSV</span>
            <input class="input" name="csv_encoding" value="<?= e((string) ($upEdit['csv_encoding'] ?? 'auto')) ?>">
          </label>
        </div>
        <label class="check-inline"><input type="checkbox" name="skip_hidden"<?= chk((int) ($upEdit['skip_hidden'] ?? 1) === 1) ?>> Не импортировать скрытые строки и столбцы</label>
        </fieldset>
        <div class="form__actions">
          <button class="btn btn--primary warehouse-import-button" name="save_and_import" value="1">Сохранить и загрузить</button>
          <button class="btn btn--ghost">Сохранить</button>
          <?php if ($upEdit !== null && !empty($upEdit['id'])): ?>
            <button class="btn btn--ghost warehouse-import-button" name="save_and_preview" value="1">Проверить маппинг</button>
          <?php endif; ?>
          <a class="btn btn--link" href="yml.php?tab=warehouses">Отмена</a>
        </div>
      </form>
      <?php if ($upEdit !== null && !empty($upEdit['id'])): ?>
      <form method="post" class="form__actions warehouse-file-delete" onsubmit="return confirm('Удалить только файл и его позиции? Сам склад сохранится.')">
        <?= Csrf::field() ?><input type="hidden" name="action" value="upload-delete"><input type="hidden" name="id" value="<?= (int) $upEdit['id'] ?>">
        <button class="btn btn--small btn--danger">Удалить файл и позиции</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($previewUpload !== null && $previewRows !== null): ?>
    <div class="card table-wrap">
      <h2 class="card__title" style="padding:18px 18px 0">Проверка маппинга: <?= e((string) ($previewUpload['warehouse_name'] ?? '')) ?></h2>
      <p class="card__hint" style="padding:0 18px">Первые строки файла как есть. Столбцы для маппинга указывайте буквами: A — первый, B — второй и т.д.</p>
      <table class="table table--compact">
        <tbody>
        <?php foreach ($previewRows as $i => $row): ?>
          <tr>
            <td class="muted small"><?= $i + 1 ?></td>
            <?php foreach ($row as $j => $cell): ?>
              <td><span class="pill pill--never"><?= e(is_numeric($j) ? ColumnRef::toLetter((int) $j) : (string) $j) ?></span> <?= e(mb_substr((string) $cell, 0, 80)) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="form__actions" style="padding:0 18px 18px"><a class="btn btn--ghost" href="yml.php?tab=warehouses">Закрыть</a></div>
    </div>
  <?php endif; ?>

