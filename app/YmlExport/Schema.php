<?php
declare(strict_types=1);

namespace App\YmlExport;

use App\Support\Db;
use RuntimeException;

/**
 * Таблицы дополнения «Выгрузка YML».
 *
 * Дополнение не трогает основную схему: на работающих установках таблицы
 * создаст общая миграция db/migrations/2026_10_05_01_yml_export.sql
 * (через ./deploy/update.sh или кнопку «Обновить базу» в админке).
 * Здесь — страховка для чистых установок, где миграции только отмечаются
 * применёнными: при первом обращении к разделу таблицы создаются сами.
 */
final class Schema
{
    private const MIGRATION_VERSION = '2026_10_05_01_yml_export';

    public static function ensure(): void
    {
        if (Db::tableExists('yx_warehouses')) {
            return;
        }

        $file = APP_ROOT . '/db/migrations/' . self::MIGRATION_VERSION . '.sql';
        if (!is_file($file)) {
            throw new RuntimeException('Не найден файл миграции ' . self::MIGRATION_VERSION . '.sql');
        }

        $sql = (string) file_get_contents($file);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            Db::pdo()->exec($statement);
        }

        if (Db::tableExists('schema_migrations')) {
            Db::run('INSERT IGNORE INTO schema_migrations (version) VALUES (?)', [self::MIGRATION_VERSION]);
        }
    }
}
