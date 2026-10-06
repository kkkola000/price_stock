<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\YmlExport\FeedBuilder;
use App\YmlExport\FeedRepository;
use App\YmlExport\Schema;

/**
 * Публичная выдача YML-фида по токену ссылки (yx_feeds).
 * Отдаёт готовый кэш-файл из storage/feeds — без запросов к базе на каждый хит
 * (запрос нужен только один раз для проверки токена).
 */

try {
    Schema::ensure();
    FeedRepository::ensureDefault();

    $token = (string) ($_GET['token'] ?? '');
    $feed = $token !== '' ? FeedRepository::findByToken($token) : null;

    if ($feed === null) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Недействительная ссылка на фид.');
    }

    $file = FeedBuilder::path((string) $feed['token']);
    if (!is_file($file)) {
        FeedBuilder::buildFeed($feed);
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
