<?php
declare(strict_types=1);

namespace App\YmlExport;

use App\Support\Db;

/**
 * Загрузки файлов складов: одна активная загрузка на склад.
 * Структура намеренно повторяет таблицу sources основного каталога,
 * чтобы работали те же читалки файлов (Fetcher, ExcelReader, CsvReader, YmlReader).
 */
final class UploadRepository
{
    /** Поля маппинга файла склада: ключ => подпись в интерфейсе. */
    public const FIELDS = [
        'sku'       => 'Артикул',
        'name'      => 'Название',
        'stock'     => 'Остаток',
        'price'     => 'Цена',
        'oldprice'  => 'Старая цена (oldprice)',
        'min_price' => 'Мин. цена (min_price)',
    ];

    public const TYPES = ['excel' => 'Excel', 'csv' => 'CSV', 'yml' => 'YML-фид'];

    /** @return list<array<string,mixed>> */
    public static function list(): array
    {
        return Db::all(
            'SELECT u.*, w.name AS warehouse_name
             FROM yx_uploads u
             JOIN yx_warehouses w ON w.id = u.warehouse_id
             ORDER BY w.sort, w.id'
        );
    }

    /**
     * Загрузки для автообновления по расписанию вместе с панелью:
     * файлы, получаемые по ссылке, у активных складов типа «из файла».
     * Ручные файлы (fetch_method = upload) не трогаем — они не меняются сами.
     *
     * @return list<array<string,mixed>>
     */
    public static function listAutoImportable(): array
    {
        return Db::all(
            "SELECT u.*, w.name AS warehouse_name
             FROM yx_uploads u
             JOIN yx_warehouses w ON w.id = u.warehouse_id AND w.is_active = 1 AND w.kind <> 'virtual'
             WHERE u.fetch_method = 'url'
             ORDER BY w.sort, w.id"
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::first('SELECT * FROM yx_uploads WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByWarehouse(int $warehouseId): ?array
    {
        return Db::first('SELECT * FROM yx_uploads WHERE warehouse_id = ?', [$warehouseId]);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        Db::run(
            'INSERT INTO yx_uploads
                (warehouse_id, type, fetch_method, source_url, file_path, original_filename,
                 csv_delimiter, csv_encoding, skip_rows, sheet_index, skip_hidden, mapping)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['warehouse_id'],
                $data['type'],
                $data['fetch_method'],
                $data['source_url'],
                $data['file_path'],
                $data['original_filename'],
                $data['csv_delimiter'],
                $data['csv_encoding'],
                $data['skip_rows'],
                $data['sheet_index'],
                $data['skip_hidden'],
                json_encode($data['mapping'], JSON_UNESCAPED_UNICODE),
            ]
        );

        return Db::lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Db::run(
            'UPDATE yx_uploads SET
                type = ?, fetch_method = ?, source_url = ?, csv_delimiter = ?, csv_encoding = ?,
                skip_rows = ?, sheet_index = ?, skip_hidden = ?, mapping = ?
             WHERE id = ?',
            [
                $data['type'],
                $data['fetch_method'],
                $data['source_url'],
                $data['csv_delimiter'],
                $data['csv_encoding'],
                $data['skip_rows'],
                $data['sheet_index'],
                $data['skip_hidden'],
                json_encode($data['mapping'], JSON_UNESCAPED_UNICODE),
                $id,
            ]
        );
    }

    public static function setFile(int $id, string $filePath, string $originalName): void
    {
        Db::run('UPDATE yx_uploads SET file_path = ?, original_filename = ? WHERE id = ?', [
            $filePath,
            mb_substr($originalName, 0, 255),
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $upload = self::find($id);
        if ($upload !== null && !empty($upload['file_path'])) {
            $path = APP_STORAGE . '/uploads/' . basename((string) $upload['file_path']);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        Db::run('DELETE FROM yx_items WHERE upload_id = ?', [$id]);
        Db::run('DELETE FROM yx_uploads WHERE id = ?', [$id]);
    }

    public static function markStatus(int $id, string $status, ?string $error, int $itemsCount): void
    {
        Db::run(
            'UPDATE yx_uploads SET last_status = ?, last_run_at = NOW(), last_error = ?, items_count = ? WHERE id = ?',
            [$status, $error === null ? null : mb_substr($error, 0, 2000), $itemsCount, $id]
        );
    }

    /** @return array<string,string> */
    public static function mappingOf(array $upload): array
    {
        $mapping = json_decode((string) ($upload['mapping'] ?? ''), true);

        return is_array($mapping) ? array_map('strval', $mapping) : [];
    }
}
