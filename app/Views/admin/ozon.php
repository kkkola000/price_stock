<?php
use App\Support\Csrf;
use App\YmlExport\OzonSettings;
use App\YmlExport\OzonScheduler;
$ozon=OzonSettings::get();$ozonPlan=$_SESSION['ozon_plan']??null;$ozonResult=OzonSettings::lastResult();
if(is_array($ozonPlan)&&(time()-$ozonPlan['created']>600||!hash_equals($ozonPlan['fingerprint'],OzonSettings::fingerprint($ozon)))){unset($_SESSION['ozon_plan']);$ozonPlan=null;}
?>
<div class="card">
  <h2 class="card__title">Ozon Seller API</h2>
  <form method="post" autocomplete="off">
    <?= Csrf::field() ?>
    <div class="grid-2">
      <label class="field"><span class="field__label">Client-Id</span><input class="input" name="client_id" value="<?= e((string)($ozon['client_id']??'')) ?>" inputmode="numeric" required autocomplete="off"></label>
      <label class="field"><span class="field__label">API-Key</span><input class="input" type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?= !empty($ozon['api_key'])?'Ключ сохранён — оставьте пустым, чтобы сохранить его':'Введите API-Key Ozon' ?>"><span class="field__hint">Ключ не показывается после сохранения. Используйте ключ с доступом к складам и обновлению остатков.</span></label>
      <label class="field"><span class="field__label">Источник товаров</span><select class="input" name="feed_id"><?php foreach($feeds as $feed): ?><option value="<?= (int)$feed['id'] ?>"<?= sel((int)$feed['id'],(int)($ozon['feed_id']??0)) ?>><?= e($feed['name']) ?></option><?php endforeach; ?></select><span class="field__hint">Используются выбранные для этой ссылки активные склады и подтверждённые сопоставления.</span></label>

    </div>
    <h3 class="a-subtitle">Склад назначения и расписание</h3>
    <div class="grid-2">
      <label class="field"><span class="field__label">Склад назначения Ozon (куда отправлять остаток)</span><select class="input" name="warehouse_id" id="ozon-warehouse"><option value="0">Сначала загрузите и выберите склад</option><?php foreach(($ozon['warehouses']??[])as $warehouse): ?><option value="<?= (int)$warehouse['warehouse_id'] ?>"<?= sel((int)$warehouse['warehouse_id'],(int)($ozon['warehouse_id']??0)) ?>><?= e($warehouse['name']) ?> · <?= (int)$warehouse['warehouse_id'] ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="field__label">Интервал обновления, секунд</span><input class="input" name="interval_seconds" id="ozon-interval" type="number" min="1" max="2147483647" step="1" required value="<?= (int)($ozon['interval_seconds']??300) ?>"><span class="field__hint">Например: 30 — каждые 30 секунд, 300 — раз в 5 минут. Интервал отсчитывается после завершения отправки.</span></label>
    </div>
    <label class="check-inline"><input type="checkbox" name="auto_enabled"<?= !empty($ozon['auto_enabled'])?' checked':'' ?>> Автоматически обновлять остатки на выбранном складе Ozon</label>
    <p class="field__hint">При смене аккаунта или API-Key выберите склад заново и повторно включите автообновление.</p>
    <div class="form__actions"><button class="btn btn--primary" name="action" value="ozon-save">Сохранить настройки</button><button class="btn btn--ghost" name="action" value="ozon-load">Сохранить и загрузить склады Ozon</button></div>
  </form>
</div>
<div class="card">
  <h2 class="card__title">Состояние автообновления</h2>
  <?php $schedule=OzonSettings::scheduleState();$next=OzonScheduler::nextDue($ozon,$schedule);$sameTarget=($schedule['fingerprint']??'')===OzonSettings::fingerprint($ozon); ?>
  <p><?= !empty($ozon['auto_enabled'])?'Включено · каждые '.(int)$ozon['interval_seconds'].' сек.':'Выключено' ?></p>
  <?php if($sameTarget&&!empty($schedule['last_started'])): ?><p>Последняя попытка: <?= e(date('d.m.Y H:i:s',(int)$schedule['last_started'])) ?> · <?= e(['ok'=>'успешно','error'=>'ошибка','running'=>'выполняется'][$schedule['last_status']??'']??'—') ?></p><?php endif; ?>
  <?php if($next!==null): ?><p>Следующий запуск: <?= $next<=time()?'ожидает обработчика':e(date('d.m.Y H:i:s',$next)) ?></p><?php endif; ?>
  <details><summary>Как запустить автообновление</summary>
    <p>Для интервала в секундах запустите постоянный обработчик на сервере:</p>
    <pre class="feed-preview">php <?= e(APP_ROOT.'/bin/ozon.php') ?> --watch</pre>
    <p class="field__hint">Для постоянной работы используйте менеджер процессов сервера. Обработчик берёт актуальные остатки из базы; импорт прайсов настраивается отдельно. Для разовой проверки расписания: <code>php <?= e(APP_ROOT.'/bin/ozon.php') ?> --once</code>.</p>
    <p class="field__hint">Общий планировщик импортов также проверяет расписание Ozon. Если он запускается раз в минуту, интервалы меньше минуты обеспечит только постоянный обработчик. После ошибок применяется пауза не менее 60 секунд.</p>
  </details>
