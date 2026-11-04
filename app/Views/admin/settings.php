<?php
use App\Support\Csrf;
use App\Support\TimezoneSettings;
$timezone=date_default_timezone_get();
$now=new DateTimeImmutable();
$common=['Europe/Moscow'=>'Москва','Europe/Kaliningrad'=>'Калининград','Europe/Samara'=>'Самара','Asia/Yekaterinburg'=>'Екатеринбург','Asia/Novosibirsk'=>'Новосибирск','Asia/Krasnoyarsk'=>'Красноярск','Asia/Irkutsk'=>'Иркутск','Asia/Yakutsk'=>'Якутск','Asia/Vladivostok'=>'Владивосток','Asia/Almaty'=>'Алматы','Asia/Aqtobe'=>'Актобе','Asia/Tashkent'=>'Ташкент','Europe/Minsk'=>'Минск','UTC'=>'Всемирное время'];
?>
<div class="card">
  <h2 class="card__title">Часовой пояс сервиса</h2>
  <form method="post" action="index.php?page=settings">
    <?= Csrf::field() ?>
    <label class="field" style="max-width:560px"><span class="field__label">Часовой пояс</span>
      <select class="input" name="timezone" id="service-timezone">
        <?php foreach(TimezoneSettings::identifiers() as $identifier):
          $zone=new DateTimeZone($identifier);$offset=$zone->getOffset($now);$sign=$offset<0?'-':'+';$seconds=abs($offset);
          $label=sprintf('UTC%s%02d:%02d — %s%s',$sign,intdiv($seconds,3600),intdiv($seconds%3600,60),isset($common[$identifier])?$common[$identifier].' · ':'',$identifier); ?>
          <option value="<?= e($identifier) ?>"<?= $identifier===$timezone?' selected':'' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <p>Текущее время сервиса: <strong><?= e(date('d.m.Y H:i:s')) ?></strong> (<?= e($timezone) ?>).</p>
    <p class="field__hint">Используется в расписании импортов, новых датах журнала и дате сборки YML {{дата_фида}}. Ранее сохранённые даты не пересчитываются. Изменение применяется со следующего запроса или запуска планировщика.</p>
    <button class="btn btn--primary">Сохранить часовой пояс</button>
  </form>
</div>

<div class="card">
  <h2 class="card__title">Закупочные цены в заказе</h2>
  <p>Доступ <?= App\Support\PurchaseAccess::configured() ? 'защищён кодом' : 'выключен: код не установлен' ?>.</p>
  <form method="post" action="index.php?page=settings">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="purchase-code">
    <label class="field"><span class="field__label">Новый код доступа</span>
      <input class="input" type="password" name="purchase_code" minlength="4" maxlength="128" autocomplete="new-password">
      <span class="field__hint">От 4 до 128 символов. Код открывает закупочные цены только в заказе и его скачиваемой таблице. Смена кода сбрасывает доступ во всех сессиях.</span>
    </label>
    <label class="check-inline"><input type="checkbox" name="disable_purchase_code"> Выключить доступ к закупочным ценам</label>
    <button class="btn btn--primary">Сохранить код доступа</button>
  </form>
</div>
