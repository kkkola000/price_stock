<?php
declare(strict_types=1);

/**
 * Сброс пароля администратора без доступа к командной строке.
 *
 * Страница работает только тогда, когда в storage лежит файл-разрешение
 * reset.allow. Создать его можно лишь через Менеджер файлов Plesk, FTP или
 * SSH — то есть тем, у кого и так есть доступ к серверу. После успешной
 * смены пароля файл удаляется, и страница снова закрывается.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Db;

const RESET_FLAG = '/reset.allow';

$flagFile = APP_STORAGE . RESET_FLAG;
$allowed = is_file($flagFile);
$errors = [];
$done = false;
$admins = [];

try {
    Db::pdo();
    $admins = array_column(Db::all('SELECT username FROM admin_users ORDER BY username'), 'username');
} catch (Throwable $exception) {
    $errors[] = 'Нет соединения с базой данных: ' . $exception->getMessage();
}

if ($allowed && $errors === [] && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if ($username === '' || ($admins !== [] && !in_array($username, $admins, true))) {
        $errors[] = 'Выберите администратора из списка.';
    }
    if (mb_strlen($password) < 8) {
        $errors[] = 'Пароль должен быть не короче 8 символов.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Пароли не совпадают.';
    }

    if ($errors === []) {
        try {
            if ($admins === []) {
                Auth::createUser($username, $password);
            } else {
                Db::run('UPDATE admin_users SET password_hash = ? WHERE username = ?', [
                    password_hash($password, PASSWORD_DEFAULT),
                    $username,
                ]);
            }
            // Разрешение одноразовое: снимаем его сразу после смены пароля
            @unlink($flagFile);
            $done = true;
        } catch (Throwable $exception) {
            $errors[] = 'Не удалось сохранить пароль: ' . $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Сброс пароля администратора</title>
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
</head>
<body>
<main class="a-main a-main--narrow">
  <h1 class="a-title">Пароль администратора</h1>

  <?php foreach ($errors as $error): ?>
    <div class="alert alert--error"><?= e($error) ?></div>
  <?php endforeach; ?>

  <?php if ($done): ?>
    <div class="alert alert--success">Пароль изменён. Файл-разрешение удалён, страница снова закрыта.</div>
    <p><a class="btn btn--primary" href="admin/index.php">Войти в админ-панель</a></p>

  <?php elseif (!$allowed): ?>
    <div class="alert alert--warning">
      <b>Страница выключена.</b><br>
      Чтобы её включить, создайте пустой файл <code>reset.allow</code> в папке <code>storage</code>
      проекта — через <i>Менеджер файлов</i> в Plesk, по FTP или в командной строке.
    </div>
    <p class="muted small">
      Полный путь: <code><?= e($flagFile) ?></code><br>
      В Менеджере файлов Plesk: откройте папку <code>storage</code> →
      <i>Создать</i> → <i>Создать файл</i> → имя <code>reset.allow</code> → <i>ОК</i>.
      Затем обновите эту страницу.
    </p>
    <p class="muted small">
      Так сделано намеренно: сменить пароль может только тот, у кого есть доступ к файлам сайта.
      После смены файл удаляется сам.
    </p>

  <?php else: ?>
    <form class="card" method="post" action="reset-password.php">
      <?= Csrf::field() ?>
      <p class="card__hint">
        <?= $admins === []
            ? 'Администраторов ещё нет — укажите логин и пароль, учётная запись будет создана.'
            : 'Выберите администратора и задайте новый пароль.' ?>
      </p>

      <label class="field">
        <span class="field__label">Логин</span>
        <?php if ($admins === []): ?>
          <input class="input" type="text" name="username" required minlength="3" value="admin" autocomplete="username">
        <?php else: ?>
          <select class="input" name="username">
            <?php foreach ($admins as $admin): ?>
              <option value="<?= e($admin) ?>"><?= e($admin) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </label>

      <label class="field">
        <span class="field__label">Новый пароль</span>
        <input class="input" type="password" name="password" required minlength="8" autocomplete="new-password">
        <span class="field__hint">Минимум 8 символов.</span>
      </label>

      <label class="field">
        <span class="field__label">Повторите пароль</span>
        <input class="input" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
      </label>

      <button class="btn btn--primary btn--block" type="submit">Сохранить пароль</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
