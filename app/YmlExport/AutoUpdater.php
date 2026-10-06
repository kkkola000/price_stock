<?php
declare(strict_types=1);

namespace App\YmlExport;

/**
 * Автообновление раздела «Выгрузка YML» вместе с панелью.
 *
 * Вызывается из общего планировщика каталога (bin/import.php и
 * public/api/cron.php) после импорта источников: обновляет файлы складов,
 * получаемые по ссылке (у каждого файла после импорта пересчитывается
 * автосопоставление склада), и пересобирает все YML-фиды.
 *
 * Ошибки отдельных файлов не роняют общий запуск планировщика:
 * UploadImporter фиксирует их в статусе загрузки и возвращает сообщение.
 */
final class AutoUpdater
{
    /**
     * @return array{uploads:int,failed:int,feeds:int,offers:int}
     */
    public static function run(): array
    {
        Schema::ensure();
        FeedRepository::ensureDefault();

        $uploads = 0;
        $failed = 0;
        foreach (UploadRepository::listAutoImportable() as $upload) {
            $uploads++;
            $result = UploadImporter::run($upload);
            if ($result['status'] !== 'ok') {
                $failed++;
            }
        }

        $built = FeedBuilder::buildAll();

        return [
            'uploads' => $uploads,
            'failed'  => $failed,
            'feeds'   => $built['feeds'],
            'offers'  => $built['offers'],
        ];
    }
}