</div>
<div class="card">
  <h2 class="card__title">Обновление остатков</h2>
  <p>Артикул <code>offer_id</code> — из файла склада. Остаток — с витрины, с учётом числовых порогов, текстовых соответствий и резервного значения. При нескольких исходных складах используется приоритетный.</p>
  <p class="field__hint">Нулевые остатки отправляются для обнуления на Ozon, даже если в YML включён фильтр нулей. Товары вне подготовленного списка не изменяются. Доступна ручная отправка и автообновление по расписанию. Сохранение настроек и загрузка списка складов не выполняют немедленную отправку.</p>
  <form method="post"><?= Csrf::field() ?><button class="btn btn--ghost" name="action" value="ozon-preview">Подготовить обновление</button></form>
  <?php if(is_array($ozonPlan)): ?>
    <h3 class="a-subtitle">Проверка перед отправкой</h3>
    <p>Склад: <b><?= e((string)($ozon['warehouse_name']??'')) ?></b> · <?= (int)($ozon['warehouse_id']??0) ?>. Позиций: <b><?= count($ozonPlan['rows']) ?></b>.</p>
    <div class="table-wrap"><table class="table table--compact"><thead><tr><th>Артикул / offer_id</th><th>Остаток</th><th>Склад Ozon / warehouse_id</th></tr></thead><tbody><?php foreach(array_slice($ozonPlan['rows'],0,100)as $row): ?><tr><td><?= e($row['offer_id']) ?></td><td><?= (int)$row['stock'] ?></td><td><?= (int)$row['warehouse_id'] ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php if(count($ozonPlan['rows'])>100): ?><p class="muted small">Показаны первые 100 позиций. Будет отправлен весь подготовленный список.</p><?php endif; ?>
    <form method="post" class="form__actions"><?= Csrf::field() ?><input type="hidden" name="plan_id" value="<?= e($ozonPlan['id']) ?>"><button class="btn btn--primary" name="action" value="ozon-send">Отправить остатки в Ozon</button><span class="muted small">Предпросмотр действует 10 минут. При изменении данных потребуется повторная подготовка.</span></form>
  <?php endif; ?>
</div>
<?php if(is_array($ozonResult)): ?>
<div class="card">
  <h2 class="card__title">Последняя отправка</h2>
  <p><?= ($ozonResult['source']??'manual')==='auto'?'Автоматическая отправка':'Ручная отправка' ?></p>
  <p>Client-Id: <?= e((string)($ozonResult['client_id']??'—')) ?> · Склад: <?= e((string)($ozonResult['warehouse_name']??'—')) ?></p>
  <p><?= e((string)$ozonResult['at']) ?> · Подтверждено: <?= (int)$ozonResult['success'] ?> · Не подтверждено: <?= (int)$ozonResult['failed'] ?> · Не отправлено: <?= (int)$ozonResult['not_sent'] ?></p>
  <?php if((int)$ozonResult['failed']>count($ozonResult['errors']??[])): ?><p class="muted small">Подробности приведены для первых ошибок; общий счётчик включает все неподтверждённые позиции.</p><?php endif; ?>
  <?php foreach(($ozonResult['errors']??[])as $error): ?><p class="error-text"><b><?= e($error['offer_id']) ?></b>: <?= e($error['message']) ?></p><?php endforeach; ?>
  <p class="field__hint">При сетевой ошибке результат части запроса может быть неизвестен. Немедленный повтор не выполняется. Если автообновление включено, следующая попытка произойдёт по расписанию с паузой не менее 60 секунд после ошибки.</p>
</div>
<?php endif; ?>
