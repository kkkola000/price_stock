<?php
declare(strict_types=1);

namespace App\YmlExport;

use App\Support\Db;
use RuntimeException;
use XMLWriter;

/**
 * Сборка YML-фида в кэш-файл storage/feeds.
 *
 * Оффер = товар каталога, подтверждённый администратором; цены и прочие
 * поля берутся из позиций складов по правилам конструктора тегов
 * (столбец файла / семантическое поле / каталог / константа),
 * остатки — блоком outlets по всем складам с подтверждённой парой.
 */
final class FeedBuilder
{
    /**
     * Собирает фид и возвращает статистику.
     *
     * @return array{offers:int,path:string}
     */
    public static function build(): array
    {
        $settings = SettingsRepository::get();
        $mapping = $settings['offer_mapping_array'];
        $outlets = $settings['outlets_array'];
        $onlyConfirmed = (int) $settings['only_confirmed'] === 1;
        $stockMap = StockMapRepository::map();
        $stockFallback = (int) ($settings['stock_fallback'] ?? 0);
        $virtualWarehouses = WarehouseRepository::virtualList();

        $statuses = $onlyConfirmed ? "('confirmed')" : "('confirmed','suggested')";

        $rows = Db::all(
            "SELECT m.product_id, m.product_sku, m.warehouse_id, m.item_sku,
                    w.name AS warehouse_name, w.sort AS warehouse_sort,
                    i.name AS item_name, i.sku AS item_sku_live, i.stock_qty, i.stock_text,
                    i.price, i.oldprice, i.min_price, i.extra
             FROM yx_matches m
             JOIN yx_warehouses w ON w.id = m.warehouse_id AND w.is_active = 1 AND w.in_feed = 1
             LEFT JOIN yx_items i ON i.warehouse_id = m.warehouse_id AND i.sku = m.item_sku
             WHERE m.status IN {$statuses}
             ORDER BY m.product_id, w.sort, w.id"
        );

        // Группируем склады по товару каталога; без product_id — по позиции склада.
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['product_id'] !== null
                ? 'p' . $row['product_id']
                : 'w' . $row['warehouse_id'] . ':' . $row['item_sku'];
            $groups[$key][] = $row;
        }

