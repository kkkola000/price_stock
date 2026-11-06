<?php
/** @var array<string,mixed>|null $source */
/** @var array<string,mixed>|null $old */
use App\Models\SourceRepository;
use App\Support\Csrf;

$isNew = $source === null;
$mapping = $source['mapping_array'] ?? [];

/** Значение поля: сначала из формы после ошибки, затем из БД, затем по умолчанию. */
$val = static function (string $key, mixed $default = '') use ($old, $source): mixed {
    if (is_array($old) && array_key_exists($key, $old)) {
        return $old[$key];
    }
    if (is_array($source) && array_key_exists($key, $source) && $source[$key] !== null) {
        return $source[$key];
    }
    return $default;
};

$mapVal = static function (string $key, string $default = '') use ($old, $mapping): string {
    if (is_array($old) && isset($old['mapping'][$key])) {
        return (string) $old['mapping'][$key];
    }
    return (string) ($mapping[$key] ?? $default);
};

$checked = static function (string $key, bool $default) use ($old, $source): bool {
    if (is_array($old)) {
        return isset($old[$key]);
    }
    if (is_array($source)) {
        return (int) $source[$key] === 1;
    }
    return $default;
};

$type = (string) $val('type', 'excel');
$fetchMethod = (string) $val('fetch_method', 'upload');
$ymlDefaults = SourceRepository::defaultYmlMapping();
$ymlDefaults['purchase_price'] = '';
?>
<div class="yml-source-form-heading toolbar">
  <a class="breadcrumbs" href="index.php">← Источники</a>
  <?php if(!$isNew): ?><a class="btn btn--ghost" href="index.php?page=source-preview&id=<?= (int)$source['id'] ?>">Проверить маппинг</a><?php endif; ?>
