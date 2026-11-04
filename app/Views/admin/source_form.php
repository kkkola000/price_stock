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
<form class="form" method="post" action="index.php?page=source-save" enctype="multipart/form-data" id="js-source-form">
  <?= Csrf::field() ?>
  <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $source['id'] ?>"><?php endif; ?>

  <div class="card">
    <h2 class="card__title">Основное</h2>

    <label class="field">
      <span class="field__label">Название поставщика <b class="req">*</b></span>
      <input class="input" type="text" name="name" required maxlength="190"
             value="<?= e($val('name')) ?>" placeholder="Например: ООО «Ромашка»">
    </label>

    <div class="grid-2">
      <label class="field">
        <span class="field__label">Тип источника <b class="req">*</b></span>
        <select class="input" name="type" id="js-type">
          <?php foreach (SourceRepository::TYPES as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field">
        <span class="field__label">Способ получения <b class="req">*</b></span>
        <select class="input" name="fetch_method" id="js-fetch">
          <?php foreach (SourceRepository::FETCH_METHODS as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $fetchMethod === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div data-fetch="upload" class="js-fetch-block">
      <label class="field">
        <span class="field__label">Файл прайса</span>
        <input class="input" type="file" name="price_file" accept=".xlsx,.xlsm,.xls,.csv,.txt,.tsv,.xml,.yml">
        <span class="field__hint">
          Excel — .xlsx и .xls (включая Excel 97–2003), CSV — .csv/.txt/.tsv, YML — .xml/.yml.
          <?php if (!$isNew && !empty($source['file_path'])): ?>
            Сейчас загружен: <b><?= e($source['original_filename'] ?: $source['file_path']) ?></b>
            (<?= e(format_datetime($source['updated_at'])) ?>). Новый файл заменит текущий.
          <?php endif; ?>
        </span>
      </label>
    </div>

    <div data-fetch="url" class="js-fetch-block">
      <label class="field">
        <span class="field__label">Ссылка на файл</span>
        <input class="input" type="url" name="source_url" maxlength="1000"
               value="<?= e($val('source_url')) ?>" placeholder="https://postavshik.ru/price.xlsx">
        <span class="field__hint">
          Файл скачивается автоматически по расписанию и при ручном запуске импорта.
          Кроме прямых ссылок понимаются ссылки на <b>Google Таблицы</b>, <b>Google Диск</b>,
          <b>Яндекс Документы</b> и <b>Яндекс Диск</b> — вставляйте их как есть, сервис сам
          подставит прямую ссылку на файл. Документ должен быть открыт по ссылке на просмотр.
          <?php if (!$isNew && $val('fetch_method') === 'url' && ($service = \App\Import\LinkResolver::service((string) $val('source_url'))) !== null): ?>
            <br><b>Распознано: <?= e($service) ?>.</b>
          <?php endif; ?>
        </span>
      </label>

      <div class="grid-2">
        <label class="check-inline">
          <input type="checkbox" name="auto_import" <?= $checked('auto_import', false) ? 'checked' : '' ?>>
          <span>Импортировать автоматически</span>
        </label>

        <label class="field">
          <span class="field__label">Интервал обновления, минут</span>
          <input class="input" type="number" name="import_interval_minutes" min="15" step="15"
                 value="<?= e($val('import_interval_minutes', 1440)) ?>">
          <span class="field__hint">1440 = раз в сутки. Запускается заданием cron (см. README).</span>
        </label>
      </div>
    </div>

    <label class="check-inline">
      <input type="checkbox" name="is_active" <?= $checked('is_active', true) ? 'checked' : '' ?>>
      <span>Источник активен (товары показываются на витрине)</span>
    </label>
  </div>

  <div class="card js-type-block" data-type="excel csv">
    <h2 class="card__title">Разбор файла</h2>
    <div class="grid-3">
      <label class="field">
        <span class="field__label">Пропустить строк сверху</span>
        <input class="input" type="number" name="skip_rows" min="0" max="100" value="<?= e($val('skip_rows', 1)) ?>">
        <span class="field__hint">Шапка таблицы. Обычно 1.</span>
      </label>

      <label class="field js-type-field" data-type="excel">
        <span class="field__label">Номер листа</span>
        <input class="input" type="number" name="sheet_index" min="1" max="50" value="<?= e($val('sheet_index', 1)) ?>">
        <span class="field__hint">1 — первый лист книги.</span>
      </label>

      <label class="field js-type-field" data-type="csv">
        <span class="field__label">Разделитель</span>
        <select class="input" name="csv_delimiter">
          <?php foreach (['auto' => 'Определить автоматически', ';' => 'Точка с запятой ( ; )', ',' => 'Запятая ( , )', 'tab' => 'Табуляция', '|' => 'Вертикальная черта ( | )'] as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= (string) $val('csv_delimiter', 'auto') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field js-type-field" data-type="csv">
        <span class="field__label">Кодировка</span>
        <select class="input" name="csv_encoding">
          <?php foreach (['auto' => 'Определить автоматически', 'UTF-8' => 'UTF-8', 'CP1251' => 'Windows-1251', 'KOI8-R' => 'KOI8-R'] as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= (string) $val('csv_encoding', 'auto') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div class="js-type-field" data-type="excel">
      <label class="check-inline">
        <input type="checkbox" name="skip_hidden" <?= $checked('skip_hidden', true) ? 'checked' : '' ?>>
        <span>Не импортировать скрытые строки и столбцы</span>
      </label>
      <span class="field__hint">
        Поставщики часто прячут в книге неактуальные позиции и старые колонки.
        С галочкой такие строки пропускаются, а скрытые столбцы читаются как пустые —
        буквы столбцов в маппинге при этом не сдвигаются.
      </span>
    </div>
  </div>

  <div class="card">
    <h2 class="card__title">Маппинг полей</h2>
    <p class="card__hint js-type-block" data-type="excel csv">
      Укажите, из какого столбца брать каждое поле: буква (<b>A</b>, <b>B</b>, <b>AA</b>) или номер (<b>1</b>, <b>2</b>).
      <br><b>Столбцы для объединения:</b> укажите буквы столбцов через запятую в нужном порядке. Значения объединяются через пробел.
      Например, <code>B,F</code> → Коляска ANEX AX-123. Можно указать <code>B,F,G</code>. Пропуск пустых ячеек настраивается галочкой ниже.
      <br>Заполнять все поля не обязательно — оставьте пустым то, чего нет в прайсе.
      Наличие показывается на витрине дословно, как написано у поставщика.
    </p>
    <p class="card__hint js-type-block" data-type="yml">
      Укажите названия тегов внутри <code>&lt;offer&gt;</code>. Для атрибутов используйте префикс <code>@</code>
      (например, <code>@available</code> или <code>@id</code>). Заполнять все поля не обязательно.
    </p>

    <label class="check-inline js-type-block" data-type="excel csv">
      <input type="hidden" name="mapping[_merge_skip_empty]" value="0">
      <input type="checkbox" name="mapping[_merge_skip_empty]" value="1"<?= $mapVal('_merge_skip_empty', '1') !== '0' ? ' checked' : '' ?>>
      Пропускать пустые ячейки при объединении
    </label>
    <p class="card__hint js-type-block" data-type="excel csv">Если галочка снята, место пустых ячеек сохраняется разделителями-пробелами.</p>

    <div class="grid-3">
      <?php foreach (SourceRepository::FIELDS as $field => $meta): ?>
        <label class="field">
          <span class="field__label"><?= e($meta['label']) ?></span>
          <input class="input js-map-input"
                 type="text"
                 name="mapping[<?= e($field) ?>]"
                 data-placeholder-table="B или B,F для объединения"
                 data-placeholder-yml="<?= e($ymlDefaults[$field] ?? '') ?>"
                 data-default-yml="<?= e($ymlDefaults[$field] ?? '') ?>"
                 value="<?= e($mapVal($field, $type === 'yml' ? ($ymlDefaults[$field] ?? '') : '')) ?>">
        </label>
      <?php endforeach; ?>
    </div>

    <div class="grid-3">
      <label class="field">
        <span class="field__label">Валюта источника</span>
        <input class="input" type="text" name="currency_code" maxlength="16" list="currency-list"
               value="<?= e($val('currency_code', '')) ?>" placeholder="например, KZT">
        <datalist id="currency-list">
          <option value="RUB"><option value="KZT"><option value="USD">
          <option value="EUR"><option value="BYN"><option value="UAH"><option value="UZS">
        </datalist>
        <span class="field__hint">
          Код (KZT, USD) или своё обозначение (тг, сум). Используется, когда валюты нет в самом файле.
          Если оставить пустым, берётся валюта из config.php.
        </span>
      </label>
    </div>

  </div>

  <div class="form__actions">
    <button class="btn btn--primary" type="submit">Сохранить</button>
    <button class="btn btn--ghost" type="submit" name="save_and_import" value="1">Сохранить и импортировать</button>
    <?php if (!$isNew): ?>
      <a class="btn btn--ghost" href="index.php?page=source-preview&id=<?= (int) $source['id'] ?>">Проверить маппинг</a>
    <?php endif; ?>
    <a class="btn btn--link" href="index.php">Отмена</a>
  </div>
</form>
