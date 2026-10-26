<?php
declare(strict_types=1);
namespace App\Models;

use App\Support\Db;

final class PriceChangeRepository
{
    /** Per-connection snapshot; no full catalog in PHP memory or history from partial batches. */
    public static function snapshot(int $sourceId): void
    {
        Db::run('DROP TEMPORARY TABLE IF EXISTS import_price_snapshot');
        Db::run('CREATE TEMPORARY TABLE import_price_snapshot AS
            SELECT id, price, price_text, promo_price, promo_price_text, currency
            FROM products WHERE source_id = ?', [$sourceId]);
        Db::run('ALTER TABLE import_price_snapshot ADD PRIMARY KEY (id)');
    }

    public static function record(int $sourceId, int $runId): void
    {
        Db::run('INSERT INTO price_changes
            (source_id, product_id, import_run_id, name, sku,
             old_price, new_price, old_price_text, new_price_text,
             old_promo_price, new_promo_price, old_promo_price_text, new_promo_price_text,
             old_currency, new_currency)
            SELECT p.source_id, p.id, ?, p.name, p.sku,
                b.price, p.price, b.price_text, p.price_text,
                b.promo_price, p.promo_price, b.promo_price_text, p.promo_price_text,
                b.currency, p.currency
            FROM products p JOIN import_price_snapshot b ON b.id = p.id
            WHERE p.source_id = ? AND p.import_run_id = ?
              AND (NOT (b.price <=> p.price) OR NOT (b.promo_price <=> p.promo_price)
                   OR b.currency <> p.currency)', [$runId, $sourceId, $runId]);
    }

    public static function recent(int $page = 1): array
    {
        $where = "c.changed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND s.is_active = 1 AND r.status = 'ok'";
        $from = ' FROM price_changes c JOIN sources s ON s.id = c.source_id
            JOIN import_runs r ON r.id = c.import_run_id WHERE ' . $where;
        $total = (int) Db::scalar('SELECT COUNT(*)' . $from);
        $pages = max(1, (int) ceil($total / 50));
        $page = max(1, min($page, $pages));
        $items = Db::all('SELECT c.*, s.name AS supplier_name' . $from
            . ' ORDER BY c.changed_at DESC, c.id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50));
        return compact('items', 'total', 'page', 'pages');
    }
}
