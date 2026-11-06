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
    <div class="card yml-dense yml-warehouse-form">
      <?php if($warehouseFormId===0): ?><h2 class="card__title">Новый склад</h2><?php endif; ?>
      <form method="post" enctype="multipart/form-data" id="warehouse-settings">
        <?= Csrf::field() ?><input type="hidden" name="action" value="warehouse-save"><input type="hidden" name="id" value="<?= $warehouseFormId ?>">
        <section class="yml-compact-section">
          <h3 class="yml-section-heading">Склад</h3>
          <div class="yml-warehouse-basics">
            <label class="check-inline"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1"<?= chk((int)($whEdit['is_active']??1)===1) ?>> Активен</label>
            <label class="field"><span class="field__label">Название <span class="req">*</span></span><input class="input" name="name" required maxlength="190" value="<?= e((string)($whEdit['name']??'')) ?>"><span class="field__hint">Название передаётся в warehouse_name фида.</span></label>
            <label class="field"><span class="field__label">Код</span><input class="input" name="code" maxlength="64" value="<?= e((string)($whEdit['code']??'')) ?>"></label>
            <label class="field"><span class="field__label">Приоритет</span><input class="input" name="sort" type="number" value="<?= (int)($whEdit['sort']??0) ?>"><span class="field__hint">Меньше — приоритетнее. Данные оффера берутся с приоритетного склада.</span></label>
            <label class="field"><span class="field__label">Тип склада</span><select class="input" name="kind"><option value="file"<?= sel('file',$whEdit['kind']??'file') ?>>Из файла</option><option value="virtual"<?= sel('virtual',$whEdit['kind']??'file') ?>>Виртуальный</option></select><span class="field__hint">Виртуальный склад добавляется в outlets с постоянным остатком.</span></label>
          </div>
          <label class="field yml-virtual-stock"><span class="field__label">Остаток виртуального склада</span><input class="input" name="default_stock" type="number" min="0" value="<?= (int)($whEdit['default_stock']??0) ?>"><span class="field__hint">Передаётся в instock всех офферов и не включается в суммарный остаток.</span></label>
        </section>
        <fieldset class="warehouse-file-settings">
          <section class="yml-compact-section">
            <h3 class="yml-section-heading">Файл</h3>
            <div class="yml-file-controls">
              <label class="field"><span class="field__label">Тип файла</span><select class="input" name="type"><?php foreach(UploadRepository::TYPES as $key=>$label): ?><option value="<?= e($key) ?>"<?= sel($key,$upEdit['type']??'excel') ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
              <label class="field"><span class="field__label">Получение</span><select class="input" name="fetch_method"><option value="upload"<?= sel('upload',$upEdit['fetch_method']??'upload') ?>>Загрузить файл</option><option value="url"<?= sel('url',$upEdit['fetch_method']??'upload') ?>>По ссылке</option></select></label>
              <label class="field" data-file-url><span class="field__label">Ссылка на файл</span><input class="input" name="source_url" value="<?= e((string)($upEdit['source_url']??'')) ?>" placeholder="https://…"></label>
            </div>
            <div class="yml-saved-file" data-file-upload>
              <div><b><?= e((string)($upEdit['original_filename']??'Файл не загружен')) ?></b><?php if($upEdit!==null): ?><span class="pill <?= ($upEdit['last_status']??'')==='error'?'pill--error':'pill--never' ?>"><?= e(['ok'=>'Загружен','error'=>'Ошибка','running'=>'Загрузка','never'=>'Не загружен'][$upEdit['last_status']??'never']??'—') ?></span><span class="muted small"><?= e(format_datetime($upEdit['last_run_at']??null)) ?></span><?php endif; ?></div>
              <label class="field"><span class="field__label"><?= $upEdit!==null?'Заменить файл':'Выбрать файл' ?></span><input class="input" type="file" name="price_file"><span class="field__hint">Новый файл заменяет прежний. Если файл не выбран, сохранённый файл остаётся.</span></label>
            </div>
          </section>
          <section class="yml-compact-section">
            <h3 class="yml-section-heading">Маппинг полей</h3>
            <p class="card__hint">Для Excel/CSV укажите букву или номер столбца. B,F объединяет столбцы через пробел в заданном порядке. Для YML укажите тег.</p>
            <div class="yml-mapping-grid">
              <?php foreach(UploadRepository::FIELDS as $field=>$label): ?><label class="field yml-mapping-field"><span class="field__label"><?= e(['oldprice'=>'Старая цена','min_price'=>'Мин. цена'][$field]??$label) ?></span><input class="input" name="mapping[<?= e($field) ?>]" value="<?= e((string)($upMapping[$field]??'')) ?>" placeholder="A или B,F"></label><?php endforeach; ?>
            </div>
            <label class="check-inline"><input type="hidden" name="mapping[_merge_skip_empty]" value="0"><input type="checkbox" name="mapping[_merge_skip_empty]" value="1"<?= chk((string)($upMapping['_merge_skip_empty']??'1')!=='0') ?>> Пропускать пустые ячейки</label>
            <p class="field__hint">Если галочка снята, место пустых ячеек сохраняется пробелами при объединении.</p>
          </section>
          <details class="yml-reading-options" id="warehouse-reading-options">
            <summary>Параметры чтения <span class="muted small">Лист <?= (int)($upEdit['sheet_index']??1) ?> · Пропустить <?= (int)($upEdit['skip_rows']??1) ?> строк</span></summary>
            <div class="yml-reading-grid">
              <label class="field"><span class="field__label">Пропустить строк</span><input class="input" name="skip_rows" type="number" min="0" value="<?= (int)($upEdit['skip_rows']??1) ?>"></label>
              <label class="field"><span class="field__label">Лист Excel</span><input class="input" name="sheet_index" type="number" min="1" value="<?= (int)($upEdit['sheet_index']??1) ?>"></label>
              <label class="field"><span class="field__label">Разделитель CSV</span><input class="input" name="csv_delimiter" value="<?= e((string)($upEdit['csv_delimiter']??'auto')) ?>"></label>
              <label class="field"><span class="field__label">Кодировка CSV</span><input class="input" name="csv_encoding" value="<?= e((string)($upEdit['csv_encoding']??'auto')) ?>"></label>
            </div>
            <label class="check-inline"><input type="checkbox" name="skip_hidden"<?= chk((int)($upEdit['skip_hidden']??1)===1) ?>> Не читать скрытые строки и столбцы</label>
          </details>
        </fieldset>
        <div class="yml-form-footer">
          <a class="btn btn--link" href="yml.php?tab=warehouses">К источникам</a>
          <div class="form__actions">
            <?php if(!empty($upEdit['id'])): ?><button class="btn btn--ghost warehouse-import-button" name="save_and_preview" value="1">Проверить маппинг</button><?php endif; ?>
            <button class="btn btn--ghost warehouse-import-button" name="save_and_import" value="1">Сохранить и загрузить</button>
            <button class="btn btn--primary">Сохранить</button>
          </div>
        </div>
      </form>
    </div>
    <?php if(!empty($upEdit['id'])): ?><form method="post" class="warehouse-file-delete" onsubmit="return confirm('Удалить файл и его позиции? Склад сохранится.')"><?= Csrf::field() ?><input type="hidden" name="action" value="upload-delete"><input type="hidden" name="id" value="<?= (int)$upEdit['id'] ?>"><button class="btn btn--link small yml-delete-file">Удалить файл и позиции</button></form><?php endif; ?>
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

