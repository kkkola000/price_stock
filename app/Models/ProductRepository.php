<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\CatalogRequest;
use App\Support\Db;

final class ProductRepository
{
    public const AVAILABILITY = [
        'in_stock'     => 'В наличии',
        'out_of_stock' => 'Нет в наличии',
    ];

    /**
     * Выборка для публичного каталога.
     *
     * @param array{q?:string,suppliers?:list<int>,availability?:list<string>,sort?:string,page?:int,per_page?:int} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int,has_more:bool}
     */
    public static function search(array $filters): array
    {
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(96, max(1, (int) ($filters['per_page'] ?? 24)));

        [$where, $params] = self::buildWhere($filters);

        $total = (int) Db::scalar(
            'SELECT COUNT(*) FROM products p JOIN sources s ON s.id = p.source_id WHERE ' . $where,
            $params
        );

        // Товары без цены всегда в конце — сортировать их не по чему
        $order = match ($filters['sort'] ?? CatalogRequest::DEFAULT_SORT) {
            'price_asc' => 'p.price IS NULL, p.price ASC, p.name ASC',
            'name_asc'  => 'p.name ASC',
            'name_desc' => 'p.name DESC',
            'newest'    => 'p.updated_at DESC, p.id DESC',
            default     => 'p.price IS NULL, p.price DESC, p.name ASC',
        };

        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT p.*, s.name AS supplier_name
                  FROM products p
                  JOIN sources s ON s.id = p.source_id
                 WHERE ' . $where . '
                 ORDER BY ' . $order . '
                 LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $items = Db::all($sql, $params);
        $pages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $pages,
            'has_more' => $page < $pages,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        $where  = ['s.is_active = 1'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.name LIKE :q_name OR p.sku LIKE :q_sku)';
            $params['q_name'] = '%' . self::escapeLike($q) . '%';
            $params['q_sku']  = '%' . self::escapeLike($q) . '%';
        }

        $suppliers = array_values(array_filter(array_map('intval', (array) ($filters['suppliers'] ?? []))));
        if ($suppliers !== []) {
            $placeholders = [];
            foreach ($suppliers as $i => $id) {
                $placeholders[] = ':sup' . $i;
                $params['sup' . $i] = $id;
            }
            $where[] = 'p.source_id IN (' . implode(', ', $placeholders) . ')';
        }

        $availability = array_values(array_intersect(
            (array) ($filters['availability'] ?? []),
            array_keys(self::AVAILABILITY)
        ));
        if ($availability !== []) {
            $placeholders = [];
            foreach ($availability as $i => $value) {
                $placeholders[] = ':av' . $i;
                $params['av' . $i] = $value;
            }
            $where[] = 'p.availability IN (' . implode(', ', $placeholders) . ')';
        }

        return [implode(' AND ', $where), $params];
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Пакетная вставка/обновление товаров одного источника.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function upsertBatch(int $sourceId, array $rows, int $runId): int
    {
        if ($rows === []) {
            return 0;
        }

        $placeholders = [];
        $params = [];
        foreach ($rows as $i => $row) {
            $placeholders[] = "(:source_id_$i, :sku_$i, :name_$i, :price_$i, :price_text_$i, :promo_price_$i, :promo_price_text_$i, :currency_$i,"
                . " :stock_qty_$i, :stock_text_$i, :availability_$i, :image_url_$i, :extra_$i, :run_$i)";
            $params["source_id_$i"]    = $sourceId;
            $params["sku_$i"]          = $row['sku'];
            $params["name_$i"]         = $row['name'];
            $params["price_$i"]        = $row['price'];
            $params["price_text_$i"]   = $row['price_text'];
            $params["promo_price_$i"] = $row['promo_price'] ?? null;
            $params["promo_price_text_$i"] = $row['promo_price_text'] ?? null;
            $params["currency_$i"]     = $row['currency'];
            $params["stock_qty_$i"]    = $row['stock_qty'];
            $params["stock_text_$i"]   = $row['stock_text'];
            $params["availability_$i"] = $row['availability'];
            $params["image_url_$i"]    = $row['image_url'];
            $params["extra_$i"]        = $row['extra'];
            $params["run_$i"]          = $runId;
        }

        $sql = 'INSERT INTO products
                    (source_id, sku, name, price, price_text, promo_price, promo_price_text, currency, stock_qty, stock_text,
                     availability, image_url, extra, import_run_id)
                VALUES ' . implode(', ', $placeholders) . '
                ON DUPLICATE KEY UPDATE
                    name          = VALUES(name),
                    price         = VALUES(price),
                    price_text    = VALUES(price_text),
                    promo_price   = VALUES(promo_price),
                    promo_price_text = VALUES(promo_price_text),
                    currency      = VALUES(currency),
                    stock_qty     = VALUES(stock_qty),
                    stock_text    = VALUES(stock_text),
                    availability  = VALUES(availability),
                    image_url     = VALUES(image_url),
                    extra         = VALUES(extra),
                    import_run_id = VALUES(import_run_id)';

        Db::run($sql, $params);

        return count($rows);
    }

    /** Удаляет товары источника, отсутствующие в последнем импорте. */
    public static function deleteStale(int $sourceId, int $runId): int
    {
        $stmt = Db::run(
            'DELETE FROM products WHERE source_id = ? AND (import_run_id IS NULL OR import_run_id <> ?)',
            [$sourceId, $runId]
        );

        return $stmt->rowCount();
    }

    public static function countBySource(int $sourceId): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM products WHERE source_id = ?', [$sourceId]);
    }

    public static function totalCount(): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM products');
    }

    /** Примеры товаров источника — для проверки маппинга в админке. */
    public static function sampleBySource(int $sourceId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        return Db::all(
            'SELECT * FROM products WHERE source_id = ? ORDER BY id DESC LIMIT ' . $limit,
            [$sourceId]
        );
    }
}
