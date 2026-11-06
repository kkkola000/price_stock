<?php
/** @var string $pageTitle */
/** @var string $content */
/** @var bool $showNav */
use App\Support\Auth;
use App\Support\Config;

$messages = flash();
$isSourceForm = str_ends_with((string)$content,'/source_form.php');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> — админка</title>
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css', '../')) ?>">
</head>
<body class="admin-page<?= $isSourceForm?' yml-page admin-source-page':'' ?>">
<?php if ($showNav): ?>
<?php require APP_ROOT.'/app/Views/admin/header.php'; ?>
<?php endif; ?>

<main class="a-main<?= $showNav ? '' : ' a-main--narrow' ?>">
  <h1 class="a-title"><?= e($isSourceForm&&$source!==null?$source['name']:$pageTitle) ?><?php if($isSourceForm&&$source!==null): ?> <span class="pill <?= (int)$source['is_active']===1?'pill--ok':'pill--disabled' ?>"><?= (int)$source['is_active']===1?'Активен':'Отключён' ?></span><?php endif; ?></h1>

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
<?php if($isSourceForm): ?><script src="<?= e(asset('assets/js/yml.js', '../')) ?>" defer></script><?php endif; ?>
</body>
</html>
