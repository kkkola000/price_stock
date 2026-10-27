<?php
declare(strict_types=1);

/**
 * Единая точка инициализации: автозагрузка, конфиг, БД, сессия.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_STORAGE', APP_ROOT . '/storage');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4));
    $file = APP_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once APP_ROOT . '/app/Support/helpers.php';

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Нет файла config.php. Скопируйте config.sample.php в config.php.\n");
        exit(1);
    }
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    exit('<h1>Сервис не настроен</h1><p>Скопируйте <code>config.sample.php</code> в <code>config.php</code> '
        . 'и укажите параметры БД, затем откройте <code>/install.php</code>.</p>');
}

$configuration=require $configFile;
$configuration['timezone']=App\Support\TimezoneSettings::current((string)($configuration['timezone']??'Asia/Almaty'));
App\Support\Config::load($configuration);
unset($configuration);

error_reporting(E_ALL);
ini_set('display_errors', App\Support\Config::get('debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_STORAGE . '/logs/php-error.log');

date_default_timezone_set(App\Support\Config::get('timezone', 'Asia/Almaty'));

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}
