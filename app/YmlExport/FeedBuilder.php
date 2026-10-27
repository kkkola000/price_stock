<?php
declare(strict_types=1);

namespace App\YmlExport;

use App\Support\Db;
use RuntimeException;
use XMLWriter;

/**
 * Сборка YML-фидов в кэш-файлы storage/feeds — по файлу на каждую ссылку.
 *
 * Оффер = товар каталога, подтверждённый администратором; артикул и название
 * берутся из загруженного файла склада, цена и остаток — из карточки каталога
 * (если в каталоге остатка нет — значение из файла по соответствиям
 * администратора). Прочие поля — по правилам конструктора тегов (столбец
 * файла / семантическое поле / каталог / константа), остатки — блоком
 * outlets по складам, выбранным для конкретной ссылки.
 */
final class FeedBuilder
{
    /**
     * Собирает все ссылки и возвращает суммарную статистику.
     *
     * @return array{feeds:int,offers:int}
     */
    public static function buildAll(): array
    {
        $feeds = FeedRepository::list();
        $offers = 0;
        foreach ($feeds as $feed) {
            $offers += self::buildFeed($feed)['offers'];
        }

        return ['feeds' => count($feeds), 'offers' => $offers];
    }

    /**
     * Собирает один фид по строке ссылки (yx_feeds + warehouse_ids)
     * и возвращает статистику. Ссылка без складов даёт пустой фид.
     *
     * @param array<string,mixed> $feed
     * @return array{offers:int,path:string}
     */
    public static function buildFeed(array $feed, ?array $settingsOverride=null, ?array $stockMapOverride=null, bool $preview=false): array
    {
        $settings = $settingsOverride ?? SettingsRepository::get();
        $template=$settings['outlets_array']['template']??null;
        $compiled=is_array($template)?XmlTemplate::compile((string)$template['text'],(array)$template['variables']):null;
        $mapping = $settings['offer_mapping_array'];
        if ($compiled===null) XmlMapping::validate($mapping);
        $outlets = $settings['outlets_array'];
        foreach (['root_name','shop_name','offers_name','offer_name'] as $name) {
            if (isset($outlets[$name]) && !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $outlets[$name])) throw new RuntimeException('Некорректное имя XML.');
        }
        $stockMap = $stockMapOverride ?? StockMapRepository::map();
        $stockFallback = (int) ($settings['stock_fallback'] ?? 0);

        $warehouseIds = array_values(array_filter(array_map('intval', $feed['warehouse_ids'] ?? [])));
        $virtualWarehouses = WarehouseRepository::virtualList($warehouseIds);

        $groups = self::groups($feed,$settings);

