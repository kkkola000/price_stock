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
