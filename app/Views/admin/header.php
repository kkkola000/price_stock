<?php
use App\Support\{Auth,Config};
$section=$adminSection??match((string)($_GET['page']??'sources')){'runs'=>'runs','settings'=>'settings',default=>'sources'};
$username=Auth::username();$avatar=mb_strtoupper(mb_substr($username,0,1,'UTF-8'),'UTF-8');
?>
<header class="admin-header">
  <div class="admin-header__inner">
    <a class="admin-brand" href="index.php"><span class="admin-brand__icon" aria-hidden="true"><i></i><i></i><i></i><i></i></span><b><?= e(Config::get('site_name','Каталог')) ?></b><span>Управление</span></a>
    <nav class="admin-navigation" aria-label="Навигация админки">
      <?php foreach(['sources'=>['index.php','Источники'],'yml'=>['yml.php','Выгрузка YML'],'runs'=>['index.php?page=runs','Журнал импортов'],'settings'=>['index.php?page=settings','Настройки']] as $key=>[$url,$label]): ?><a href="<?= e($url) ?>"<?= $section===$key?' class="is-active" aria-current="page"':'' ?>><?= e($label) ?></a><?php endforeach; ?>
    </nav>
    <div class="admin-header__actions">
      <a class="admin-storefront" href="../index.php" target="_blank" rel="noopener">Витрина <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></a>
      <details class="admin-account"><summary aria-label="Меню пользователя"><span class="admin-avatar" aria-hidden="true"><?= e($avatar) ?></span><span class="admin-account__name"><?= e($username) ?></span><span aria-hidden="true">⌄</span></summary>
        <div class="admin-account__menu"><b><?= e($username) ?></b><small>Администратор</small><a href="index.php?page=logout">Выйти</a></div>
      </details>
    </div>
  </div>
</header>