        $dir = APP_STORAGE . '/feeds';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог storage/feeds (проверьте права).');
        }

        $file = $preview ? APP_STORAGE.'/tmp/yml-preview-'.bin2hex(random_bytes(12)).'.xml' : self::path((string) $feed['token']);
        $temp = $file . '.tmp';

        $xml = new XMLWriter();
        $xml->openURI($temp);
        $xml->startDocument('1.0', 'UTF-8');
        $offers = 0;
        $skipped=['missing_items'=>0,'zero_stock'=>0,'other_product'=>0];
        $onSkip=static function(string $reason)use(&$skipped):void {$skipped[$reason]++;};
        $productCache = [];
        if ($compiled!==null) {
            $eachProduct=static function(callable $emit)use($groups,$xml,$mapping,$outlets,$settings,&$productCache,$stockMap,$stockFallback,$virtualWarehouses,&$offers,$onSkip,&$skipped):void {
                foreach($groups as $group) {
                    if(!empty($settings['preview_product_id'])&&(int)$group[0]['product_id']!==(int)$settings['preview_product_id']){$skipped['other_product']++;continue;}
                    if(self::buildOffer($xml,$group,$mapping,$outlets,$settings,$productCache,$stockMap,$stockFallback,$virtualWarehouses,$emit,$onSkip))$offers++;
                }
            };
            XmlTemplate::write($xml,$compiled,(array)$template['variables'],$eachProduct,(int)$settings['oldprice_only_higher']===1);
        } else {
        $xml->startElement((string) ($outlets['root_name'] ?? 'yml_catalog'));
        $rootDate = array_filter($mapping, static fn($node)=>!empty($node['enabled'])&&$node['parent']==='_catalog'&&$node['kind']==='attribute'&&$node['name']==='date');
        if ($rootDate===[]) $xml->writeAttribute('date', date('Y-m-d H:i'));
        $constant = static fn(array $source, ?array $warehouse): string => (string)($source['ref']??'');
        XmlMapping::write($xml,$mapping,$constant,[],'_catalog');
        $xml->startElement((string) ($outlets['shop_name'] ?? 'shop'));
        XmlMapping::write($xml,$mapping,$constant,[],'_shop');

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

        $xml->startElement((string) ($outlets['offers_name'] ?? 'offers'));
        XmlMapping::write($xml,$mapping,$constant,[],'_offers');

        $offers = 0;
        $productCache = [];
        foreach ($groups as $group) {
            $offer = self::buildOffer($xml, $group, $mapping, $outlets, $settings, $productCache, $stockMap, $stockFallback, $virtualWarehouses, null, $onSkip);
            if ($offer) {
                $offers++;
            }
        }

        $xml->endElement(); // offers
        $xml->endElement(); // shop
        $xml->endElement(); // yml_catalog
        }
        $xml->endDocument();
        $xml->flush();

        // Validate the complete document before replacing the last working cache.
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader();
        try {
            if (!$reader->open($temp, null, LIBXML_NONET)) throw new RuntimeException('Не удалось проверить XML.');
            while ($reader->read()) {}
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) throw new RuntimeException('Некорректный XML: ' . trim($error->message));
            }
        } catch (\Throwable $exception) {
            @unlink($temp);
            throw $exception;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        if (!rename($temp, $file)) {
            @unlink($temp);
            throw new RuntimeException('Не удалось сохранить готовый XML.');
        }

        if (!$preview) FeedRepository::markBuilt((int) ($feed['id'] ?? 0), $offers);

        return ['offers' => $offers, 'path' => $file, 'diagnostic'=>$offers===0?self::diagnose($feed,$settings,$skipped):null];
    }

    /** Same confirmed matches, source-file identifiers and resolved catalog stock as YML. */
    public static function apiStocks(array $feed): array
    {
        $settings=SettingsRepository::get();
        $settings['only_confirmed']=1;
        // Omitting a zero would leave the previous nonzero stock on the marketplace.
        $settings['skip_zero_stock']=0;
        $groups=self::groups($feed,$settings);$cache=[];$stocks=[];$stockMap=StockMapRepository::map();
        $writer=new XMLWriter();$writer->openMemory();
        foreach($groups as $group) {
            self::buildOffer($writer,$group,[],[],$settings,$cache,$stockMap,(int)($settings['stock_fallback']??0),[],
                static function(callable $resolve,array $warehouses)use(&$stocks):void {
                    $id=trim($resolve(['type'=>'field','ref'=>'sku'],null));
                    if($id==='')throw new RuntimeException('У позиции нет артикула из файла склада.');
                    if(isset($stocks[$id]))throw new RuntimeException('Артикул «'.$id.'» сопоставлен с несколькими товарами. Исправьте сопоставления перед отправкой.');
                    $stocks[$id]=['offer_id'=>$id,'stock'=>(int)$resolve(['type'=>'resolved','ref'=>'stock'],null)];
                });
        }
        ksort($stocks,SORT_STRING);
        return array_values($stocks);
    }

    private static function groups(array $feed,array $settings): array
    {
        $onlyConfirmed=(int)$settings['only_confirmed']===1;
        $warehouseIds=array_values(array_filter(array_map('intval',$feed['warehouse_ids']??[])));
        $statuses = $onlyConfirmed ? "('confirmed')" : "('confirmed','suggested')";

        if ($warehouseIds === []) {
            $rows = [];
        } else {
            $placeholders = implode(',', array_fill(0, count($warehouseIds), '?'));
            $rows = Db::all(
                "SELECT m.product_id, m.product_sku, m.warehouse_id, m.item_sku,
                        w.name AS warehouse_name, w.sort AS warehouse_sort,
                        i.name AS item_name, i.sku AS item_sku_live, i.stock_qty, i.stock_text,
                        i.price, i.oldprice, i.min_price, i.extra
                 FROM yx_matches m
                 JOIN yx_warehouses w ON w.id = m.warehouse_id AND w.is_active = 1
                 LEFT JOIN yx_items i ON i.warehouse_id = m.warehouse_id AND i.sku = m.item_sku
                 WHERE m.status IN {$statuses} AND m.warehouse_id IN ({$placeholders})
                 ORDER BY m.product_id, w.sort, w.id",
                $warehouseIds
            );
        }

        // Группируем склады по товару каталога; без product_id — по позиции склада.
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['product_id'] !== null
                ? 'p' . $row['product_id']
                : 'w' . $row['warehouse_id'] . ':' . $row['item_sku'];
            $groups[$key][] = $row;
        }

        return $groups;
    }

    /** Explain an empty result using the selected link's actual source data. */
    public static function diagnose(array $feed,array $settings, array $skipped=[]): array
    {
        $ids=array_values(array_filter(array_map('intval',$feed['warehouse_ids']??[])));
        $stats=['selected'=>count($ids),'active'=>0,'items'=>0,'confirmed'=>0,'suggested'=>0];
        if($ids!==[]) {
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $stats['active']=(int)Db::scalar("SELECT COUNT(*) FROM yx_warehouses WHERE id IN ($marks) AND is_active=1",$ids);
            $stats['items']=(int)Db::scalar("SELECT COUNT(*) FROM yx_items i JOIN yx_warehouses w ON w.id=i.warehouse_id AND w.is_active=1 WHERE i.warehouse_id IN ($marks)",$ids);
            $counts=Db::all("SELECT m.status,COUNT(*) AS n FROM yx_matches m JOIN yx_warehouses w ON w.id=m.warehouse_id AND w.is_active=1 JOIN yx_items i ON i.warehouse_id=m.warehouse_id AND i.sku=m.item_sku WHERE m.warehouse_id IN ($marks) GROUP BY m.status",$ids);
            foreach($counts as $row)if(in_array($row['status'],['confirmed','suggested'],true))$stats[$row['status']]=(int)$row['n'];
        }
        $message=match(true) {
            $stats['selected']===0=>'Для этой ссылки не выбраны склады. Откройте «Готовый фид → Изменить» и отметьте нужные склады.',
            $stats['active']===0=>'Выбранные склады отключены или удалены. Включите склад либо выберите другой для этой ссылки.',
            $stats['items']===0=>'В выбранных активных складах нет загруженных товаров. Загрузите файл остатков. Виртуальный склад сам по себе не создаёт товары.',
            (int)$settings['only_confirmed']===1&&$stats['confirmed']===0=>'В выбранных складах нет подтверждённых сопоставлений. Подтвердите позиции во вкладке «Сопоставление» — они должны появиться в «Товары».',
            $stats['confirmed']+$stats['suggested']===0=>'Нет сопоставленных товаров в выбранных складах. Выполните сопоставление с витриной.',
            !empty($settings['preview_product_id'])&&!empty($skipped['other_product'])&&empty($skipped['zero_stock'])=>'Выбранный товар не входит в сопоставления этой ссылки. Выберите «Все товары выбранного фида» или другой товар.',
            !empty($skipped['zero_stock'])=>'Подходящие товары исключены настройкой «Исключать офферы с нулевым суммарным остатком». Проверьте соответствия остатков или отключите этот фильтр.',
            !empty($skipped['missing_items'])=>'Сопоставления ссылаются на отсутствующие позиции файла. Повторно загрузите актуальный файл склада.',
            default=>'Сопоставления есть. Проверьте выбранный товар, фильтр нулевых остатков и пересоберите фид.',
        };
        return ['message'=>$message,'stats'=>$stats];
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
        array $virtualWarehouses,
        ?callable $consume=null,
        ?callable $onSkip=null
    ): bool {
        // Позиции склада могли быть удалены после сопоставления — такие строки пропускаем.
        $group = array_values(array_filter($group, static fn (array $row): bool => $row['item_sku_live'] !== null));
        if ($group === []) {
            if($onSkip!==null)$onSkip('missing_items');
            return false;
        }

        // Приоритетный склад (меньше sort) — оттуда берутся значения тегов оффера.
        $primary = $group[0];

        // Остаток из карточки каталога важнее значения в файле склада:
        // число как есть, текст («Более 5») — через соответствия администратора.
        // Если в каталоге остатка нет — используется значение из файла.
        $catalogQty = null;
        $numericRule=(array)($settings['outlets_array']['numeric_stock_rule']??[]);
        $product = self::product(
            $primary['product_id'] !== null ? (int) $primary['product_id'] : null,
            $productCache
        );
        if ($product !== null) {
            if ($product['stock_qty'] !== null) {
                $catalogQty = StockMapRepository::catalogQuantity((int)$product['stock_qty'],$numericRule);
            } else {
                $catalogQty = StockMapRepository::match(
                    $product['stock_text'] !== null ? (string) $product['stock_text'] : null,
                    $stockMap
                );
            }
        }

        // Остаток каждой строки: каталог, затем число из файла как есть,
        // затем текст через соответствия, иначе fallback из настроек.
        $totalStock = 0;
        foreach ($group as $idx => $row) {
            $group[$idx]['resolved_qty'] = $catalogQty ?? StockMapRepository::resolve(
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
            if($onSkip!==null)$onSkip('zero_stock');
            return false;
        }

        $warehouseContexts = $group;
        foreach ($virtualWarehouses as $warehouse) {
            $warehouseContexts[] = ['resolved_qty'=>max(0,(int)$warehouse['default_stock']), 'warehouse_name'=>$warehouse['name'], 'warehouse_id'=>$warehouse['id']];
        }
        $resolve = static function(array $source, ?array $warehouse) use ($primary,$group,$totalStock,&$productCache,$numericRule): string {
            if (($source['type'] ?? '') === 'resolved') {
                return (string) (($source['ref'] ?? '') === 'total_stock' ? $totalStock : ($warehouse['resolved_qty'] ?? $group[0]['resolved_qty'] ?? 0));
            }
            if (($source['type'] ?? '') === 'warehouse') return (string) ($warehouse['warehouse_name'] ?? $primary['warehouse_name'] ?? '');
            if ($warehouse !== null && ($source['type'] ?? '') === 'field' && ($source['ref'] ?? '') === 'stock') return (string) ($warehouse['resolved_qty'] ?? $group[0]['resolved_qty'] ?? 0);
            $context = $warehouse !== null && in_array($source['type'] ?? '', ['field','column'], true) ? $warehouse : $primary;
            return self::resolveSource($source, $context, $group, $totalStock, $productCache, $numericRule);
        };
        if ($consume!==null) { $consume($resolve,$warehouseContexts); return true; }
        $xml->startElement((string) ($outlets['offer_name'] ?? 'offer'));
        XmlMapping::write($xml, $mapping, $resolve, $warehouseContexts, 'offer', null, (int)$settings['oldprice_only_higher']===1);
        $xml->endElement();

        return true;
    }

    /**
     * Значение тега по правилу конструктора:
     * field — разобранное поле файла склада (price, oldprice, min_price, sku, name, stock);
     * column — сырой столбец по букве (A, B, C...) из загруженного файла;
     * catalog — поле товара каталога (sku, name, price, price_text, stock, stock_text);
     * constant — текстовая константа.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $primary
     * @param list<array<string,mixed>> $group
     * @param array<int,array<string,mixed>|null> $productCache
     */
    private static function resolveSource(array $source, array $primary, array $group, int $totalStock, array &$productCache, array $numericRule=[]): string
    {
        $type = (string) ($source['type'] ?? '');
        $ref = (string) ($source['ref'] ?? '');

        switch ($type) {
            case 'field':
                if ($ref === 'warehouse_name') return (string)($primary['warehouse_name']??'');
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
                $product = self::product(
                    $primary['product_id'] !== null ? (int) $primary['product_id'] : null,
                    $productCache
                );
                if ($product === null) {
                    return $ref === 'sku' ? (string) ($primary['product_sku'] ?? '') : '';
                }
                if (in_array($ref, ['price','promo_price','effective_price'], true)) {
                    $field = $ref === 'effective_price' ? ((float)($product['promo_price'] ?? 0)>0 ? 'promo_price' : 'price') : $ref;
                    $formatted = self::formatNumber($product[$field] ?? null);
                    if ($formatted === '' && is_numeric(trim((string) ($product[$field . '_text'] ?? '')))) {
                        $formatted = self::formatNumber(trim((string) $product[$field . '_text']));
                    }

                    return $formatted;
                }
                if ($ref === 'stock') {
                    return $product['stock_qty'] !== null ? (string)StockMapRepository::catalogQuantity((int)$product['stock_qty'],$numericRule) : '';
                }

                return (string) ($product[$ref] ?? '');

            case 'constant':
                return $ref;
        }

        return '';
    }

    /**
     * Товар каталога с кэшем на одну сборку.
     *
     * @param array<int,array<string,mixed>|null> $cache
     * @return array<string,mixed>|null
     */
    private static function product(?int $id, array &$cache): ?array
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        if (!array_key_exists($id, $cache)) {
            $cache[$id] = Db::first(
                'SELECT id, sku, name, price, price_text, promo_price, promo_price_text, stock_qty, stock_text FROM products WHERE id = ?',
                [$id]
            );
        }

        return $cache[$id];
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
