<?php
declare(strict_types=1);

namespace App\YmlExport;

use App\Support\Db;
use RuntimeException;

/**
 * Таблицы дополнения «Выгрузка YML».
 *
 * Дополнение не трогает основную схему: на работающих установках таблицы
 * создадут общие миграции db/migrations (через ./deploy/update.sh или кнопку
 * «Обновить базу» в админке). Здесь — страховка для чистых установок, где
 * миграции только отмечаются применёнными: при первом обращении к разделу
 * недостающие таблицы создаются сами. Контроль — по наличию таблицы,
 * поэтому повторный запуск безопасен.
 */
final class Schema
{
    /** Версия миграции => контрольная таблица. */
    private const MIGRATIONS = [
        '2026_10_05_01_yml_export'    => 'yx_warehouses',
        '2026_10_05_02_yml_stock_map' => 'yx_stock_map',
    ];

    public static function ensure(): void
    {
        foreach (self::MIGRATIONS as $version => $table) {
            if (Db::tableExists($table)) {
                continue;
            }

            $file = APP_ROOT . '/db/migrations/' . $version . '.sql';
            if (!is_file($file)) {
                throw new RuntimeException('Не найден файл миграции ' . $version . '.sql');
            }

            $sql = (string) file_get_contents($file);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                Db::pdo()->exec($statement);
            }

            if (Db::tableExists('schema_migrations')) {
                Db::run('INSERT IGNORE INTO schema_migrations (version) VALUES (?)', [$version]);
            }
        }
    }
}