</div>
<form class="form card yml-dense admin-source-form" method="post" action="index.php?page=source-save" enctype="multipart/form-data" id="js-source-form">
  <?= Csrf::field() ?><?php if(!$isNew): ?><input type="hidden" name="id" value="<?= (int)$source['id'] ?>"><?php endif; ?>
  <section class="yml-compact-section">
    <h2 class="yml-section-heading">Источник</h2>
    <div class="admin-source-basics">
      <label class="check-inline"><input type="checkbox" name="is_active"<?= $checked('is_active',true)?' checked':'' ?>> Активен</label>
      <label class="field"><span class="field__label">Название поставщика <b class="req">*</b></span><input class="input" name="name" required maxlength="190" value="<?= e($val('name')) ?>" placeholder="Название поставщика"></label>
      <label class="field"><span class="field__label">Тип файла</span><select class="input" name="type" id="js-type"><?php foreach(SourceRepository::TYPES as $key=>$label): ?><option value="<?= e($key) ?>"<?= $type===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="field__label">Получение</span><select class="input" name="fetch_method" id="js-fetch"><?php foreach(SourceRepository::FETCH_METHODS as $key=>$label): ?><option value="<?= e($key) ?>"<?= $fetchMethod===$key?' selected':'' ?>><?= e($key==='url'?'По ссылке':'Загрузить файл') ?></option><?php endforeach; ?></select></label>
    </div>
    <div data-fetch="upload" class="js-fetch-block yml-source-file">
      <label class="field"><span class="field__label">Файл прайса</span><input class="input" type="file" name="price_file" accept=".xlsx,.xlsm,.xls,.csv,.txt,.tsv,.xml,.yml"><span class="field__hint">Excel: .xlsx и .xls; CSV: .csv/.txt/.tsv; YML: .xml/.yml. Новый файл заменяет текущий. Без нового файла сохраняется прежний.</span></label>
      <?php if(!$isNew&&!empty($source['file_path'])): ?><span class="small muted">Загружен: <?= e($source['original_filename']?:$source['file_path']) ?> · <?= e(format_datetime($source['updated_at'])) ?></span><?php endif; ?>
    </div>
    <div data-fetch="url" class="js-fetch-block yml-source-url">
      <label class="field"><span class="field__label">Ссылка на прайс</span><input class="input" type="url" name="source_url" maxlength="1000" value="<?= e($val('source_url')) ?>" placeholder="https://supplier.example/price.xlsx"><span class="field__hint">Поддерживаются прямые ссылки, Google Таблицы и Диск, Яндекс Документы и Диск. Документ должен быть доступен по ссылке на просмотр.</span></label>
      <div class="admin-source-schedule">
        <label class="check-inline"><input type="checkbox" name="auto_import"<?= $checked('auto_import',false)?' checked':'' ?>> Автоимпорт</label>
        <label class="field"><span class="field__label">Интервал, минут</span><input class="input" type="number" name="import_interval_minutes" min="15" step="15" value="<?= e($val('import_interval_minutes',1440)) ?>"><span class="field__hint">Минимум 15 минут. 1440 — раз в сутки. Запускается общим планировщиком источников.</span></label>
        <?php if(!$isNew): ?><span class="small muted">Обновлён: <?= e(format_datetime($source['last_success_at']??null)) ?><?php if($source['fetch_method']==='url'&&(int)$source['auto_import']===1): ?> · Следующее: <?= e(SourceRepository::nextRunAt($source)) ?><?php endif; ?></span><?php endif; ?>
      </div>
    </div>
  </section>
  <section class="yml-compact-section">
    <h2 class="yml-section-heading">Маппинг полей</h2>
    <p class="card__hint js-type-block" data-type="excel csv">Укажите букву или номер столбца. B,F объединяет выбранные столбцы через пробел в указанном порядке; можно указать B,F,G. Все поля необязательные. Обычная цена, промоцена и остаток показываются как в прайсе.</p>
    <p class="card__hint js-type-block" data-type="yml">Укажите имена тегов внутри offer, например name, price. Для атрибутов используйте @id, @available. Все поля необязательные.</p>
    <div class="yml-mapping-grid">
      <?php foreach(SourceRepository::FIELDS as $field=>$meta): ?>
      <label class="field yml-mapping-field"><span class="field__label"><?= e(['name'=>'Название','sku'=>'Артикул','promo_price'=>'Промоцена','stock'=>'Остаток','image'=>'Фото','currency'=>'Валюта'][$field]??$meta['label']) ?></span>
        <input class="input js-map-input" type="text" name="mapping[<?= e($field) ?>]" data-placeholder-table="A или B,F" data-placeholder-yml="<?= e($ymlDefaults[$field]??'') ?>" data-default-yml="<?= e($ymlDefaults[$field]??'') ?>" value="<?= e($mapVal($field,$type==='yml'?($ymlDefaults[$field]??''):'')) ?>">
        <?php if($field==='purchase_price'): ?><span class="field__hint">Закупочная цена преобразуется в число. Она доступна только в заказе после ввода кода администратора.</span><?php endif; ?>
      </label>
      <?php endforeach; ?>
    </div>
    <div class="admin-source-mapping-footer">
      <label class="check-inline js-type-block" data-type="excel csv"><input type="hidden" name="mapping[_merge_skip_empty]" value="0"><input type="checkbox" name="mapping[_merge_skip_empty]" value="1"<?= $mapVal('_merge_skip_empty','1')!=='0'?' checked':'' ?>> Пропускать пустые ячейки</label>
      <label class="field"><span class="field__label">Валюта по умолчанию</span><input class="input" name="currency_code" maxlength="16" list="currency-list" value="<?= e($val('currency_code','')) ?>" placeholder="<?= e((string)\App\Support\Config::get('default_currency','RUB')) ?>"><span class="field__hint">Можно указать код RUB, KZT или своё обозначение. Используется, если валюта не задана в файле. Пустое значение использует настройку сервиса.</span></label>
      <datalist id="currency-list"><?php foreach(['RUB','KZT','USD','EUR','BYN','UAH','UZS'] as $currency): ?><option value="<?= $currency ?>"><?php endforeach; ?></datalist>
    </div>
  </section>
  <details class="yml-reading-options js-type-block" data-type="excel csv" id="source-reading-options">
    <summary>Параметры чтения <span class="muted small">Пропустить <?= (int)$val('skip_rows',1) ?> строк · Лист <?= (int)$val('sheet_index',1) ?></span></summary>
    <div class="yml-reading-grid">
      <label class="field"><span class="field__label">Пропустить строк</span><input class="input" type="number" name="skip_rows" min="0" max="100" value="<?= e($val('skip_rows',1)) ?>"></label>
      <label class="field js-type-field" data-type="excel"><span class="field__label">Лист Excel</span><input class="input" type="number" name="sheet_index" min="1" max="50" value="<?= e($val('sheet_index',1)) ?>"></label>
      <label class="field js-type-field" data-type="csv"><span class="field__label">Разделитель CSV</span><select class="input" name="csv_delimiter"><?php foreach(['auto'=>'Автоматически',';'=>'Точка с запятой',','=>'Запятая','tab'=>'Табуляция','|'=>'Вертикальная черта'] as $key=>$label): ?><option value="<?= e($key) ?>"<?= (string)$val('csv_delimiter','auto')===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label class="field js-type-field" data-type="csv"><span class="field__label">Кодировка CSV</span><select class="input" name="csv_encoding"><?php foreach(['auto'=>'Автоматически','UTF-8'=>'UTF-8','CP1251'=>'Windows-1251','KOI8-R'=>'KOI8-R'] as $key=>$label): ?><option value="<?= $key ?>"<?= (string)$val('csv_encoding','auto')===$key?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
    </div>
    <label class="check-inline js-type-field" data-type="excel"><input type="checkbox" name="skip_hidden"<?= $checked('skip_hidden',true)?' checked':'' ?>> Не читать скрытые строки и столбцы</label>
  </details>
  <div class="yml-form-footer">
    <span class="small muted"><?= !$isNew?\App\View\Catalog::foundLabel((int)$source['products_count']).' · '.e(['ok'=>'Импорт завершён','error'=>'Ошибка импорта','running'=>'Импорт выполняется','never'=>'Ещё не импортирован'][$source['last_status']]??$source['last_status']):'Новый источник' ?></span>
    <div class="form__actions"><button class="btn btn--ghost" type="submit" name="save_and_import" value="1">Сохранить и импортировать</button><button class="btn btn--primary" type="submit">Сохранить</button><a class="btn btn--link" href="index.php">Отмена</a></div>
  </div>
</form>

<?php if(!$isNew): ?><form method="post" action="index.php?page=source-delete" onsubmit="return confirm('Удалить источник вместе со всеми его товарами?')" class="admin-source-delete"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$source['id'] ?>"><button class="btn btn--link yml-delete-file">Удалить источник</button></form><?php endif; ?>
