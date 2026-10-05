<?php
declare(strict_types=1);

namespace App\YmlExport;

use App\Import\ColumnRef;
use App\Import\Fetcher;
use App\Import\Readers\CsvReader;
use App\Import\Readers\ExcelReader;
use App\Import\Readers\RowReader;
use App\Import\Readers\YmlReader;
use App\Import\ValueParser;
use App\Models\SourceRepository;
use App\Support\Db;
use RuntimeException;
use Throwable;

/**
 * Импорт файла склада в yx_items.
 * Читалки и получение файла — общие с основным каталогом (app/Import),
 * дальше данные идут в свои таблицы, основной каталог не затрагивается.
 */
final class UploadImporter
{
    private const MAX_EXTRA_COLUMNS = 30;

    /**
     * Полный цикл импорта одной загрузки.
     *
     * @param array<string,mixed> $upload
     * @return array{status:string,message:string}
     */
    public static function run(array $upload): array
    {
        $uploadId = (int) $upload['id'];
        $warehouseId = (int) $upload['warehouse_id'];
        Db::run("UPDATE yx_uploads SET last_status = 'running', last_run_at = NOW(), last_error = NULL WHERE id = ?", [$uploadId]);

        $stats = ['read' => 0, 'imported' => 0, 'skipped' => 0, 'deleted' => 0];
        $tempFile = null;

        try {
            [$path, $isTemp] = Fetcher::resolve($upload);
            $tempFile = $isTemp ? $path : null;

            $mapping = UploadRepository::mappingOf($upload);
            $batch = bin2hex(random_bytes(8));
            $iterator = $upload['type'] === 'yml'
                ? self::iterateYml($upload, $path)
                : self::iterateTabular($upload, $path);

            foreach ($iterator as $raw) {
                $stats['read']++;
                $item = self::buildItem($raw, $mapping);
                if ($item === null) {
                    $stats['skipped']++;
                    continue;
                }
                self::upsert($uploadId, $warehouseId, $batch, $item);
                $stats['imported']++;
            }

            if ($stats['imported'] === 0) {
                throw new RuntimeException(
                    'Из файла не удалось загрузить ни одной позиции. Проверьте маппинг столбцов '
                    . 'и количество пропускаемых строк заголовка.'
                );
            }

            // Позиции, которых нет в свежем файле, убираем — как в основном каталоге.
            $stmt = Db::run('DELETE FROM yx_items WHERE upload_id = ? AND import_batch <> ?', [$uploadId, $batch]);
            $stats['deleted'] = $stmt->rowCount();

            $count = (int) Db::scalar('SELECT COUNT(*) FROM yx_items WHERE upload_id = ?', [$uploadId]);
            UploadRepository::markStatus($uploadId, 'ok', null, $count);

            // После обновления остатков — пересчитываем автосопоставление склада.
            Matcher::autoForWarehouse($warehouseId);

            return [
                'status'  => 'ok',
                'message' => sprintf(
                    'Загружено позиций: %d, пропущено строк: %d, удалено отсутствующих: %d.',
                    $stats['imported'],
                    $stats['skipped'],
                    $stats['deleted']
                ),
            ];
        } catch (Throwable $exception) {
            UploadRepository::markStatus($uploadId, 'error', $exception->getMessage(), 0);

            return ['status' => 'error', 'message' => $exception->getMessage()];
        } finally {
            if ($tempFile !== null && is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Предпросмотр файла для проверки маппинга: первые строки как есть.
     *
     * @param array<string,mixed> $upload
     * @return list<list<string>>
     */
    public static function preview(array $upload, int $limit = 8): array
    {
        [$path, $isTemp] = Fetcher::resolve($upload);

        try {
            if ($upload['type'] === 'yml') {
                $reader = new YmlReader($path);
                $preview = $reader->preview($limit);
                $rows = [];
                foreach ($preview['offers'] as $offer) {
                    $line = [];
                    foreach ($offer as $tag => $values) {
                        $line[] = $tag . ': ' . mb_substr(implode(' | ', $values), 0, 80);
                    }
                    $rows[] = $line;
                }

                return $rows;
            }

            return self::tabularReader($upload, $path)->preview($limit + max(0, (int) $upload['skip_rows']));
        } finally {
            if ($isTemp && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Строки табличного файла как «сырые» массивы (для extra — по буквам столбцов).
     *
     * @param array<string,mixed> $upload
     * @return \Generator<int, list<string>>
     */
    private static function iterateTabular(array $upload, string $path): \Generator
    {
        $reader = self::tabularReader($upload, $path);
        $skip = max(0, (int) $upload['skip_rows']);
        $line = 0;

        foreach ($reader->rows() as $row) {
            $line++;
            if ($line <= $skip) {
                continue;
            }
            if ($row === [] || trim(implode('', $row)) === '') {
                continue;
            }

            yield $row;
        }
    }

    /**
     * Офферы YML: значения тегов идут в extra по имени тега.
     *
     * @param array<string,mixed> $upload
     * @return \Generator<int, array<string, list<string>>>
     */
    private static function iterateYml(array $upload, string $path): \Generator
    {
        $reader = new YmlReader($path);
        foreach ($reader->offers() as $offer) {
            yield $offer;
        }
    }

    /** @param array<string,mixed> $upload */
    private static function tabularReader(array $upload, string $path): RowReader
    {
        return $upload['type'] === 'excel'
            ? ExcelReader::open(
                $path,
                max(1, (int) $upload['sheet_index']),
                (int) ($upload['skip_hidden'] ?? 1) === 1
            )
            : new CsvReader($path, (string) $upload['csv_delimiter'], (string) $upload['csv_encoding']);
    }

    /**
     * Собирает позицию склада из строки файла или YML-оффера.
     *
     * @param list<string>|array<string, list<string>> $raw
     * @param array<string,string> $mapping
     * @return array<string,mixed>|null
     */
    private static function buildItem(array $raw, array $mapping): ?array
    {
        $isYml = !array_is_list($raw);

        // Значение поля: из столбца по букве/номеру или из тега оффера.
        $get = static function (string $field) use ($raw, $mapping, $isYml): string {
            $ref = trim((string) ($mapping[$field] ?? ''));
            if ($ref === '') {
                return '';
            }
            if ($isYml) {
                /** @var array<string, list<string>> $raw */
                foreach ([$ref, '@' . ltrim($ref, '@'), ltrim($ref, '@')] as $key) {
                    if (isset($raw[$key][0]) && $raw[$key][0] !== '') {
                        return trim((string) $raw[$key][0]);
                    }
                }

                return '';
            }

            /** @var list<string> $raw */
            try {
                return ColumnRef::value($raw, ColumnRef::toIndex($ref));
            } catch (Throwable) {
                return '';
            }
        };

        $name = ValueParser::text($get('name'), 500);
        $sku  = ValueParser::text($get('sku'), 190);

        if ($name === '' && $sku === '') {
            return null;
        }
        if ($name === '') {
            $name = $sku;
        }
        if ($sku === '') {
            // Устойчивый ключ по названию — как в основном импорте,
            // чтобы повторные загрузки обновляли позицию, а не дублировали.
            $sku = 'auto-' . substr(md5(mb_strtolower($name, 'UTF-8')), 0, 16);
        }

        $rawStock = $get('stock');
        $stock = trim((string) ($mapping['stock'] ?? '')) !== ''
            ? ValueParser::stock(
                $rawStock,
                SourceRepository::DEFAULT_IN_STOCK_VALUES,
                SourceRepository::DEFAULT_OUT_OF_STOCK_VALUES
            )
            : ['qty' => null, 'text' => ''];

        // Сырые значения всех столбцов — конструктор тегов фида привязывается к ним.
        $extra = [];
        if ($isYml) {
            /** @var array<string, list<string>> $raw */
            foreach ($raw as $tag => $values) {
                $extra[(string) $tag] = mb_substr((string) ($values[0] ?? ''), 0, 500);
            }
        } else {
            /** @var list<string> $raw */
            foreach (array_slice($raw, 0, self::MAX_EXTRA_COLUMNS) as $index => $value) {
                $extra[ColumnRef::toLetter($index)] = mb_substr(trim((string) $value), 0, 500);
            }
        }

        return [
            'sku'        => $sku,
            'name'       => $name,
            'stock_qty'  => $stock['qty'],
            'stock_text' => ValueParser::text($rawStock, 190),
            'price'      => ValueParser::price($get('price')),
            'oldprice'   => ValueParser::price($get('oldprice')),
            'min_price'  => ValueParser::price($get('min_price')),
            'extra'      => json_encode($extra, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @param array<string,mixed> $item */
    private static function upsert(int $uploadId, int $warehouseId, string $batch, array $item): void
    {
        Db::run(
            'INSERT INTO yx_items
                (upload_id, warehouse_id, sku, name, stock_qty, stock_text, price, oldprice, min_price, extra, import_batch)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                upload_id = VALUES(upload_id), name = VALUES(name), stock_qty = VALUES(stock_qty),
                stock_text = VALUES(stock_text), price = VALUES(price), oldprice = VALUES(oldprice),
                min_price = VALUES(min_price), extra = VALUES(extra), import_batch = VALUES(import_batch)',
            [
                $uploadId,
                $warehouseId,
                $item['sku'],
                $item['name'],
                $item['stock_qty'],
                $item['stock_text'],
                $item['price'],
                $item['oldprice'],
                $item['min_price'],
                $item['extra'],
                $batch,
            ]
        );
    }
}
