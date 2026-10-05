<?php
declare(strict_types=1);

namespace App\YmlExport;

use App\Support\Db;

/** Склады магазина для выгрузки YML. */
final class WarehouseRepository
{
    /** @return list<array<string,mixed>> */
    public static function list(): array
    {
        return Db::all(
            "SELECT w.*,
                    (SELECT COUNT(*) FROM yx_items i WHERE i.warehouse_id = w.id) AS items_count,
                    (SELECT COUNT(*) FROM yx_matches m WHERE m.warehouse_id = w.id AND m.status = 'confirmed') AS confirmed_count
             FROM yx_warehouses w
             ORDER BY w.sort, w.id"
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::first('SELECT * FROM yx_warehouses WHERE id = ?', [$id]);
    }

    public static function create(string $name, string $code, int $sort, string $kind = 'file', int $defaultStock = 0): int
    {
        Db::run('INSERT INTO yx_warehouses (name, code, sort, kind, default_stock) VALUES (?, ?, ?, ?, ?)', [
            mb_substr($name, 0, 190),
            mb_substr($code, 0, 64),
            $sort,
            self::kind($kind),
            max(0, $defaultStock),
        ]);

        return Db::lastInsertId();
    }

    public static function update(int $id, string $name, string $code, int $sort, string $kind = 'file', int $defaultStock = 0): void
    {
        Db::run('UPDATE yx_warehouses SET name = ?, code = ?, sort = ?, kind = ?, default_stock = ? WHERE id = ?', [
            mb_substr($name, 0, 190),
            mb_substr($code, 0, 64),
            $sort,
            self::kind($kind),
            max(0, $defaultStock),
            $id,
        ]);
    }

    private static function kind(string $kind): string
    {
        return $kind === 'virtual' ? 'virtual' : 'file';
    }

    public static function setActive(int $id, bool $active): void
    {
        Db::run('UPDATE yx_warehouses SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    /**
     * Виртуальные склады без файла: попадают в outlets каждого оффера
     * с постоянным остатком default_stock.
     *
     * @return list<array<string,mixed>>
     */
    public static function virtualList(): array
    {
        return Db::all(
            "SELECT * FROM yx_warehouses WHERE kind = 'virtual' AND is_active = 1 ORDER BY sort, id"
        );
    }

    /** Удаляет склад; его загрузка и позиции уходят каскадом. */
    public static function delete(int $id): void
    {
        $upload = Db::first('SELECT file_path FROM yx_uploads WHERE warehouse_id = ?', [$id]);
        if ($upload !== null && !empty($upload['file_path'])) {
            $path = APP_STORAGE . '/uploads/' . basename((string) $upload['file_path']);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        Db::run('DELETE FROM yx_warehouses WHERE id = ?', [$id]);
    }
}
