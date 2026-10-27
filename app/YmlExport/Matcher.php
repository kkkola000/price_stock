<?php
declare(strict_types=1);

namespace App\YmlExport;

use App\Support\Db;

/**
 * Сопоставление позиций складов с товарами основного каталога.
 *
 * Автоматически: точное совпадение артикула, затем похожесть названия (>= 50%).
 * Автоматические пары — только предложения (suggested): в фид попадают
 * пары, подтверждённые администратором (confirmed).
 * Ключ пары — «склад + артикул из файла», поэтому подтверждения переживают
 * повторные загрузки прайсов.
 */
final class Matcher
{
    public const NAME_THRESHOLD = 50;

    /** Автосопоставление всех позиций склада; подтверждённые пары не трогает. */
    public static function autoForWarehouse(int $warehouseId): void
    {
        $items = Db::all('SELECT sku, name FROM yx_items WHERE warehouse_id = ?', [$warehouseId]);

        foreach ($items as $item) {
            $existing = Db::first(
                'SELECT status FROM yx_matches WHERE warehouse_id = ? AND item_sku = ?',
                [$warehouseId, $item['sku']]
            );
            if ($existing !== null && $existing['status'] === 'confirmed') {
                continue;
            }

            $product = null;
            $score = 0;
            $method = 'none';

            // 1. Точное совпадение артикула (без учёта регистра и пробелов).
            $sku = trim((string) $item['sku']);
            if ($sku !== '') {
                $product = Db::first(
                    'SELECT id, sku, name FROM products WHERE UPPER(TRIM(sku)) = ? LIMIT 1',
                    [mb_strtoupper($sku, 'UTF-8')]
                );
                if ($product !== null) {
                    $score = 100;
                    $method = 'sku';
                }
            }

            // 2. Похожесть названия — лучший кандидат с долей совпадения >= 50%.
            if ($product === null) {
                $best = self::bestByName((string) $item['name']);
                if ($best !== null) {
                    $product = $best;
                    $score = (int) $best['score'];
                    $method = 'name';
                }
            }

            self::saveMatch($warehouseId, (string) $item['sku'], $product, $score, $method);
        }
    }

    /**
     * Кандидаты для ручного сопоставления: поиск по названию/артикулу
     * с процентом похожести.
     *
     * @return list<array<string,mixed>>
     */
    public static function candidates(string $query, int $limit = 8, string $field = 'all'): array
    {
        $query = trim($query);
        if ($query === '') return [];
        [$where, $params] = self::searchWhere($query, $field, ['sku'=>'p.sku', 'name'=>'p.name'], ['s.name']);
        return Db::all('SELECT p.id, p.sku, p.name, s.name AS supplier_name,
                IF(UPPER(TRIM(p.sku)) = ?, 100, 0) AS score
            FROM products p JOIN sources s ON s.id = p.source_id
            WHERE s.is_active = 1 AND (' . $where . ')
            ORDER BY score DESC, p.name, p.id LIMIT ' . max(1, min(100, $limit)),
            array_merge([mb_strtoupper($query, 'UTF-8')], $params));
    }

    /** Literal substring search; field names are selected only from server-owned columns. */
    private static function searchWhere(string $query, string $field, array $columns, array $extra = []): array
    {
        if ($query === '') return ['1=1', []];
        $selected = isset($columns[$field]) ? (array) $columns[$field]
            : array_merge(...array_merge(array_map(static fn($value): array => (array) $value, array_values($columns)), [$extra]));
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
        return [implode(' OR ', array_map(static fn(string $column): string => $column . " LIKE ? ESCAPE '!'", $selected)),
            array_fill(0, count($selected), $pattern)];
    }

