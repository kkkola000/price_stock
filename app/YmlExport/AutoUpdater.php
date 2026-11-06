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
     * @return array<string,mixed>
     */
    public static function run(): array
    {
        if(CycleSettings::configured())return CycleScheduler::tick();
        return CycleScheduler::tick(true,static fn():array=>self::execute(false));
    }

    /** Full section cycle: active warehouse URLs -> XML -> optional API. */
    public static function execute(bool $unified=false,?OzonClient $client=null): array
    {
        Schema::ensure();
        FeedRepository::ensureDefault();

        $needed=[];
        foreach(FeedRepository::list() as $feed)if(CycleSettings::feedActive((int)$feed['id']))$needed=array_merge($needed,$feed['warehouse_ids']);
        $needed=array_unique(array_map('intval',$needed));
        $uploads = 0;
        $failed = 0;
        $errors = [];
        foreach (UploadRepository::listAutoImportable() as $upload) {
            if(!in_array((int)$upload['warehouse_id'],$needed,true))continue;
            $uploads++;
            $result = UploadImporter::run($upload);
            if ($result['status'] !== 'ok') {
                $failed++;
                $errors[]=(string)$upload['warehouse_name'].': '.$result['message'];
            }
        }

        // Do not publish/send an inconsistent cycle after a failed source import.
        if($failed>0)return ['uploads'=>$uploads,'failed'=>$failed,'feeds'=>0,'offers'=>0,'ozon'=>['status'=>'skipped','message'=>'Ошибка загрузки файла склада.'],'errors'=>$errors];
        $built = FeedBuilder::buildAll(true);
        $ozon=OzonScheduler::tick($client,null,$unified);

        return [
            'uploads' => $uploads,
            'failed'  => $failed,
            'feeds'   => $built['feeds'],
            'ozon'    => $ozon,
            'offers'  => $built['offers'],
        ];
    }
}
