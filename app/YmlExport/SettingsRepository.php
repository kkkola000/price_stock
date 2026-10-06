<?php
declare(strict_types=1);

namespace App\YmlExport;

use App\Support\Db;

/**
 * Настройки YML-фида (одна строка в yx_feed_settings):
 * блок <shop>, конструктор тегов оффера, outlets, токен ссылки.
 */
final class SettingsRepository
{
    /**
     * Конструктор по умолчанию: артикул и название — из файла склада,
     * цена — из карточки каталога, oldprice/min_price — из файла.
     */
    private const DEFAULT_OFFER_MAPPING = [
        ['enabled' => true, 'kind' => 'attribute', 'name' => 'id',        'source' => ['type' => 'field',   'ref' => 'sku']],
        ['enabled' => true, 'kind' => 'tag',       'name' => 'name',      'source' => ['type' => 'field',   'ref' => 'name']],
        ['enabled' => true, 'kind' => 'tag',       'name' => 'price',     'source' => ['type' => 'catalog', 'ref' => 'price']],
        ['enabled' => true, 'kind' => 'tag',       'name' => 'oldprice',  'source' => ['type' => 'field',   'ref' => 'oldprice']],
        ['enabled' => true, 'kind' => 'tag',       'name' => 'min_price', 'source' => ['type' => 'field',   'ref' => 'min_price']],
    ];

    /** Прежний дефолт: если он сохранён нетронутым, заменяем на новый. */
    private const LEGACY_OFFER_MAPPING = [
        ['enabled' => true, 'kind' => 'attribute', 'name' => 'id',        'source' => ['type' => 'catalog', 'ref' => 'sku']],
        ['enabled' => true, 'kind' => 'tag',       'name' => 'price',     'source' => ['type' => 'field',   'ref' => 'price']],
        ['enabled' => true, 'kind' => 'tag',       'name' => 'oldprice',  'source' => ['type' => 'field',   'ref' => 'oldprice']],
        ['enabled' => true, 'kind' => 'tag',       'name' => 'min_price', 'source' => ['type' => 'field',   'ref' => 'min_price']],
    ];

    private const DEFAULT_OUTLETS = [
        'enabled'   => true,
        'parent'    => 'outlets',
        'tag'       => 'outlet',
        'stock_attr' => 'instock',
        'name_attr' => 'warehouse_name',
    ];

    /** @return array<string,mixed> */
    public static function get(): array
    {
        $row = Db::first('SELECT * FROM yx_feed_settings WHERE id = 1');
        if ($row === null) {
            Db::run(
                'INSERT INTO yx_feed_settings (id, offer_mapping, outlets, token) VALUES (1, ?, ?, ?)',
                [
                    json_encode(self::DEFAULT_OFFER_MAPPING, JSON_UNESCAPED_UNICODE),
                    json_encode(self::DEFAULT_OUTLETS, JSON_UNESCAPED_UNICODE),
                    self::newToken(),
                ]
            );
            $row = Db::first('SELECT * FROM yx_feed_settings WHERE id = 1');
        }

        /** @var array<string,mixed> $row */
        $mapping = json_decode((string) $row['offer_mapping'], true);
        if (!is_array($mapping) || $mapping === [] || $mapping === self::LEGACY_OFFER_MAPPING) {
            // Пустой или нетронутый прежний дефолт — переключаем на новый:
            // артикул/название из файла, цена из каталога.
            $mapping = self::DEFAULT_OFFER_MAPPING;
            Db::run('UPDATE yx_feed_settings SET offer_mapping = ? WHERE id = 1', [
                json_encode(self::DEFAULT_OFFER_MAPPING, JSON_UNESCAPED_UNICODE),
            ]);
        }
        $row['offer_mapping_array'] = $mapping;
        $row['outlets_array'] = json_decode((string) $row['outlets'], true) ?: self::DEFAULT_OUTLETS;

        return $row;
    }

    /**
     * @param list<array<string,mixed>> $offerMapping
     * @param array<string,mixed> $outlets
     */
    public static function save(
        string $shopName,
        string $shopCompany,
        string $shopUrl,
        string $currency,
        array $offerMapping,
        array $outlets,
        bool $onlyConfirmed,
        bool $skipZeroStock,
        bool $oldpriceOnlyHigher,
        int $stockFallback = 0
    ): void {
        self::get(); // гарантирует существование строки

        Db::run(
            'UPDATE yx_feed_settings SET
                shop_name = ?, shop_company = ?, shop_url = ?, currency = ?,
                offer_mapping = ?, outlets = ?,
                only_confirmed = ?, skip_zero_stock = ?, oldprice_only_higher = ?,
                stock_fallback = ?
             WHERE id = 1',
            [
                mb_substr($shopName, 0, 190),
                mb_substr($shopCompany, 0, 190),
                mb_substr($shopUrl, 0, 500),
                mb_substr($currency, 0, 8),
                json_encode(array_values($offerMapping), JSON_UNESCAPED_UNICODE),
                json_encode($outlets, JSON_UNESCAPED_UNICODE),
                $onlyConfirmed ? 1 : 0,
                $skipZeroStock ? 1 : 0,
                $oldpriceOnlyHigher ? 1 : 0,
                max(0, $stockFallback),
            ]
        );
    }

    /** Перевыпуск токена: старая ссылка на фид перестаёт работать. */
    public static function regenerateToken(): string
    {
        self::get();
        $token = self::newToken();
        Db::run('UPDATE yx_feed_settings SET token = ? WHERE id = 1', [$token]);

        return $token;
    }

    public static function markBuilt(int $offers): void
    {
        Db::run('UPDATE yx_feed_settings SET built_at = NOW(), built_offers = ? WHERE id = 1', [$offers]);
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
