<?php
/** @var string $pageTitle */
/** @var string $content */
/** @var bool $showNav */
use App\Support\Auth;
use App\Support\Config;

$messages = flash();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> — админка</title>
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css', '../')) ?>">
</head>
<body>
<?php if ($showNav): ?>
<header class="a-header">
  <div class="a-header__inner">
    <a class="a-header__logo" href="index.php"><?= e(Config::get('site_name', 'Каталог')) ?> <span>админка</span></a>
    <nav class="a-nav">
      <a href="index.php">Источники</a>
      <a href="index.php?page=runs">Журнал импортов</a>
      <a href="yml.php">Выгрузка YML</a>
      <a href="../index.php" target="_blank" rel="noopener">Витрина ↗</a>
    </nav>
    <div class="a-user">
      <span><?= e(Auth::username()) ?></span>
      <a href="index.php?page=logout">Выйти</a>
    </div>
  </div>
</header>
<?php endif; ?>

<main class="a-main<?= $showNav ? '' : ' a-main--narrow' ?>">
  <h1 class="a-title"><?= e($pageTitle) ?></h1>

  <?php foreach ($messages as $message): ?>
    <div class="alert alert--<?= e($message['type']) ?>"><?= e($message['message']) ?></div>
  <?php endforeach; ?>

  <?php if (!empty($pendingMigrations) && $showNav): ?>
    <div class="alert alert--warning alert--action">
      <div>
        <b>База данных не обновлена под новую версию сервиса.</b><br>
        Пока структура старая, источники не сохраняются и импорт не запускается.
        Обновление безопасное: товары и настройки не удаляются.
        <span class="muted small">Ожидают применения: <?= e(implode(', ', $pendingMigrations)) ?></span>
      </div>
      <form method="post" action="index.php?page=migrate">
        <?= \App\Support\Csrf::field() ?>
        <button class="btn btn--primary" type="submit">Обновить базу</button>
      </form>
    </div>
  <?php endif; ?>

  <?php require $content; ?>
</main>

<script src="<?= e(asset('assets/js/admin.js', '../')) ?>" defer></script>
</body>
</html>
