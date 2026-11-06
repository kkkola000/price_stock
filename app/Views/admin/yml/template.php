<?php
use App\Support\Csrf;
use App\Import\ColumnRef;
use App\YmlExport\{UploadRepository,WarehouseRepository,FeedRepository,Matcher,FeedBuilder};
?>

  <!-- ================= Настройка фида ================= -->
  <form method="post" id="yml-template-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="settings-save">
    <input type="hidden" name="settings_panel" value="<?= e($_SESSION['yml_settings_panel']??'structure') ?>">

    <?php foreach(['shop_name','shop_company','shop_url','currency'] as $field): ?><input type="hidden" name="<?= e($field) ?>" value="<?= e((string)$settings[$field]) ?>"><?php endforeach; ?>
    <div class="card">
      <div class="toolbar"><h2 class="card__title">Значения для шаблона</h2><button type="button" class="btn btn--primary" id="template-add-variable">+ Добавить значение</button></div>
      <p class="card__hint">Задайте переменные и вставляйте их в структуру XML.</p>
      <div class="table-wrap"><table class="table table--compact" id="template-variables"><thead><tr><th>Значение / переменная</th><th>Источник</th><th>Поле / константа</th><th></th></tr></thead><tbody>
        <?php foreach($settings['template_config']['variables'] as $i=>$variable) {require APP_ROOT.'/app/Views/admin/template_variable.php';} ?>
      </tbody></table></div>
      <p class="field__hint">Источники: Файл, Витрина, Константа. «Текущий склад» доступен только в повторении складов. Системная переменная {{дата_фида}} подставляет дату сборки.</p>
      <template id="template-variable-row"><?php $i='__INDEX__';$variable=['name'=>'','source'=>['type'=>'constant','ref'=>'']];require APP_ROOT.'/app/Views/admin/template_variable.php'; ?></template>
    </div>
    <div class="card">
      <h2 class="card__title">Структура YML</h2>
      <div class="form__actions template-toolbar">
        <select class="input" id="template-insert-variable" aria-label="Переменная для вставки"><option value="">Выберите переменную</option></select>
        <button type="button" class="btn btn--ghost" id="template-insert">+ Вставить переменную</button>
        <button type="button" class="btn btn--ghost" data-template-loop="warehouse">+ Повторение по складам</button>
        <button type="button" class="btn btn--ghost" data-template-loop="product">+ Повторение товаров</button>
      </div>
      <p class="field__hint">Вставка в место курсора. Выделенный текст можно обернуть повторением. Для каждого товара нужен один блок повторения; склады — внутри него.</p>
      <div class="template-editor">
        <pre class="template-lines" aria-hidden="true"></pre><pre class="template-highlight" aria-hidden="true"></pre>
        <textarea name="xml_template" id="xml-template" spellcheck="false" wrap="off" aria-label="Структура YML"><?= e($settings['template_config']['text']) ?></textarea>
      </div>
      <p class="field__hint">Проверка XML и переменных выполняется кнопкой «Проверить шаблон». Данные подставляются с безопасным XML-экранированием.</p>
    </div>

    <details class="card yml-advanced" id="yml-stock-settings"<?= (($_SESSION['yml_settings_panel']??'')==='stock'||isset($_GET['add_stock']))?' open':'' ?>>
      <summary>Остатки и соответствия</summary>
    <div class="yml-advanced-section">
      <h2 class="card__title">Остатки для выгрузки</h2>
      <p class="card__hint">Основной источник — <b>витрина, сопоставленный товар</b>. Число передаётся напрямую; текстовый остаток — по таблице соответствий ниже.</p>
      <p class="field__hint">Если остаток витрины не определён: остаток из файла склада → соответствия текста файла → запасное значение. Виртуальные склады используют постоянный остаток из своих настроек.</p>
      <p class="field__hint">Склады выбираются для каждой ссылки в настройках ссылки YML. Блок «Для каждого склада» повторяется для этих складов.</p>
      <?php $numericRule=(array)($settings['outlets_array']['numeric_stock_rule']??[]); ?>
      <h3 class="a-subtitle">Правило для числового остатка с витрины</h3>
      <label class="check-inline"><input type="checkbox" name="numeric_stock_enabled"<?= chk(!empty($numericRule['enabled'])) ?>> Заменять числовой остаток по порогу</label>
      <div class="grid-2">
        <label class="field"><span class="field__label">Если остаток ≥</span><input class="input" type="number" min="0" max="2147483647" step="1" name="numeric_stock_threshold" value="<?= e((string)($numericRule['threshold']??5)) ?>"></label>
        <label class="field"><span class="field__label">Передавать в YML</span><input class="input" type="number" min="0" max="2147483647" step="1" name="numeric_stock_value" value="<?= e((string)($numericRule['value']??10)) ?>"></label>
      </div>
      <label class="check-inline"><input type="checkbox" name="numeric_stock_lower_enabled"<?= chk(!empty($numericRule['lower_enabled'])) ?>> Заменять малый числовой остаток</label>
      <div class="grid-2">
        <label class="field"><span class="field__label">Если остаток ≤</span><input class="input" type="number" min="0" max="2147483647" step="1" name="numeric_stock_lower_threshold" value="<?= e((string)($numericRule['lower_threshold']??4)) ?>"></label>
        <label class="field"><span class="field__label">Передавать в YML</span><input class="input" type="number" min="0" max="2147483647" step="1" name="numeric_stock_lower_value" value="<?= e((string)($numericRule['lower_value']??0)) ?>"></label>
      </div>
      <p class="field__hint">Например: остаток ≥ 5 → передавать 10, остаток ≤ 4 → передавать 0. Диапазоны не должны пересекаться. Значения между диапазонами передаются как есть. Остаток на витрине не меняется; текстовые соответствия, файл склада и виртуальные склады обрабатываются отдельно. Замена применяется до фильтра нулевых остатков.</p>


    </div>

    <div class="card table-wrap">
      <h2 class="card__title" style="padding:18px 18px 0">Соответствие остатков</h2>
      <p class="card__hint" style="padding:0 18px">
        Если поставщик передаёт остаток текстом («Более 5», «мало», «в наличии»), здесь задаётся,
        какое число уйдёт в фид в атрибут instock. Совпадение — сначала точное, затем по вхождению,
        регистр не важен. Числа из файла передаются как есть, без соответствий.
      </p>
      <table class="table table--compact">
        <thead>
          <tr><th>Остаток на витрине / в файле</th><th style="width:170px">Передавать количество</th><th style="width:90px"></th></tr>
        </thead>
        <tbody>
        <?php if ($stockMapRows === []): ?>
          <tr><td colspan="3" class="muted">Соответствий пока нет — добавьте первое.</td></tr>
        <?php endif; ?>
        <?php foreach ($stockMapRows as $i => $stockRow): ?>
          <tr>
            <td><input class="input" name="stock_pattern[<?= $i ?>]" maxlength="190" value="<?= e((string) $stockRow['pattern']) ?>" placeholder="Более 5"></td>
            <td><input class="input" type="number" min="0" name="stock_qty[<?= $i ?>]" value="<?= (int) $stockRow['qty'] ?>"></td>
            <td><button type="button" class="btn btn--small btn--danger" data-remove-stock aria-label="Удалить соответствие">×</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="grid-3" style="padding:0 18px">
        <label class="field"><span class="field__label">Если соответствие не найдено — передавать</span>
          <input class="input" type="number" min="0" name="stock_fallback" value="<?= (int) ($settings['stock_fallback'] ?? 0) ?>">
          <span class="field__hint">Например 0: неизвестный текстовый остаток уйдёт в фид нулём.</span>
        </label>
      </div>
      <div class="form__actions" style="padding:0 18px 18px">
        <button type="button" class="btn btn--ghost" id="template-add-stock">+ Добавить соответствие</button>
      </div>
    </div>

    </details>
    <div class="card">
      <h2 class="card__title">Фильтры и сохранение</h2>
      <label class="check-inline"><input type="checkbox" name="only_confirmed"<?= chk((int) $settings['only_confirmed'] === 1) ?>> Включать в фид только подтверждённые сопоставления</label>
      <label class="check-inline"><input type="checkbox" name="skip_zero_stock"<?= chk((int) $settings['skip_zero_stock'] === 1) ?>> Исключать офферы с нулевым суммарным остатком <span class="muted small">(виртуальные склады в сумме не учитываются)</span></label>
      <label class="check-inline"><input type="checkbox" name="oldprice_only_higher"<?= chk((int) $settings['oldprice_only_higher'] === 1) ?>> oldprice выводить, только если больше price</label>
      <div class="form__actions">
        <button class="btn btn--primary">Сохранить настройки</button>
        <button class="btn btn--ghost" name="check_template" value="1">Проверить шаблон</button>
      </div>
    </div>
  </form>

  <div class="card">
    <h2 class="card__title">Предпросмотр готового XML</h2>
    <div class="form__actions">
      <label>Фид <select class="input" name="preview_feed_id" form="yml-template-form"><?php foreach($feeds as $feed): ?><option value="<?= (int)$feed['id'] ?>"<?= sel((int)$feed['id'],(int)($templatePreview['feed']??$previewFeedId)) ?>><?= e($feed['name']) ?></option><?php endforeach; ?></select></label>
      <label>Товар для примера <select class="input" name="preview_product_id" form="yml-template-form"><option value="0">Все товары выбранного фида</option><?php foreach(\App\Models\ProductRepository::search(['per_page'=>96])['items'] as $product): ?><option value="<?= (int)$product['id'] ?>"<?= sel((int)$product['id'],(int)($templatePreview['product']??0)) ?>><?= e($product['sku'].' — '.$product['name']) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn--ghost" name="check_template" value="1" form="yml-template-form">Проверить шаблон</button>
    </div>
    <?php if($templatePreview!==null): ?>
      <p class="<?= $templatePreview['offers']>0?'template-status':'muted' ?>"><?= $templatePreview['offers']>0?'Шаблон корректен · Товаров: '.(int)$templatePreview['offers']:'Шаблон корректен, но товары не попали в выгрузку.' ?></p>
      <?php if(!empty($templatePreview['diagnostic'])): ?>
        <div class="alert alert--warning"><?= e($templatePreview['diagnostic']['message']) ?><br><span class="small">Выбрано складов: <?= (int)$templatePreview['diagnostic']['stats']['selected'] ?> · активных: <?= (int)$templatePreview['diagnostic']['stats']['active'] ?> · позиций: <?= (int)$templatePreview['diagnostic']['stats']['items'] ?> · подтверждено: <?= (int)$templatePreview['diagnostic']['stats']['confirmed'] ?></span></div>
      <?php endif; ?>
      <?php if(!empty($templatePreview['truncated'])): ?><p class="muted small">Показаны первые 16 КБ XML. Для подробного примера выберите один товар.</p><?php endif; ?>
      <pre class="feed-preview"><?= e($templatePreview['xml']) ?></pre>
    <?php else: ?><p class="field__hint">Нажмите «Проверить шаблон»: предпросмотр использует текущий текст, переменные и соответствия остатков без сохранения настроек.</p><?php endif; ?>
  </div>

