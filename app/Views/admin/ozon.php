<?php
use App\Support\Csrf;
use App\YmlExport\{OzonSettings,OzonScheduler,CycleSettings};
$unifiedSchedule=CycleSettings::configured();
$ozon=OzonSettings::get();$ozonPlan=$_SESSION['ozon_plan']??null;$ozonResult=OzonSettings::lastResult();
if(is_array($ozonPlan)&&(time()-$ozonPlan['created']>600||!hash_equals($ozonPlan['fingerprint'],OzonSettings::fingerprint($ozon)))){unset($_SESSION['ozon_plan']);$ozonPlan=null;}
$apiConfigured=!empty($ozon['api_key'])&&!empty($ozon['client_id'])&&!empty($ozon['warehouse_id']);
?>
<div class="toolbar yml-api-heading"><h2 class="a-subtitle">Ozon API <span class="pill <?= $apiConfigured?'pill--ok':'pill--never' ?>"><?= $apiConfigured?'Настроено':'Не настроено' ?></span></h2></div>
<div class="card yml-dense yml-api-strip">
  <label class="check-inline"><input type="checkbox" form="ozon-settings" name="auto_enabled"<?= !empty($ozon['auto_enabled'])?' checked':'' ?>> <?= $unifiedSchedule?'Отправка в общем цикле':'Автоматическое обновление остатков' ?></label>
  <a href="yml.php?tab=schedule">Общее расписание<?= $unifiedSchedule?' · '.(int)CycleSettings::get()['interval_seconds'].' сек.':'' ?> ↗</a>
  <p class="field__hint">Отправка выполняется общим планировщиком после обновления файлов и сборки YML. Галочка применяется после сохранения настроек; сохранение не отправляет остатки.</p>
