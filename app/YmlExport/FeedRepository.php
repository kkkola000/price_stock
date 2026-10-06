<?php
declare(strict_types=1);

namespace App\YmlExport;

use App\Support\Db;

/**
 * Ссылки на YML-фиды: у каждой свой токен и свой набор складов
 * (yx_feeds + yx_feed_warehouses). Кэш-файл фида ключуется токеном.
 */
final class FeedRepository
{
    /**
     * Все ссылки; к каждой прикреплён список id складов.
     *
     * @return list<array<string,mixed>>
     */
    public static function list(): array
    {
        $feeds = Db::all('SELECT * FROM yx_feeds ORDER BY id');
        if ($feeds === []) {
            return [];
        }

        $links = Db::all('SELECT feed_id, warehouse_id FROM yx_feed_warehouses');
        $byFeed = [];
        foreach ($links as $link) {
            $byFeed[(int) $link['feed_id']][] = (int) $link['warehouse_id'];
        }
        foreach ($feeds as &$feed) {
            $feed['warehouse_ids'] = $byFeed[(int) $feed['id']] ?? [];
        }
        unset($feed);

        return $feeds;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $feed = Db::first('SELECT * FROM yx_feeds WHERE id = ?', [$id]);
        if ($feed !== null) {
            $feed['warehouse_ids'] = self::warehouseIds((int) $feed['id']);
        }

        return $feed;
    }

    /** @return array<string,mixed>|null */
    public static function findByToken(string $token): ?array
    {
        $token = preg_replace('/[^a-f0-9]/i', '', $token) ?? '';
        if ($token === '') {
            return null;
        }
        $feed = Db::first('SELECT * FROM yx_feeds WHERE token = ?', [$token]);
        if ($feed !== null) {
            $feed['warehouse_ids'] = self::warehouseIds((int) $feed['id']);
        }

        return $feed;
    }

    /** @return list<int> */
    public static function warehouseIds(int $feedId): array
    {
        $rows = Db::all('SELECT warehouse_id FROM yx_feed_warehouses WHERE feed_id = ?', [$feedId]);

        return array_map(static fn (array $row): int => (int) $row['warehouse_id'], $rows);
    }

    /** @param list<int> $warehouseIds */
    public static function create(string $name, array $warehouseIds): int
    {
        Db::run('INSERT INTO yx_feeds (name, token) VALUES (?, ?)', [
            mb_substr($name, 0, 190),
            self::newToken(),
        ]);
        $id = Db::lastInsertId();
        self::setWarehouses($id, $warehouseIds);

        return $id;
    }

    /** @param list<int> $warehouseIds */
    public static function update(int $id, string $name, array $warehouseIds): void
    {
        Db::run('UPDATE yx_feeds SET name = ? WHERE id = ?', [mb_substr($name, 0, 190), $id]);
        self::setWarehouses($id, $warehouseIds);
    }

    /** @param list<int> $warehouseIds */
    public static function setWarehouses(int $feedId, array $warehouseIds): void
    {
        Db::run('DELETE FROM yx_feed_warehouses WHERE feed_id = ?', [$feedId]);
        foreach (array_unique(array_map('intval', $warehouseIds)) as $warehouseId) {
            if ($warehouseId > 0) {
                // INSERT IGNORE: чужой/удалённый id склада просто пропускаем.
                Db::run('INSERT IGNORE INTO yx_feed_warehouses (feed_id, warehouse_id) VALUES (?, ?)', [$feedId, $warehouseId]);
            }
        }
    }

    /** Перевыпуск токена: старая ссылка перестаёт работать, старый кэш-файл удаляется. */
    public static function regenerateToken(int $id): void
    {
        $feed = self::find($id);
        if ($feed === null) {
            return;
        }
        $oldFile = FeedBuilder::path((string) $feed['token']);
        Db::run('UPDATE yx_feeds SET token = ? WHERE id = ?', [self::newToken(), $id]);
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    /** Удаляет ссылку, её привязки (каскад) и кэш-файл фида. */
    public static function delete(int $id): void
    {
        $feed = self::find($id);
        if ($feed === null) {
            return;
        }
        Db::run('DELETE FROM yx_feeds WHERE id = ?', [$id]);
        $file = FeedBuilder::path((string) $feed['token']);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function markBuilt(int $id, int $offers): void
    {
        Db::run('UPDATE yx_feeds SET built_at = NOW(), built_offers = ? WHERE id = ?', [$offers, $id]);
    }

    /**
     * Страховка для чистых установок: если ссылок нет совсем, создаём
     * «Основной фид» из токена настроек и текущего набора складов in_feed.
     */
    public static function ensureDefault(): void
    {
        if ((int) Db::scalar('SELECT COUNT(*) FROM yx_feeds') > 0) {
            return;
        }
        $settings = SettingsRepository::get();
        Db::run('INSERT INTO yx_feeds (name, token) VALUES (?, ?)', ['Основной фид', (string) $settings['token']]);
        $feedId = Db::lastInsertId();
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            Db::all('SELECT id FROM yx_warehouses WHERE in_feed = 1')
        );
        self::setWarehouses($feedId, $ids);
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
