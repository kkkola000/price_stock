<?php
declare(strict_types=1);

/**
 * Установка и диагностика из командной строки — альтернатива public/install.php.
 *
 *   php bin/setup.php --check                     проверить окружение
 *   php bin/setup.php --write-config              создать config.php из переменных окружения
 *   php bin/setup.php                             создать таблицы и администратора
 *   php bin/setup.php --list-admins               показать логины администраторов
 *   ADMIN_USER=admin ADMIN_PASS=новый php bin/setup.php --reset-password
 *                                                 задать новый пароль администратору
 *
 * Пароли передаются через переменные окружения (DB_PASS, ADMIN_PASS), а не аргументами:
 * аргументы видны в списке процессов другим пользователям сервера.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Этот скрипт запускается только из командной строки.');
}

const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'zip', 'xmlreader', 'curl', 'iconv', 'json'];
const MIN_PHP_VERSION = '8.1.0';

$root = dirname(__DIR__);
$options = getopt('', ['check', 'write-config', 'force', 'admin-user::', 'admin-pass::',
    'reset-password', 'list-admins', 'quiet']);
$quiet = isset($options['quiet']);

function say(string $message): void
{
    global $quiet;
    if (!$quiet) {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}

function fail(string $message): never
{
    fwrite(STDERR, '  ОШИБКА: ' . $message . PHP_EOL);
    exit(1);
}

function env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : trim($value);
}

/* ---------- Проверка окружения ---------- */

function check_environment(string $root): array
{
    $problems = [];

    if (version_compare(PHP_VERSION, MIN_PHP_VERSION, '<')) {
        $problems[] = 'нужен PHP ' . MIN_PHP_VERSION . ' или новее, установлен ' . PHP_VERSION;
    }

    foreach (REQUIRED_EXTENSIONS as $extension) {
        if (!extension_loaded($extension)) {
            $problems[] = 'не подключено расширение PHP «' . $extension . '»';
        }
    }

    foreach (['storage/uploads', 'storage/tmp', 'storage/logs'] as $dir) {
        $path = $root . '/' . $dir;
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            $problems[] = 'нет каталога ' . $dir . ' и его не удалось создать';
        } elseif (!is_writable($path)) {
            $problems[] = 'нет прав на запись в ' . $dir . ' (нужно chmod 775)';
        }
    }

    return $problems;
}

$problems = check_environment($root);

if (isset($options['check'])) {
    say('PHP ' . PHP_VERSION . ' (' . PHP_BINARY . ')');
    foreach (REQUIRED_EXTENSIONS as $extension) {
        say(sprintf('  %s расширение %s', extension_loaded($extension) ? '✓' : '✗', $extension));
    }
    foreach (['storage/uploads', 'storage/tmp', 'storage/logs'] as $dir) {
        say(sprintf('  %s запись в %s', is_writable($root . '/' . $dir) ? '✓' : '✗', $dir));
    }

    $configExists = is_file($root . '/config.php');
    say(sprintf('  %s config.php%s', $configExists ? '✓' : '—', $configExists ? '' : ' (ещё не создан)'));

    if ($configExists) {
        require_once $root . '/app/bootstrap.php';
        try {
            App\Support\Db::pdo();
            say('  ✓ соединение с базой данных');
            foreach (['sources', 'products', 'import_runs', 'admin_users'] as $table) {
                $exists = App\Support\Db::tableExists($table);
                say(sprintf('  %s таблица %s%s', $exists ? '✓' : '✗', $table,
                    $exists ? ' (записей: ' . App\Support\Db::scalar("SELECT COUNT(*) FROM `$table`") . ')' : ''));
            }
        } catch (Throwable $exception) {
            say('  ✗ соединение с базой данных: ' . $exception->getMessage());
            $problems[] = 'нет соединения с базой данных';
        }
    }

    if ($problems !== []) {
        say('');
        foreach ($problems as $problem) {
            fwrite(STDERR, '  ! ' . $problem . PHP_EOL);
        }
        exit(1);
    }
    say('');
    say('Окружение готово.');
    exit(0);
}

if ($problems !== []) {
    foreach ($problems as $problem) {
        fwrite(STDERR, '  ! ' . $problem . PHP_EOL);
    }
    fail('окружение не готово, исправьте пункты выше');
}

/* ---------- Создание config.php ---------- */

if (isset($options['write-config'])) {
    $configFile = $root . '/config.php';
    if (is_file($configFile) && !isset($options['force'])) {
        say('config.php уже существует — оставляю без изменений (--force перезапишет).');
        exit(0);
    }

    $database = env('DB_NAME');
    $username = env('DB_USER');
    if ($database === '' || $username === '') {
        fail('задайте переменные окружения DB_NAME и DB_USER (а также DB_PASS)');
    }

    $config = [
        'db' => [
            'host'     => env('DB_HOST', 'localhost'),
            'port'     => (int) env('DB_PORT', '3306'),
            'database' => $database,
            'username' => $username,
            'password' => env('DB_PASS'),
            'charset'  => 'utf8mb4',
        ],
        'site_name'        => env('SITE_NAME', 'Каталог товаров'),
        'default_currency' => env('DEFAULT_CURRENCY', 'RUB'),
        'per_page'         => (int) env('PER_PAGE', '24'),
        'cron_token'       => env('CRON_TOKEN') !== '' ? env('CRON_TOKEN') : bin2hex(random_bytes(24)),
        'max_upload_bytes' => (int) env('MAX_UPLOAD_BYTES', '52428800'),
        'download_timeout' => (int) env('DOWNLOAD_TIMEOUT', '120'),
        'timezone'         => env('TIMEZONE', 'Asia/Almaty'),
        'debug'            => false,
    ];

    $contents = "<?php\n\n// Создан автоматически: " . date('Y-m-d H:i:s') . "\n// Пароли и токен хранятся здесь — файл не должен быть доступен из интернета.\n\nreturn "
        . var_export($config, true) . ";\n";

    if (file_put_contents($configFile, $contents) === false) {
        fail('не удалось записать config.php (проверьте права на каталог проекта)');
    }
    @chmod($configFile, 0640);

    say('Создан config.php (права 640).');
    say('Токен для планировщика: ' . $config['cron_token']);
    exit(0);
}

