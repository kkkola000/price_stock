<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\YmlExport\FeedBuilder;
use App\YmlExport\Schema;
use App\YmlExport\SettingsRepository;

/**
 * Публичная выдача YML-фида по токену.
 * Отдаёт готовый кэш-файл из storage/feeds — без запросов к базе на каждый хит
 * (запрос нужен только один раз для проверки токена).
 */

try {
    Schema::ensure();

    $settings = SettingsRepository::get();
    $token = (string) ($_GET['token'] ?? '');

    if ($token === '' || !hash_equals((string) $settings['token'], $token)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Недействительная ссылка на фид.');
    }

    $file = FeedBuilder::path((string) $settings['token']);
    if (!is_file($file)) {
        FeedBuilder::build();
    }

    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('Content-Length: ' . filesize($file));
    readfile($file);
} catch (Throwable $exception) {
    error_log('[yml-feed] ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Фид временно недоступен.');
}
