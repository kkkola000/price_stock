<?php
use App\Support\Csrf;
use App\YmlExport\OzonSettings;
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
      <label class="field"><span class="field__label">Склад Ozon для обновления</span><select class="input" name="warehouse_id"><option value="0">Сначала загрузите и выберите склад</option><?php foreach(($ozon['warehouses']??[])as $warehouse): ?><option value="<?= (int)$warehouse['warehouse_id'] ?>"<?= sel((int)$warehouse['warehouse_id'],(int)($ozon['warehouse_id']??0)) ?>><?= e($warehouse['name']) ?> · <?= (int)$warehouse['warehouse_id'] ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="form__actions"><button class="btn btn--primary" name="action" value="ozon-save">Сохранить настройки</button><button class="btn btn--ghost" name="action" value="ozon-load">Сохранить и загрузить склады Ozon</button></div>
  </form>
</div>
<div class="card">
  <h2 class="card__title">Обновление остатков</h2>
  <p>Артикул <code>offer_id</code> — из файла склада. Остаток — с витрины, с учётом числовых порогов, текстовых соответствий и резервного значения. При нескольких исходных складах используется приоритетный.</p>
  <p class="field__hint">Нулевые остатки отправляются для обнуления на Ozon, даже если в YML включён фильтр нулей. Товары вне подготовленного списка не изменяются. Отправка выполняется вручную; сохранение настроек и загрузка складов остатки не меняют.</p>
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
  <p>Client-Id: <?= e((string)($ozonResult['client_id']??'—')) ?> · Склад: <?= e((string)($ozonResult['warehouse_name']??'—')) ?></p>
  <p><?= e((string)$ozonResult['at']) ?> · Подтверждено: <?= (int)$ozonResult['success'] ?> · Не подтверждено: <?= (int)$ozonResult['failed'] ?> · Не отправлено: <?= (int)$ozonResult['not_sent'] ?></p>
  <?php if((int)$ozonResult['failed']>count($ozonResult['errors']??[])): ?><p class="muted small">Подробности приведены для первых ошибок; общий счётчик включает все неподтверждённые позиции.</p><?php endif; ?>
  <?php foreach(($ozonResult['errors']??[])as $error): ?><p class="error-text"><b><?= e($error['offer_id']) ?></b>: <?= e($error['message']) ?></p><?php endforeach; ?>
  <p class="field__hint">При сетевой ошибке результат части запроса может быть неизвестен. Автоматический повтор не выполняется; перед повторной отправкой проверьте остатки в Ozon.</p>
</div>
<?php endif; ?>
