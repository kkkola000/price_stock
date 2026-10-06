<?php
declare(strict_types=1);

/**
 * Импорт из командной строки (планировщик задач Plesk → «Запустить команду»).
 *
 *   php /var/www/vhosts/example.com/catalog/bin/import.php --due
 *   php bin/import.php --all
 *   php bin/import.php --source=3
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Этот скрипт запускается только из командной строки.');
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Import\ImportService;
use App\Models\SourceRepository;
use App\Support\Scheduler;

@set_time_limit(0);

$options = getopt('', ['all', 'due', 'source::', 'quiet']);
$quiet = isset($options['quiet']);

$log = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
    }
};

/** Дополнение «Выгрузка YML»: обновить файлы складов по ссылкам и пересобрать все фиды. */
$updateYml = static function () use ($log): void {
    try {
        $stats = \App\YmlExport\AutoUpdater::run();
        $log(sprintf(
            'YML-фиды: файлов складов обновлено: %d (ошибок: %d); фидов: %d, офферов: %d.',
            $stats['uploads'],
            $stats['failed'],
            $stats['feeds'],
            $stats['offers']
        ));
    } catch (Throwable $exception) {
        error_log('[yml-export] автообновление: ' . $exception->getMessage());
        $log('YML-фиды: ошибка автообновления — ' . $exception->getMessage());
    }
};

try {
    $manual = isset($options['source']) || isset($options['all']);

    if (isset($options['source'])) {
        $source = SourceRepository::find((int) $options['source']);
        if ($source === null) {
            fwrite(STDERR, 'Источник не найден: ' . $options['source'] . PHP_EOL);
            exit(1);
        }
        $sources = [$source];
    } elseif (isset($options['all'])) {
        $sources = SourceRepository::list(['only_active' => true]);
    } else {
        $sources = SourceRepository::dueForImport();
    }

    if (!$manual) {
        Scheduler::ping('cli', count($sources));
    }

    if ($sources === []) {
        $log('Нечего импортировать.');
        $updateYml();
        exit(0);
    }

    $log('Источников к импорту: ' . count($sources));
    $failed = 0;

    $results = ImportService::runMany($sources, $manual ? 'manual' : 'cron');
    if (!$manual) {
        Scheduler::ping('cli', count($sources), count($results));
    }

    foreach ($results as $result) {
        $log(sprintf('%s — %s. %s', $result['source_name'], $result['status'] === 'ok' ? 'OK' : 'ОШИБКА', $result['message']));
        if ($result['status'] !== 'ok') {
            $failed++;
        }
    }

    $updateYml();

    exit($failed > 0 ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Критическая ошибка: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
