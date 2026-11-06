<?php
use App\Support\Csrf;
use App\Import\ColumnRef;
use App\YmlExport\{UploadRepository,WarehouseRepository,FeedRepository,Matcher,FeedBuilder};
?>
  <?php if ($feedFormId !== null):
      $formWarehouseIds = $feedEdit !== null ? array_map('intval', (array) $feedEdit['warehouse_ids']) : []; ?>
    <div class="card">
      <h2 class="card__title"><?= $feedFormId > 0 ? 'Ссылка: ' . e((string) ($feedEdit['name'] ?? '')) : 'Новая ссылка' ?></h2>
      <p class="card__hint">Отмеченные склады попадут в этот YML: их остатки идут в outlets, значения тегов оффера берутся по приоритету складов. Отключённые склады не попадут в фид независимо от галочки.</p>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="feed-save">
        <input type="hidden" name="id" value="<?= (int) $feedFormId ?>">
        <label class="field" style="max-width:420px"><span class="field__label">Название ссылки</span>
          <input class="input" name="name" required maxlength="190" value="<?= e((string) ($feedEdit['name'] ?? '')) ?>" placeholder="Например: Маркет"></label>
        <label class="field" style="max-width:420px"><span class="field__label">Откуда брать товары и остатки</span><select class="input" name="warehouse_mode"><option value="all"<?= sel('all',$feedEdit['warehouse_mode']??'all') ?>>Все активные склады</option><option value="selected"<?= sel('selected',$feedEdit['warehouse_mode']??'all') ?>>Только выбранные ниже</option></select><span class="field__hint">Товары — подтверждённые сопоставления этих складов из раздела «Товары». В режиме «Все активные» новые склады подключаются автоматически.</span></label>
        <?php if ($warehouses === []): ?>
          <p class="muted">Складов пока нет — создайте их на вкладке «Склады и загрузка».</p>
        <?php endif; ?>
        <?php foreach ($warehouses as $wh): ?>
          <label class="check-inline" style="display:block">
            <input type="checkbox" name="warehouse_ids[]" value="<?= (int) $wh['id'] ?>"<?= chk(in_array((int) $wh['id'], $formWarehouseIds, true)) ?>>
            <b><?= e((string) $wh['name']) ?></b>
            <?php if ((string) ($wh['kind'] ?? 'file') === 'virtual'): ?>
              <span class="pill pill--running">виртуальный · <?= (int) ($wh['default_stock'] ?? 0) ?></span>
            <?php endif; ?>
            <?php if ((int) $wh['is_active'] !== 1): ?>
              <span class="pill pill--disabled">отключён</span>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
        <div class="form__actions">
          <button class="btn btn--primary"><?= $feedFormId > 0 ? 'Сохранить' : 'Создать ссылку' ?></button>
          <a class="btn btn--ghost" href="yml.php?tab=feed">Отмена</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

