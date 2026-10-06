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
    /**
     * Версия миграции => контроль: таблица целиком или конкретный столбец
     * (для миграций, которые только добавляют столбец в существующую таблицу).
     *
     * @var array<string, array{table:string, column:?string}>
     */
    private const MIGRATIONS = [
        '2026_10_05_01_yml_export'          => ['table' => 'yx_warehouses', 'column' => null],
        '2026_10_05_02_yml_stock_map'       => ['table' => 'yx_stock_map', 'column' => null],
        '2026_10_05_03_yml_feed_warehouses' => ['table' => 'yx_warehouses', 'column' => 'in_feed'],
        '2026_10_06_01_yml_feeds'           => ['table' => 'yx_feeds', 'column' => null],
    ];

    public static function ensure(): void
    {
        foreach (self::MIGRATIONS as $version => $check) {
            $applied = $check['column'] === null
                ? Db::tableExists($check['table'])
                : self::columnExists($check['table'], $check['column']);
            if ($applied) {
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

    private static function columnExists(string $table, string $column): bool
    {
        return Db::first(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) !== null;
    }
}