    public static function find(int $id): ?array
    {
        return Db::first('SELECT m.*, i.name AS item_name, w.name AS warehouse_name
            FROM yx_matches m LEFT JOIN yx_items i ON i.warehouse_id=m.warehouse_id AND i.sku=m.item_sku
            JOIN yx_warehouses w ON w.id=m.warehouse_id WHERE m.id=?', [$id]);
    }

    /** Remove confirmations only, including entries outside the visible/filtered list. */
    public static function clearConfirmed(): int
    {
        return Db::run("UPDATE yx_matches SET status=IF(product_id IS NULL, 'unmatched', 'suggested'),
            confirmed_by=NULL, confirmed_at=NULL WHERE status='confirmed'")->rowCount();
    }

    /** Подтверждение пары администратором (в т.ч. с выбранным вручную товаром). */
    public static function confirm(int $matchId, ?int $productId, int $adminId): void
    {
        if ($productId === null) {
            // Подтверждаем предложенную пару как есть.
            Db::run(
                "UPDATE yx_matches SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() WHERE id = ?",
                [$adminId, $matchId]
            );

            return;
        }

        $product = Db::first('SELECT id, sku FROM products WHERE id = ?', [$productId]);
        if ($product === null) {
            return;
        }

        Db::run(
            "UPDATE yx_matches SET
                status = 'confirmed',
                product_id = ?,
                product_sku = ?,
                method = 'manual',
                confirmed_by = ?,
                confirmed_at = NOW()
             WHERE id = ?",
            [
                $product['id'],
                (string) $product['sku'],
                $adminId,
                $matchId,
            ]
        );
    }

    /** Массовое подтверждение предложенных пар с совпадением не ниже порога. */
    public static function bulkConfirm(int $minScore, int $adminId): int
    {
        $stmt = Db::run(
            "UPDATE yx_matches SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW()
             WHERE status = 'suggested' AND score >= ?",
            [$adminId, $minScore]
        );

        return $stmt->rowCount();
    }

    public static function reject(int $matchId): void
    {
        Db::run("UPDATE yx_matches SET status = 'rejected' WHERE id = ?", [$matchId]);
    }

    public static function resetToSuggested(int $matchId): void
    {
        Db::run(
            "UPDATE yx_matches SET status = IF(product_id IS NULL, 'unmatched', 'suggested'), confirmed_by = NULL, confirmed_at = NULL WHERE id = ?",
            [$matchId]
        );
    }

    /** Полное удаление сопоставления: пара разрывается, позиция возвращается в очередь. */
    public static function unmatch(int $matchId): void
    {
        Db::run(
            "UPDATE yx_matches SET
                status = 'unmatched', product_id = NULL, product_sku = '',
                score = 0, method = 'none', confirmed_by = NULL, confirmed_at = NULL
             WHERE id = ?",
            [$matchId]
        );
    }

    /**
     * Подтверждённые пары для подраздела «Товары».
     *
     * @return list<array<string,mixed>>
     */
    public static function confirmedList(int $limit = 500, string $query = '', string $field = 'all'): array
    {
        [$search, $params] = self::searchWhere(trim($query), $field,
            ['sku'=>['m.item_sku','p.sku'], 'name'=>['i.name','p.name']], ['w.name']);
        return Db::all(
            "SELECT m.*, i.name AS item_name, i.stock_qty, i.price,
                    w.name AS warehouse_name, p.name AS product_name, p.sku AS product_sku_live
             FROM yx_matches m
             LEFT JOIN yx_items i ON i.warehouse_id = m.warehouse_id AND i.sku = m.item_sku
             LEFT JOIN yx_warehouses w ON w.id = m.warehouse_id
             LEFT JOIN products p ON p.id = m.product_id
             WHERE m.status = 'confirmed' AND (" . $search . ")
             ORDER BY w.sort, w.id, m.id
             LIMIT " . max(1, $limit), $params
        );
    }

    /**
     * Очередь сопоставления для вкладки: предложенные и не найденные.
     *
     * @return list<array<string,mixed>>
     */
    public static function queue(int $limit = 300, string $query = '', string $field = 'all'): array
    {
        [$search, $params] = self::searchWhere(trim($query), $field,
            ['sku'=>['m.item_sku','p.sku'], 'name'=>['i.name','p.name']], ['w.name']);
        return Db::all(
            "SELECT m.*, i.name AS item_name, i.stock_qty, i.price,
                    w.name AS warehouse_name, p.name AS product_name, p.sku AS product_sku_live
             FROM yx_matches m
             LEFT JOIN yx_items i ON i.warehouse_id = m.warehouse_id AND i.sku = m.item_sku
             LEFT JOIN yx_warehouses w ON w.id = m.warehouse_id
             LEFT JOIN products p ON p.id = m.product_id
             WHERE m.status IN ('suggested', 'unmatched') AND (" . $search . ")
             ORDER BY (m.status = 'suggested') DESC, m.score DESC, m.id
             LIMIT " . max(1, $limit), $params
        );
    }

    /** @return array<string,int> */
    public static function stats(): array
    {
        $rows = Db::all('SELECT status, COUNT(*) AS c FROM yx_matches GROUP BY status');
        $stats = ['suggested' => 0, 'confirmed' => 0, 'rejected' => 0, 'unmatched' => 0];
        foreach ($rows as $row) {
            $stats[(string) $row['status']] = (int) $row['c'];
        }

        return $stats;
    }

    /**
     * Лучший товар каталога по похожести названия.
     * Предфильтр по самому длинному слову, чтобы не сканировать весь каталог.
     *
     * @return array<string,mixed>|null
     */
    private static function bestByName(string $name): ?array
    {
        $normalized = self::normalize($name);
        if ($normalized === '') {
            return null;
        }

        $words = array_filter(explode(' ', $normalized), static fn (string $w): bool => mb_strlen($w, 'UTF-8') >= 4);
        usort($words, static fn (string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
        $anchor = $words[0] ?? $normalized;

        $rows = Db::all(
            'SELECT id, sku, name FROM products WHERE name LIKE ? LIMIT 500',
            ['%' . $anchor . '%']
        );

        $best = null;
        $bestScore = 0.0;
        foreach ($rows as $row) {
            $score = 0.0;
            similar_text($normalized, self::normalize((string) $row['name']), $score);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        if ($best === null || $bestScore < self::NAME_THRESHOLD) {
            return null;
        }

        $best['score'] = (int) round($bestScore);

        return $best;
    }

    /** @param array<string,mixed>|null $product */
    private static function saveMatch(int $warehouseId, string $itemSku, ?array $product, int $score, string $method): void
    {
        Db::run(
            'INSERT INTO yx_matches (warehouse_id, item_sku, product_id, product_sku, status, score, method)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                product_id = VALUES(product_id), product_sku = VALUES(product_sku),
                status = VALUES(status), score = VALUES(score), method = VALUES(method)',
            [
                $warehouseId,
                $itemSku,
                $product['id'] ?? null,
                (string) ($product['sku'] ?? ''),
                $product === null ? 'unmatched' : 'suggested',
                $score,
                $method,
            ]
        );
    }

    /** Нормализация для сравнения названий: регистр, ё, пунктуация, лишние пробелы. */
    private static function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace('ё', 'е', $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
