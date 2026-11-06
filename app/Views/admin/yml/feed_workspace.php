<?php
use App\Support\Csrf;
use App\Import\ColumnRef;
use App\YmlExport\{UploadRepository,WarehouseRepository,FeedRepository,Matcher,FeedBuilder};
?>
<div class="toolbar"><span class="stat"><b><?= count($feeds) ?></b> фидов</span><a class="btn btn--primary" href="yml.php?tab=feed&feed_form=new">+ Создать фид</a></div>
<div class="yml-feed-workspace">
  <aside class="card yml-feed-list" aria-label="Ссылки YML">
    <h2 class="card__title">Фиды</h2>
    <?php foreach($feeds as $feedRow): ?>
    <a class="yml-feed-choice <?= (int)$feedRow['id']===$previewFeedId?'is-active':'' ?>" href="yml.php?tab=<?= $tab==='settings'?'settings':'feed' ?>&preview_feed=<?= (int)$feedRow['id'] ?>">
      <b><?= e($feedRow['name']) ?></b><span class="pill <?= $feedRow['built_at']!==null?'pill--ok':'pill--never' ?>"><?= $feedRow['built_at']!==null?'Готов':'Не собран' ?></span>
      <span><?= (int)$feedRow['built_offers'] ?> товаров · <?= count($feedRow['warehouse_ids']) ?> складов</span>
      <small><?= $feedRow['built_at']!==null?e(format_datetime($feedRow['built_at'])):'—' ?></small>
    </a>
    <?php endforeach; ?>
  </aside>
  <section class="yml-feed-content">
    <?php if($feedFormId!==null): require APP_ROOT.'/app/Views/admin/yml/feed_form.php'; endif; ?>
    <?php if($previewFeed!==null): ?>
    <div class="card yml-feed-head">
      <div class="toolbar"><h2 class="card__title"><?= e($previewFeed['name']) ?></h2><div class="form__actions">
        <button class="btn btn--small btn--ghost" type="button" data-copy-text="<?= e($feedBaseUrl.$previewFeed['token']) ?>">Копировать ссылку</button>
        <a class="btn btn--small btn--ghost" href="<?= e($feedBaseUrl.$previewFeed['token']) ?>" download="feed-<?= $previewFeedId ?>.xml">Скачать XML</a>
        <details class="yml-actions"><summary aria-label="Действия фида">•••</summary><div>
          <a class="btn btn--small btn--ghost" href="yml.php?tab=feed&feed_form=<?= (int)$previewFeed['id'] ?>&preview_feed=<?= (int)$previewFeed['id'] ?>">Настройки ссылки</a>
          <form method="post" onsubmit="return confirm('Перевыпустить токен? Старая ссылка перестанет работать.')"><?= Csrf::field() ?><input type="hidden" name="action" value="feed-token"><input type="hidden" name="id" value="<?= (int)$previewFeed['id'] ?>"><button class="btn btn--small btn--ghost">Перевыпустить токен</button></form>
          <?php if(count($feeds)>1): ?><form method="post" onsubmit="return confirm('Удалить ссылку?')"><?= Csrf::field() ?><input type="hidden" name="action" value="feed-delete"><input type="hidden" name="id" value="<?= (int)$previewFeed['id'] ?>"><button class="btn btn--small btn--danger">Удалить ссылку</button></form><?php endif; ?>
        </div></details>
      </div></div>
      <nav class="tabs" aria-label="Работа с фидом">
        <a href="yml.php?tab=settings&preview_feed=<?= $previewFeedId ?>" class="<?= $tab==='settings'?'is-active':'' ?>">Общий шаблон</a>
        <a href="yml.php?tab=feed&preview_feed=<?= $previewFeedId ?>" class="<?= $tab==='feed'?'is-active':'' ?>">Предпросмотр XML</a>
        <a href="yml.php?tab=feed&feed_form=<?= $previewFeedId ?>&preview_feed=<?= $previewFeedId ?>">Склады и ссылка</a>
      </nav>
      <p class="card__hint">Шаблон, соответствия остатков и фильтры общие для всех фидов. Выбор ссылки определяет склады и товары предпросмотра; сохранение общего шаблона пересобирает все фиды.</p>
    </div>
    <?php endif; ?>
    <?php if($tab==='settings'): require APP_ROOT.'/app/Views/admin/yml/template.php'; elseif($previewFeed!==null): ?>
      <div class="card"><div class="toolbar"><h3 class="card__title">Предпросмотр XML</h3><form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="feed-build"><input type="hidden" name="feed_id" value="<?= $previewFeedId ?>"><button class="btn btn--primary">Собрать YML</button></form></div>
      <?php if($feedPreview!==''): ?><pre class="feed-preview"><?= e($feedPreview) ?></pre><?php else: ?><p class="muted">Фид ещё не собран.</p><?php endif; ?>
      <label class="field"><span class="field__label">Ссылка</span><input class="input mono" readonly value="<?= e($feedBaseUrl.$previewFeed['token']) ?>" onclick="this.select()"></label></div>
    <?php endif; ?>
  </section>
</div>