        $dir = APP_STORAGE . '/feeds';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог storage/feeds (проверьте права).');
        }

        $file = self::path((string) $settings['token']);
        $temp = $file . '.tmp';

        $xml = new XMLWriter();
        $xml->openURI($temp);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('yml_catalog');
        $xml->writeAttribute('date', date('Y-m-d H:i'));
        $xml->startElement('shop');

        if ($settings['shop_name'] !== '') {
            $xml->writeElement('name', (string) $settings['shop_name']);
        }
        if ($settings['shop_company'] !== '') {
            $xml->writeElement('company', (string) $settings['shop_company']);
        }
        if ($settings['shop_url'] !== '') {
            $xml->writeElement('url', (string) $settings['shop_url']);
        }
        if ($settings['currency'] !== '') {
            $xml->startElement('currencies');
            $xml->startElement('currency');
            $xml->writeAttribute('id', (string) $settings['currency']);
            $xml->writeAttribute('rate', '1');
            $xml->endElement(); // currency
            $xml->endElement(); // currencies
        }

        $xml->startElement('offers');

        $offers = 0;
        $productCache = [];
        foreach ($groups as $group) {
            $offer = self::buildOffer($xml, $group, $mapping, $outlets, $settings, $productCache, $stockMap, $stockFallback, $virtualWarehouses);
            if ($offer) {
                $offers++;
            }
        }

        $xml->endElement(); // offers
        $xml->endElement(); // shop
        $xml->endElement(); // yml_catalog
        $xml->endDocument();
        $xml->flush();

        rename($temp, $file);

        SettingsRepository::markBuilt($offers);

        return ['offers' => $offers, 'path' => $file];
    }

    /** Путь к кэш-файлу фида для текущего токена. */
    public static function path(?string $token = null): string
    {
        if ($token === null) {
            $settings = SettingsRepository::get();
            $token = (string) $settings['token'];
        }

        return APP_STORAGE . '/feeds/yml_' . preg_replace('/[^a-f0-9]/i', '', $token) . '.xml';
    }

    /**
     * Пишет один <offer>; возвращает false, если оффер пропущен.
     *
     * @param list<array<string,mixed>> $group
     * @param list<array<string,mixed>> $mapping
     * @param array<string,mixed> $outlets
     * @param array<string,mixed> $settings
     * @param array<int,array<string,mixed>|null> $productCache
     * @param list<array{pattern:string,qty:int}> $stockMap
     * @param list<array<string,mixed>> $virtualWarehouses
     */
    private static function buildOffer(
        XMLWriter $xml,
        array $group,
        array $mapping,
        array $outlets,
        array $settings,
        array &$productCache,
        array $stockMap,
        int $stockFallback,
        array $virtualWarehouses
    ): bool {
        // Позиции склада могли быть удалены после сопоставления — такие строки пропускаем.
        $group = array_values(array_filter($group, static fn (array $row): bool => $row['item_sku_live'] !== null));
        if ($group === []) {
            return false;
        }

        // Приоритетный склад (меньше sort) — оттуда берутся значения тегов оффера.
        $primary = $group[0];

        // Остаток каждой строки: число из файла как есть, текст («Более 5») —
        // через соответствия администратора, иначе fallback из настроек.
        $totalStock = 0;
        foreach ($group as $idx => $row) {
            $group[$idx]['resolved_qty'] = StockMapRepository::resolve(
                $row['stock_qty'] !== null ? (int) $row['stock_qty'] : null,
                $row['stock_text'] !== null ? (string) $row['stock_text'] : null,
                $stockMap,
                $stockFallback
            );
            $totalStock += max(0, (int) $group[$idx]['resolved_qty']);
        }
        // Виртуальные склады в суммарный остаток не входят:
        // фильтр «исключать нулевые» смотрит только на реальные склады.
        if ((int) $settings['skip_zero_stock'] === 1 && $totalStock === 0) {
            return false;
        }

        $values = [];
        foreach ($mapping as $rule) {
            if (empty($rule['enabled']) || trim((string) ($rule['name'] ?? '')) === '') {
                continue;
            }
            $values[] = [
                'kind'  => (string) ($rule['kind'] ?? 'tag'),
                'name'  => trim((string) $rule['name']),
                'value' => self::resolveSource($rule['source'] ?? [], $primary, $group, $totalStock, $productCache),
            ];
        }

        // oldprice выводим только если он больше price — когда включено в настройках.
        if ((int) $settings['oldprice_only_higher'] === 1) {
            $price = self::numericTag($values, 'price');
            $old = self::numericTag($values, 'oldprice');
            if ($price !== null && $old !== null && $old <= $price) {
                $values = array_values(array_filter(
                    $values,
                    static fn (array $v): bool => !($v['kind'] === 'tag' && $v['name'] === 'oldprice')
                ));
            }
        }

        $xml->startElement('offer');
        foreach ($values as $v) {
            if ($v['kind'] === 'attribute') {
                if ($v['value'] !== '') {
                    $xml->writeAttribute($v['name'], $v['value']);
                }
            }
        }
        foreach ($values as $v) {
            if ($v['kind'] !== 'attribute' && $v['value'] !== '') {
                $xml->writeElement($v['name'], $v['value']);
            }
        }

        if (!empty($outlets['enabled'])) {
            $xml->startElement((string) ($outlets['parent'] ?? 'outlets'));
            foreach ($group as $row) {
                $xml->startElement((string) ($outlets['tag'] ?? 'outlet'));
                $xml->writeAttribute((string) ($outlets['stock_attr'] ?? 'instock'), (string) max(0, (int) ($row['resolved_qty'] ?? 0)));
                $xml->writeAttribute((string) ($outlets['name_attr'] ?? 'warehouse_name'), (string) $row['warehouse_name']);
                $xml->endElement();
            }
            // Виртуальные склады — у каждого оффера с постоянным остатком.
            foreach ($virtualWarehouses as $vw) {
                $xml->startElement((string) ($outlets['tag'] ?? 'outlet'));
                $xml->writeAttribute((string) ($outlets['stock_attr'] ?? 'instock'), (string) max(0, (int) ($vw['default_stock'] ?? 0)));
                $xml->writeAttribute((string) ($outlets['name_attr'] ?? 'warehouse_name'), (string) $vw['name']);
                $xml->endElement();
            }
            $xml->endElement(); // outlets
        }

        $xml->endElement(); // offer

        return true;
    }

    /**
     * Значение тега по правилу конструктора:
     * field — разобранное поле файла склада (price, oldprice, min_price, sku, name, stock);
     * column — сырой столбец по букве (A, B, C...) из загруженного файла;
     * catalog — поле товара каталога (sku, name);
     * constant — текстовая константа.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $primary
     * @param list<array<string,mixed>> $group
     * @param array<int,array<string,mixed>|null> $productCache
     */
    private static function resolveSource(array $source, array $primary, array $group, int $totalStock, array &$productCache): string
    {
        $type = (string) ($source['type'] ?? '');
        $ref = (string) ($source['ref'] ?? '');

        switch ($type) {
            case 'field':
                if ($ref === 'stock') {
                    return (string) $totalStock;
                }
                if (in_array($ref, ['price', 'oldprice', 'min_price'], true)) {
                    return self::formatNumber($primary[$ref] ?? null);
                }
                if ($ref === 'sku') {
                    return (string) ($primary['item_sku_live'] ?? '');
                }
                if ($ref === 'name') {
                    return (string) ($primary['item_name'] ?? '');
                }

                return '';

            case 'column':
                $extra = json_decode((string) ($primary['extra'] ?? ''), true);
                if (!is_array($extra)) {
                    return '';
                }
                $letter = strtoupper(trim($ref));

                return trim((string) ($extra[$letter] ?? ''));

            case 'catalog':
                $productId = $primary['product_id'] !== null ? (int) $primary['product_id'] : null;
                if ($productId === null) {
                    return $ref === 'sku' ? (string) ($primary['product_sku'] ?? '') : '';
                }
                if (!array_key_exists($productId, $productCache)) {
                    $productCache[$productId] = Db::first('SELECT id, sku, name FROM products WHERE id = ?', [$productId]);
                }
                $product = $productCache[$productId];
                if ($product === null) {
                    return $ref === 'sku' ? (string) ($primary['product_sku'] ?? '') : '';
                }

                return (string) ($product[$ref] ?? '');

            case 'constant':
                return $ref;
        }

        return '';
    }

    /** Число для XML: целые без дробной части (9760), дробные с точкой. */
    private static function formatNumber(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $number = (float) $value;

        return fmod($number, 1.0) === 0.0
            ? (string) (int) $number
            : number_format($number, 2, '.', '');
    }

    /** @param list<array{kind:string,name:string,value:string}> $values */
    private static function numericTag(array $values, string $name): ?float
    {
        foreach ($values as $v) {
            if ($v['kind'] === 'tag' && $v['name'] === $name && is_numeric($v['value'])) {
                return (float) $v['value'];
            }
        }

        return null;
    }
}
