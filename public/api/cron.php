<?php
declare(strict_types=1);

/**
 * Запуск импорта по URL — для планировщика Plesk в режиме «Получить URL»
 * или для внешнего cron-сервиса:
 *
 *   https://example.com/api/cron.php?token=ВАШ_ТОКЕН
 *   https://example.com/api/cron.php?token=ВАШ_ТОКЕН&all=1
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Import\ImportService;
use App\Models\SourceRepository;
use App\Support\Scheduler;
use App\Support\Config;

@set_time_limit(0);
ignore_user_abort(true);
header('Cache-Control: no-store');

$configured = (string) Config::get('cron_token', '');
$provided   = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');

if ($configured === '' || strlen($configured) < 16) {
    json_response(['ok' => false, 'error' => 'Задайте cron_token (не короче 16 символов) в config.php.'], 500);
}
if (!hash_equals($configured, $provided)) {
    json_response(['ok' => false, 'error' => 'Неверный токен.'], 403);
}

/** Дополнение «Выгрузка YML»: обновить файлы складов по ссылкам и пересобрать все фиды. */
$updateYml = static function (): array {
    try {
        return \App\YmlExport\AutoUpdater::run();
    } catch (Throwable $exception) {
        error_log('[yml-export] автообновление: ' . $exception->getMessage());
        return ['error' => $exception->getMessage()];
    }
};

try {
    $sources = isset($_GET['all'])
        ? SourceRepository::list(['only_active' => true])
        : SourceRepository::dueForImport();

    Scheduler::ping('url', count($sources));

    if ($sources === []) {
        json_response(['ok' => true, 'message' => 'Нечего импортировать.', 'results' => [], 'yml_feeds' => $updateYml()]);
    }

    $results = ImportService::runMany($sources, 'cron');
    Scheduler::ping('url', count($sources), count($results));
    $failed = array_filter($results, static fn (array $r): bool => $r['status'] !== 'ok');

    json_response([
        'ok'      => $failed === [],
        'total'   => count($results),
        'failed'  => count($failed),
        'results' => array_map(static fn (array $r): array => [
            'source'   => $r['source_name'],
            'status'   => $r['status'],
            'imported' => $r['rows_imported'],
            'skipped'  => $r['rows_skipped'],
            'deleted'  => $r['rows_deleted'],
            'message'  => $r['message'],
        ], $results),
        'yml_feeds' => $updateYml(),
    ]);
} catch (Throwable $exception) {
    error_log('[cron] ' . $exception->getMessage());
    json_response(['ok' => false, 'error' => $exception->getMessage()], 500);
}
