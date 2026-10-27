<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOStatement;

final class Db
{
    private static ?PDO $pdo = null;

    /** Release an idle connection between ticks of a long-running CLI worker. */
    public static function disconnect(): void { self::$pdo=null; }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = (array) Config::get('db', []);
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'] ?? 'localhost',
            (int) ($cfg['port'] ?? 3306),
            $cfg['database'] ?? '',
            $cfg['charset'] ?? 'utf8mb4'
        );

        self::$pdo = new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // База и приложение должны считать время одинаково: иначе расписание
        // импорта и показанные в админке даты разъезжаются на часовой пояс.
        // Если хостинг запрещает менять настройку — работаем как раньше.
        try {
            self::$pdo->exec("SET time_zone = '" . self::currentOffset() . "'");
        } catch (\PDOException $exception) {
            error_log('[db] не удалось задать часовой пояс сессии: ' . $exception->getMessage());
        }

        return self::$pdo;
    }

    /** Смещение часового пояса PHP в формате «+03:00». */
    private static function currentOffset(): string
    {
        $seconds = (new \DateTimeImmutable())->getOffset();
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<string|int,mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        return self::run($sql, $params)->fetchColumn();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    public static function tableExists(string $table): bool
    {
        $found = self::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        return (int) $found > 0;
    }
}