</div>
<div class="yml-api-layout">
  <section>
    <div class="card yml-dense">
      <form method="post" id="ozon-settings" autocomplete="off">
        <?= Csrf::field() ?>
        <section class="yml-compact-section">
          <h3 class="yml-section-heading">Подключение</h3>
          <div class="yml-two-fields">
            <label class="field"><span class="field__label">Client-Id</span><input class="input" name="client_id" value="<?= e((string)$ozon['client_id']) ?>" inputmode="numeric" required autocomplete="off"></label>
            <label class="field"><span class="field__label">API-Key</span><input class="input" type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?= !empty($ozon['api_key'])?'Ключ сохранён':'Введите API-Key' ?>"><span class="field__hint">Оставьте пустым, чтобы сохранить ключ. При смене аккаунта или ключа заново выберите склад и включите отправку.</span></label>
          </div>
        </section>
        <section class="yml-compact-section">
          <h3 class="yml-section-heading">Отправка</h3>
          <div class="yml-two-fields">
            <label class="field"><span class="field__label">Источник товаров</span><select class="input" name="feed_id"><?php foreach($feeds as $feed): ?><option value="<?= (int)$feed['id'] ?>"<?= sel((int)$feed['id'],(int)$ozon['feed_id']) ?>><?= e($feed['name']) ?></option><?php endforeach; ?></select><span class="field__hint">Активные склады выбранной ссылки и подтверждённые сопоставления. Артикул — из файла, остаток — из витрины с правилами преобразования.</span></label>
            <label class="field"><span class="field__label">Склад Ozon</span><select class="input" name="warehouse_id" id="ozon-warehouse"><option value="0">Загрузите и выберите склад</option><?php foreach($ozon['warehouses']??[] as $warehouse): ?><option value="<?= (int)$warehouse['warehouse_id'] ?>"<?= sel((int)$warehouse['warehouse_id'],(int)$ozon['warehouse_id']) ?>><?= e($warehouse['name']) ?> · <?= (int)$warehouse['warehouse_id'] ?></option><?php endforeach; ?></select></label>
            <?php if(!$unifiedSchedule): ?><label class="field"><span class="field__label">Интервал, секунд</span><input class="input" name="interval_seconds" id="ozon-interval" type="number" min="1" max="2147483647" step="1" required value="<?= (int)$ozon['interval_seconds'] ?>"><span class="field__hint">Интервал отсчитывается после завершения. Фактическая частота ограничена общим планировщиком источников.</span></label><?php else: ?><input type="hidden" name="interval_seconds" value="<?= (int)$ozon['interval_seconds'] ?>"><?php endif; ?>
          </div>
        </section>
      </form>
      <div class="yml-form-footer">
        <button class="btn btn--primary" type="submit" form="ozon-settings" name="action" value="ozon-save">Сохранить настройки</button>
        <button class="btn btn--ghost" type="submit" form="ozon-settings" name="action" value="ozon-load">Сохранить и загрузить склады Ozon</button>
      </div>
    </div>
    <div class="card yml-dense">
      <div class="toolbar"><h3 class="yml-section-heading">Проверка перед отправкой</h3><form method="post"><?= Csrf::field() ?><button class="btn btn--ghost" name="action" value="ozon-preview">Подготовить обновление</button></form></div>
      <p class="card__hint">Предпросмотр действует 10 минут. Изменение настроек или остатков требует новой подготовки. Нули передаются в API для обнуления остатков. При отправке будет обработан весь подготовленный список.</p>
      <?php if(is_array($ozonPlan)): ?>
        <p class="yml-preview-count"><?= e((string)$ozon['warehouse_name']) ?> · <b><?= count($ozonPlan['rows']) ?> позиций</b></p>
        <div class="table-wrap yml-api-preview"><table class="table table--compact"><thead><tr><th>Артикул</th><th>Остаток</th><th>Склад Ozon / ID</th></tr></thead><tbody><?php foreach(array_slice($ozonPlan['rows'],0,100) as $row): ?><tr><td><?= e($row['offer_id']) ?></td><td><?= (int)$row['stock'] ?></td><td><?= (int)$row['warehouse_id'] ?></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="yml-form-footer"><span class="muted small">Показано <?= min(100,count($ozonPlan['rows'])) ?> из <?= count($ozonPlan['rows']) ?></span><form method="post"><?= Csrf::field() ?><input type="hidden" name="plan_id" value="<?= e($ozonPlan['id']) ?>"><button class="btn btn--primary" name="action" value="ozon-send">Отправить остатки в Ozon</button></form></div>
      <?php else: ?><span class="muted small">Обновление ещё не подготовлено</span><?php endif; ?>
    </div>
  </section>
  <aside>
    <div class="card yml-dense" id="ozon-last-result">
      <h3 class="yml-section-heading">Последняя отправка</h3>
      <?php if(is_array($ozonResult)): ?>
        <p class="yml-result-date"><?= e((string)$ozonResult['at']) ?></p>
        <dl class="yml-result-list"><div><dt>Подтверждено</dt><dd><?= (int)$ozonResult['success'] ?></dd></div><div><dt>Ошибок</dt><dd><?= (int)$ozonResult['failed'] ?></dd></div><div><dt>Не отправлено</dt><dd><?= (int)$ozonResult['not_sent'] ?></dd></div><div><dt>Режим</dt><dd><?= ($ozonResult['source']??'manual')==='auto'?'Автоматически':'Вручную' ?></dd></div><div><dt>Склад</dt><dd><?= e((string)($ozonResult['warehouse_name']??'—')) ?></dd></div><div><dt>Client-Id</dt><dd><?= e((string)($ozonResult['client_id']??'—')) ?></dd></div></dl>
        <?php foreach($ozonResult['errors']??[] as $error): ?><p class="error-text small"><b><?= e($error['offer_id']) ?></b>: <?= e($error['message']) ?></p><?php endforeach; ?>
        <p class="field__hint">Показаны первые ошибки; счётчики включают все позиции. После сетевого сбоя результат части запроса может быть неизвестен. Автообновление повторит попытку с паузой не менее 60 секунд.</p>
      <?php else: ?><p class="muted small">Отправок ещё не было</p><?php endif; ?>
      <?php if(!$unifiedSchedule): $schedule=OzonSettings::scheduleState();$next=OzonScheduler::nextDue($ozon,$schedule);$sameTarget=($schedule['fingerprint']??'')===OzonSettings::fingerprint($ozon); ?>
        <p class="small"><?= !empty($ozon['auto_enabled'])?'Включено · каждые '.(int)$ozon['interval_seconds'].' сек.':'Автообновление выключено' ?></p>
        <?php if($sameTarget&&!empty($schedule['last_started'])): ?><p class="small">Последняя попытка: <?= e(date('d.m.Y H:i:s',(int)$schedule['last_started'])) ?></p><?php endif; ?>
        <?php if($next!==null): ?><p class="small">Следующий запуск: <?= $next<=time()?'ожидает планировщика':e(date('d.m.Y H:i:s',$next)) ?></p><?php endif; ?>
      <?php endif; ?>
      <a class="small" href="yml.php?tab=schedule">Расписание и статус ↗</a>
    </div>
  </aside>
</div>
