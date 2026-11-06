<?php
use App\YmlExport\{CycleSettings,CycleScheduler};
$cycleSettings=CycleSettings::get();$cycleState=CycleSettings::state();$cycleNext=CycleScheduler::nextDue($cycleSettings,$cycleState);
?>
<div class="yml-schedule-layout"><section>
<div class="card">
  <h2 class="card__title">Общее расписание</h2>
  <p class="card__hint">Один цикл: обновление файлов активных складов по ссылкам → сборка активных YML → отправка остатков по API, если она включена.</p>
  <p class="field__hint">Остатки берутся из текущей витрины с учётом соответствий и порогов. Импорт поставщиков витрины работает по общему расписанию панели. Ручные файлы повторно не загружаются.</p>
  <?php if(!CycleSettings::configured()): ?><p class="field__hint">Общее расписание ещё не сохранено: действует прежний запуск из cron панели. После сохранения этой формы весь раздел перейдёт на указанный здесь интервал.</p><?php endif; ?>
  <form method="post">
    <?= App\Support\Csrf::field() ?><input type="hidden" name="action" value="cycle-save">
    <div class="yml-schedule-controls"><label class="check-inline"><input type="checkbox" name="enabled"<?= chk((bool)$cycleSettings['enabled']) ?>> Автообновление</label>
    <label class="field"><span class="field__label">Интервал, секунд</span><input class="input" type="number" name="interval_seconds" min="1" max="2147483647" step="1" required value="<?= (int)$cycleSettings['interval_seconds'] ?>"></label></div>
    <p class="field__hint">Интервал отсчитывается после завершения цикла. Параллельные запуски пропускаются. При ошибках пауза составляет не менее 60 секунд.</p>
    <h3 class="a-subtitle">Источники / склады</h3>
    <p class="field__hint">Неактивный склад не загружается и не участвует в YML и API. Эта галочка соответствует активности склада в разделе «Источники».</p>
    <div class="table-wrap"><table class="table"><thead><tr><th>Активен</th><th>Источник / склад</th><th>Получение файла</th></tr></thead><tbody>
      <?php foreach($warehouses as $warehouse): $upload=$uploadsByWarehouse[(int)$warehouse['id']]??null; ?>
      <tr><td><input type="checkbox" name="active_warehouses[]" aria-label="Активен: <?= e($warehouse['name']) ?>" value="<?= (int)$warehouse['id'] ?>"<?= chk((int)$warehouse['is_active']===1) ?>></td><td><?= e($warehouse['name']) ?></td><td><?= $warehouse['kind']==='virtual'?'Виртуальный склад':($upload===null?'Файл не настроен':($upload['fetch_method']==='url'?'По ссылке':'Ручная загрузка')) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <h3 class="a-subtitle">Ссылки YML</h3>
    <p class="field__hint">Выключенные ссылки не пересобираются автоматически; сохранённая ссылка остаётся доступна. API пропускается, если его выбранная ссылка выключена.</p>
    <?php foreach($feeds as $feed): ?><label class="check-inline"><input type="checkbox" name="active_feeds[]" value="<?= (int)$feed['id'] ?>"<?= chk(CycleSettings::feedActive((int)$feed['id'])) ?>> Активен: <?= e($feed['name']) ?></label><?php endforeach; ?>
    <div class="form__actions"><button class="btn btn--primary">Сохранить расписание</button></div>
  </form>
</div>
</section><aside>
<div class="card">
  <h2 class="card__title">Последний цикл</h2>
  <p><?= !empty($cycleSettings['enabled'])?'Включено · интервал '.(int)$cycleSettings['interval_seconds'].' сек.':'Выключено' ?></p>
  <?php if(!empty($cycleState['started'])): ?><p>Последний запуск: <?= e(date('d.m.Y H:i:s',$cycleState['started'])) ?> · <?= e(['ok'=>'Успешно','error'=>'Ошибка','running'=>'Выполняется'][$cycleState['status']??'']??'—') ?></p><?php endif; ?>
  <?php if($cycleNext!==null): ?><p>Следующий запуск: <?= $cycleNext<=time()?'ожидает планировщика':e(date('d.m.Y H:i:s',$cycleNext)) ?></p><?php endif; ?>
  <?php if(isset($cycleState['result'])): $cycleResult=$cycleState['result']; ?><p>Файлов: <?= (int)$cycleResult['uploads'] ?> · ошибок файлов: <?= (int)$cycleResult['failed'] ?> · фидов: <?= (int)$cycleResult['feeds'] ?> · товаров: <?= (int)$cycleResult['offers'] ?> · API: <?= e($cycleResult['ozon']['status']??'—') ?></p><?php endif; ?>
  <?php foreach(($cycleState['result']['errors']??[]) as $cycleError): ?><p class="error-text"><?= e($cycleError) ?></p><?php endforeach; ?>
  <?php if(!empty($cycleState['error'])): ?><p class="error-text"><?= e($cycleState['error']) ?></p><?php endif; ?>
  <form method="post"><?= App\Support\Csrf::field() ?><button class="btn btn--ghost" name="action" value="cycle-run">Обновить раздел сейчас</button></form>
  <div class="yml-panel-scheduler">
    <?php $panelPing=App\Support\Scheduler::lastPing(); ?>
    <b class="yml-scheduler-label">Общий планировщик</b>
    <p><?= $panelPing!==null?e($panelPing['last_ping_at']):'Ещё не зарегистрирован' ?></p>
    <p class="field__hint">Используется общий планировщик источников. Он сначала обновляет поставщиков, затем проверяет интервал YML. Частота обновления ограничена общим cron: интервал 30 секунд при cron раз в минуту проверяется раз в минуту.</p>
  </div>
</div>

</aside></div>