/* ---------- Создание таблиц и администратора ---------- */

if (!is_file($root . '/config.php')) {
    fail('нет файла config.php — создайте его: php bin/setup.php --write-config');
}

require_once $root . '/app/bootstrap.php';

use App\Support\Auth;
use App\Support\Config;
use App\Support\Db;

try {
    Db::pdo();
} catch (Throwable $exception) {
    fail('нет соединения с базой данных: ' . $exception->getMessage());
}
say('Соединение с базой данных: ок');

$adminUser = trim((string) ($options['admin-user'] ?? env('ADMIN_USER')));
$adminPass = env('ADMIN_PASS') !== '' ? env('ADMIN_PASS') : (string) ($options['admin-pass'] ?? '');

/* ---------- Список администраторов ---------- */

if (isset($options['list-admins'])) {
    $admins = Db::all('SELECT username, created_at, last_login_at FROM admin_users ORDER BY username');
    if ($admins === []) {
        say('Администраторов нет. Создать: ADMIN_USER=admin ADMIN_PASS=пароль php bin/setup.php');
        exit(0);
    }
    say('Администраторы:');
    foreach ($admins as $admin) {
        say(sprintf('  %-20s создан %s, последний вход %s',
            $admin['username'],
            format_datetime($admin['created_at']),
            format_datetime($admin['last_login_at'])));
    }
    exit(0);
}

/* ---------- Смена пароля ---------- */

if (isset($options['reset-password'])) {
    // Пароль восстановить нельзя: в базе лежит только хеш. Зато можно задать новый.
    if ($adminUser === '' || $adminPass === '') {
        fail('укажите логин и новый пароль: ADMIN_USER=admin ADMIN_PASS=новый_пароль php bin/setup.php --reset-password');
    }
    if (mb_strlen($adminPass) < 8) {
        fail('пароль должен быть не короче 8 символов');
    }
    if (Db::first('SELECT id FROM admin_users WHERE username = ?', [$adminUser]) === null) {
        $known = array_column(Db::all('SELECT username FROM admin_users'), 'username');
        fail('нет администратора с логином «' . $adminUser . '».'
            . ($known !== [] ? ' Есть такие: ' . implode(', ', $known) . '.' : ' Администраторов нет вообще.'));
    }

    Db::run('UPDATE admin_users SET password_hash = ? WHERE username = ?', [
        password_hash($adminPass, PASSWORD_DEFAULT),
        $adminUser,
    ]);
    say('Пароль администратора «' . $adminUser . '» изменён.');
    exit(0);
}


try {
    $result = App\Support\Migrator::migrate();
} catch (Throwable $exception) {
    fail($exception->getMessage());
}

say(sprintf('Схема применена (%d запросов, существующие таблицы не затронуты).', $result['statements']));
if ($result['applied'] !== []) {
    foreach ($result['applied'] as $version) {
        say('  применена миграция: ' . $version);
    }
} elseif (!$result['fresh']) {
    say('Новых миграций нет.');
}

$adminUser = trim((string) ($options['admin-user'] ?? env('ADMIN_USER')));
$adminPass = env('ADMIN_PASS') !== '' ? env('ADMIN_PASS') : (string) ($options['admin-pass'] ?? '');

if (Auth::usersCount() > 0 && !isset($options['force'])) {
    say('Администратор уже создан — пропускаю (--force создаст ещё одного).');
} elseif ($adminUser === '' || $adminPass === '') {
    say('');
    say('Администратор не создан: не заданы ADMIN_USER и ADMIN_PASS.');
    say('Создать: ADMIN_USER=admin ADMIN_PASS=пароль php bin/setup.php');
} else {
    if (mb_strlen($adminUser) < 3) {
        fail('логин администратора должен быть не короче 3 символов');
    }
    if (mb_strlen($adminPass) < 8) {
        fail('пароль администратора должен быть не короче 8 символов');
    }
    if (Db::first('SELECT id FROM admin_users WHERE username = ?', [$adminUser]) !== null) {
        say('Пользователь «' . $adminUser . '» уже существует — пропускаю.');
    } else {
        Auth::createUser($adminUser, $adminPass);
        say('Создан администратор: ' . $adminUser);
    }
}

$token = (string) Config::get('cron_token', '');
say('');
say('Готово. Источников в базе: ' . Db::scalar('SELECT COUNT(*) FROM sources')
    . ', товаров: ' . Db::scalar('SELECT COUNT(*) FROM products') . '.');
if ($token === '' || strlen($token) < 16) {
    say('ВНИМАНИЕ: в config.php не задан cron_token — запуск импорта по URL работать не будет.');
}
