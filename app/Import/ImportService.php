<?php
declare(strict_types=1);

namespace App\Import;

use App\Import\Readers\CsvReader;
use App\Import\Readers\ExcelReader;
use App\Import\Readers\RowReader;
use App\Import\Readers\XlsxReader;
use App\Import\Readers\YmlReader;
use App\Models\ImportRunRepository;
use App\Models\ProductRepository;
use App\Models\PriceChangeRepository;
use App\Models\SourceRepository;
use App\Support\Config;
use App\Support\Db;
use RuntimeException;
use Throwable;

final class ImportService
{
    private const BATCH_SIZE = 300;

    /** Читалка последнего импорта — чтобы узнать, сколько скрытого пропущено. */
    private static ?Readers\RowReader $lastReader = null;
    private const MAX_ERROR_SAMPLES = 5;

    /**
     * Полный цикл импорта одного источника.
     *
     * @param array<string,mixed> $source
     * @return array{status:string,rows_read:int,rows_imported:int,rows_skipped:int,rows_deleted:int,message:string}
     */
    public static function run(array $source, string $trigger = 'manual'): array
    {
        $sourceId = (int) $source['id'];
        $runId = ImportRunRepository::start($sourceId, $trigger);

        Db::run("UPDATE sources SET last_status = 'running', last_run_at = NOW(), last_error = NULL WHERE id = ?", [$sourceId]);

        $stats = ['rows_read' => 0, 'rows_imported' => 0, 'rows_skipped' => 0, 'rows_deleted' => 0];
        $errors = [];
        $tempFile = null;

        try {
            [$path, $isTemp] = Fetcher::resolve($source);
            $tempFile = $isTemp ? $path : null;

            PriceChangeRepository::snapshot($sourceId);
            $batch = [];
            self::$lastReader = null;
            $iterator = $source['type'] === 'yml'
                ? self::iterateYml($source, $path, $errors)
                : self::iterateTabular($source, $path, $errors);

            foreach ($iterator as $product) {
                $stats['rows_read']++;
                if ($product === null) {
                    $stats['rows_skipped']++;
                    continue;
                }

                $batch[$product['sku']] = $product; // дубли артикулов внутри файла схлопываем
                if (count($batch) >= self::BATCH_SIZE) {
                    $stats['rows_imported'] += ProductRepository::upsertBatch($sourceId, array_values($batch), $runId);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $stats['rows_imported'] += ProductRepository::upsertBatch($sourceId, array_values($batch), $runId);
            }

            if ($stats['rows_imported'] === 0) {
                throw new RuntimeException(
                    'Из файла не удалось импортировать ни одного товара. Проверьте настройки маппинга '
                    . 'и количество пропускаемых строк заголовка.'
                    . ($errors !== [] ? ' Первые ошибки: ' . implode('; ', $errors) : '')
                );
            }

            $stats['rows_deleted'] = ProductRepository::deleteStale($sourceId, $runId);
            $count = SourceRepository::refreshProductsCount($sourceId);

            $message = sprintf(
                'Импортировано товаров: %d, пропущено строк: %d, удалено отсутствующих: %d. Всего в каталоге по поставщику: %d.',
                $stats['rows_imported'],
                $stats['rows_skipped'],
                $stats['rows_deleted'],
                $count
            );
            $hidden = self::hiddenSummary();
            if ($hidden !== '') {
                $message .= ' ' . $hidden;
            }
            if ($errors !== []) {
                $message .= ' Примеры пропусков: ' . implode('; ', $errors);
            }

            PriceChangeRepository::record($sourceId, $runId);
            ImportRunRepository::finish($runId, 'ok', $stats, $message);
            Db::run(
                "UPDATE sources SET last_status = 'ok', last_success_at = NOW(), last_error = NULL WHERE id = ?",
                [$sourceId]
            );

            return $stats + ['status' => 'ok', 'message' => $message];
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            ImportRunRepository::finish($runId, 'error', $stats, $message);
            Db::run("UPDATE sources SET last_status = 'error', last_error = ? WHERE id = ?", [
                mb_substr($message, 0, 2000),
                $sourceId,
            ]);

            return $stats + ['status' => 'error', 'message' => $message];
        } finally {
            if ($tempFile !== null && is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Импорт нескольких источников (ручной «Обновить всё» и cron).
     *
     * @param list<array<string,mixed>> $sources
     * @return list<array<string,mixed>>
     */
    public static function runMany(array $sources, string $trigger): array
    {
        $results = [];
        foreach ($sources as $source) {
            $result = self::run($source, $trigger);
            $result['source_id'] = (int) $source['id'];
            $result['source_name'] = (string) $source['name'];
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Предпросмотр файла для настройки маппинга: сырые строки/теги + результат разбора.
     *
     * @param array<string,mixed> $source
     * @return array{raw:list<list<string>>,mapped:list<array<string,mixed>>,tags:list<string>,errors:list<string>}
     */
    public static function preview(array $source, int $limit = 8): array
    {
        [$path, $isTemp] = Fetcher::resolve($source);
        $errors = [];

        try {
            if ($source['type'] === 'yml') {
                $reader = new YmlReader($path);
                $preview = $reader->preview($limit);
                $raw = [];
                foreach ($preview['offers'] as $offer) {
                    $line = [];
                    foreach ($offer as $tag => $values) {
                        $line[] = $tag . ': ' . mb_substr(implode(' | ', $values), 0, 80);
                    }
                    $raw[] = $line;
                }

                $mapped = [];
                foreach ($preview['offers'] as $offer) {
                    $product = self::mapOffer($offer, $source, $errors);
                    if ($product !== null) {
                        $mapped[] = $product;
                    }
                }

                return ['raw' => $raw, 'mapped' => $mapped, 'tags' => $preview['tags'], 'errors' => $errors];
            }

            $reader = self::tabularReader($source, $path);
            $raw = $reader->preview($limit + (int) $source['skip_rows']);

            $mapped = [];
            $skip = (int) $source['skip_rows'];
            foreach (array_slice($raw, $skip) as $row) {
                $product = self::mapRow($row, $source, $errors);
                if ($product !== null) {
                    $mapped[] = $product;
                }
            }

            return ['raw' => $raw, 'mapped' => $mapped, 'tags' => [], 'errors' => $errors];
        } finally {
            if ($isTemp && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $errors
     * @return \Generator<int, array<string,mixed>|null>
     */
    private static function iterateTabular(array $source, string $path, array &$errors): \Generator
    {
        $reader = self::tabularReader($source, $path);
        self::$lastReader = $reader;
        $skip = (int) $source['skip_rows'];
        $line = 0;

        foreach ($reader->rows() as $row) {
            $line++;
            if ($line <= $skip) {
                continue;
            }
            if ($row === [] || trim(implode('', $row)) === '') {
                continue; // полностью пустая строка не считается ошибкой
            }

            yield self::mapRow($row, $source, $errors, $line);
        }
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $errors
     * @return \Generator<int, array<string,mixed>|null>
     */
    private static function iterateYml(array $source, string $path, array &$errors): \Generator
    {
        $reader = new YmlReader($path);
        $line = 0;
        foreach ($reader->offers() as $offer) {
            $line++;
            yield self::mapOffer($offer, $source, $errors, $line);
        }
    }

    private static function tabularReader(array $source, string $path): RowReader
    {
        return $source['type'] === 'excel'
            ? ExcelReader::open(
                $path,
                max(1, (int) $source['sheet_index']),
                (int) ($source['skip_hidden'] ?? 1) === 1
            )
            : new CsvReader($path, (string) $source['csv_delimiter'], (string) $source['csv_encoding']);
    }

    /**
     * @param list<string> $row
     * @param array<string,mixed> $source
     * @param list<string> $errors
     * @return array<string,mixed>|null
     */
    private static function mapRow(array $row, array $source, array &$errors, int $line = 0): ?array
    {
        $mapping = $source['mapping_array'] ?? [];
        $get = static fn (string $field): string => ColumnRef::value($row, self::indexCache($mapping, $field));

        return self::buildProduct(
            $get('name'),
            $get('sku'),
            $get('price'),
            $get('stock'),
            $get('image'),
            $get('currency'),
            $source,
            $errors,
            $line,
            $get('promo_price')
        );
    }

    /** Кэш разобранных ссылок на столбцы в пределах запроса. */
    private static function indexCache(array $mapping, string $field): ?int
    {
        static $cache = [];
        $key = md5(json_encode($mapping, JSON_UNESCAPED_UNICODE) . '|' . $field);
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = ColumnRef::toIndex((string) ($mapping[$field] ?? ''));
        }

        return $cache[$key];
    }

    /**
     * @param array<string, list<string>> $offer
     * @param array<string,mixed> $source
     * @param list<string> $errors
     * @return array<string,mixed>|null
     */
    private static function mapOffer(array $offer, array $source, array &$errors, int $line = 0): ?array
    {
        $mapping = $source['mapping_array'] ?? [];
        $get = static function (string $field) use ($offer, $mapping): string {
            $tag = trim((string) ($mapping[$field] ?? ''));
            if ($tag === '') {
                return '';
            }
            foreach ([$tag, '@' . ltrim($tag, '@'), ltrim($tag, '@')] as $key) {
                if (isset($offer[$key][0]) && $offer[$key][0] !== '') {
                    return $offer[$key][0];
                }
            }

            return '';
        };

        $name = $get('name');
        if ($name === '' && isset($offer['model'][0])) {
            $vendor = $offer['vendor'][0] ?? '';
            $name = trim($vendor . ' ' . $offer['model'][0]);
        }

        $sku = $get('sku');
        if ($sku === '') {
            $sku = $offer['@id'][0] ?? '';
        }

        return self::buildProduct(
            $name,
            $sku,
            $get('price'),
            $get('stock'),
            $get('image'),
            $get('currency'),
            $source,
            $errors,
            $line,
            $get('promo_price')
        );
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $errors
     * @return array<string,mixed>|null
     */
    private static function buildProduct(
        string $rawName,
        string $rawSku,
        string $rawPrice,
        string $rawStock,
        string $rawImage,
        string $rawCurrency,
        array $source,
        array &$errors,
        int $line = 0,
        string $rawPromoPrice = ''
    ): ?array {
        $mapping = $source['mapping_array'] ?? [];

        $name = ValueParser::text($rawName, 500);
        $sku  = ValueParser::text($rawSku, 190);

        // Поля маппинга необязательные: название и артикул подстраховывают друг друга,
        // и только когда пусты оба — показывать в каталоге нечего.
        if ($name === '' && $sku === '') {
            self::addError($errors, $line, 'нет ни названия, ни артикула');
            return null;
        }
        if ($name === '') {
            $name = $sku;
        }
        if ($sku === '') {
            // Артикул не задан — делаем устойчивый ключ по названию,
            // чтобы при повторных импортах товар обновлялся, а не дублировался
            $sku = 'auto-' . substr(md5(mb_strtolower($name, 'UTF-8')), 0, 16);
        }

        // Цену показываем так же, как остаток — ровно как записано в прайсе.
        // Разобранное число нужно только для сортировки по цене.
        $priceText = ValueParser::text($rawPrice, 190);
        $price = ValueParser::price($rawPrice);

        $stockMapped = trim((string) ($mapping['stock'] ?? '')) !== '';
        $stock = $stockMapped
            ? ValueParser::stock(
                $rawStock,
                SourceRepository::DEFAULT_IN_STOCK_VALUES,
                SourceRepository::DEFAULT_OUT_OF_STOCK_VALUES
            )
            // Колонка остатка не указана — наличие не показываем и товар не прячем в конец списка
            : ['qty' => null, 'status' => 'in_stock', 'text' => ''];

        // Валюта: сначала из файла, затем заданная для источника, затем общая по умолчанию.
        // Обозначение источника прогоняем через тот же разбор, что и значение из файла,
        // чтобы «тг» стало KZT, а незнакомое «сум» осталось как есть.
        $fallbackCurrency = ValueParser::currency(
            (string) ($source['currency_code'] ?? ''),
            (string) Config::get('default_currency', 'RUB')
        );

        return [
            'sku'          => $sku,
            'name'         => $name,
            'price'        => $price,
            'price_text'   => $priceText,
            'promo_price'  => ValueParser::price($rawPromoPrice),
            'promo_price_text' => ValueParser::text($rawPromoPrice, 190),
            'currency'     => ValueParser::currency($rawCurrency, $fallbackCurrency),
            'stock_qty'    => $stock['qty'],
            'stock_text'   => $stock['text'],
            'availability' => $stock['status'],
            'image_url'    => ValueParser::imageUrl($rawImage),
            'extra'        => null,
        ];
    }

    /** Текст о пропущенных скрытых строках и столбцах — для журнала импортов. */
    private static function hiddenSummary(): string
    {
        if (!self::$lastReader instanceof Readers\SkipsHidden) {
            return '';
        }

        $rows = self::$lastReader->hiddenRowsSkipped();
        $columns = self::$lastReader->hiddenColumnsSkipped();
        if ($rows === 0 && $columns === 0) {
            return '';
        }

        $parts = [];
        if ($rows > 0) {
            $parts[] = 'строк: ' . $rows;
        }
        if ($columns > 0) {
            $parts[] = 'столбцов: ' . $columns;
        }

        return 'Пропущено скрытого в книге — ' . implode(', ', $parts) . '.';
    }

    /** @param list<string> $errors */
    private static function addError(array &$errors, int $line, string $reason): void
    {
        if (count($errors) >= self::MAX_ERROR_SAMPLES) {
            return;
        }
        $errors[] = ($line > 0 ? 'строка ' . $line . ': ' : '') . $reason;
    }
}
