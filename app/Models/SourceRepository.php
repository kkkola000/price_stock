<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Db;

final class SourceRepository
{
    public const TYPES = [
        'excel' => 'Excel (.xlsx, .xls)',
        'csv'   => 'CSV / TXT',
        'yml'   => 'YML-фид (XML)',
    ];

    public const FETCH_METHODS = [
        'upload' => 'Загрузка файла вручную',
        'url'    => 'Ссылка (скачивание по расписанию)',
    ];

    /**
     * Поля маппинга. Все необязательные: заполняется только то, что есть
     * в прайсе. Если не задано ни название, ни артикул, строка пропускается —
     * показывать в каталоге будет нечего.
     */
    public const FIELDS = [
        'name'  => ['label' => 'Название товара'],
        'sku'   => ['label' => 'Артикул / SKU'],
        'price' => ['label' => 'Цена'],
        'stock' => ['label' => 'Остаток/наличие'],
        'image' => ['label' => 'Фото (URL)'],
        'currency' => ['label' => 'Валюта (столбец)'],
    ];

    public const DEFAULT_IN_STOCK_VALUES = 'да,есть,в наличии,в наличие,+,true,in stock,instock,available,много,мало,ограничен';
    public const DEFAULT_OUT_OF_STOCK_VALUES = 'нет,отсутствует,под заказ,ожидается,распродан,закончил,снят,недоступен,out of stock,нет в наличии';

    /** @return array<string,string> Маппинг по умолчанию для YML-фида. */
    public static function defaultYmlMapping(): array
    {
        return [
            'name'     => 'name',
            'sku'      => 'vendorCode',
            'price'    => 'price',
            'stock'    => 'count',
            'image'    => 'picture',
            'currency' => 'currencyId',
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public static function list(array $filters = []): array
    {
        $sql = 'SELECT * FROM sources WHERE 1';
        $params = [];

        if (!empty($filters['only_active'])) {
            $sql .= ' AND is_active = 1';
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND name LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }
        $sql .= ' ORDER BY name';

        return array_map([self::class, 'hydrate'], Db::all($sql, $params));
    }

    /**
     * Поставщики, у которых есть хотя бы один товар — для фильтра на витрине
     * и окна «Поставщики». Дата берётся от последнего успешного импорта:
     * именно эти данные сейчас и показаны в каталоге.
     */
    public static function listForCatalog(): array
    {
        return Db::all(
            'SELECT s.id, s.name, s.last_success_at, COUNT(p.id) AS products_count
               FROM sources s
               JOIN products p ON p.source_id = s.id
              WHERE s.is_active = 1
              GROUP BY s.id, s.name, s.last_success_at
              HAVING products_count > 0
              ORDER BY s.name'
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Db::first('SELECT * FROM sources WHERE id = ?', [$id]);
        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Запас по времени при проверке расписания, минут.
     *
     * Планировщик запускается по своим часам: при ежечасной задаче и интервале
     * 60 минут между запусками проходит 59 минут 59 секунд, и без запаса такой
     * источник пропускался бы через раз — импорт шёл бы раз в два часа,
     * а при суточном интервале и ежедневной задаче — через день.
     */
    private const SCHEDULE_GRACE_MINUTES = 5;

    /** Источники, которым пора выполнить автоматический импорт. */
    public static function dueForImport(): array
    {
        $rows = Db::all(
            "SELECT * FROM sources
              WHERE is_active = 1
                AND auto_import = 1
                AND fetch_method = 'url'
                AND source_url IS NOT NULL AND source_url <> ''
                AND (last_run_at IS NULL
                     OR last_run_at <= DATE_SUB(
                            NOW(),
                            INTERVAL GREATEST(import_interval_minutes - " . self::SCHEDULE_GRACE_MINUTES . ", 1) MINUTE
                        ))
              ORDER BY last_run_at IS NOT NULL, last_run_at"
        );

        return array_map([self::class, 'hydrate'], $rows);
    }

    /** Когда источник должен обновиться в следующий раз (для показа в админке). */
    public static function nextRunAt(array $source): ?string
    {
        if ((int) $source['is_active'] !== 1 || (int) $source['auto_import'] !== 1
            || $source['fetch_method'] !== 'url') {
            return null;
        }
        if (empty($source['last_run_at'])) {
            return 'при ближайшем запуске планировщика';
        }

        $interval = max(1, (int) $source['import_interval_minutes'] - self::SCHEDULE_GRACE_MINUTES);
        $next = strtotime((string) $source['last_run_at']) + $interval * 60;

        return $next <= time() ? 'при ближайшем запуске планировщика' : format_datetime(date('Y-m-d H:i:s', $next));
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $data['mapping'] = json_encode($data['mapping'] ?? [], JSON_UNESCAPED_UNICODE);
        $columns = array_keys($data);
        $sql = 'INSERT INTO sources (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')';
        Db::run($sql, $data);

        return Db::lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if (isset($data['mapping']) && is_array($data['mapping'])) {
            $data['mapping'] = json_encode($data['mapping'], JSON_UNESCAPED_UNICODE);
        }
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $column . ' = :' . $column;
        }
        $data['id'] = $id;
        Db::run('UPDATE sources SET ' . implode(', ', $sets) . ' WHERE id = :id', $data);
    }

    public static function setActive(int $id, bool $active): void
    {
        Db::run('UPDATE sources SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    /** Полное удаление источника вместе с товарами. */
    public static function delete(int $id): void
    {
        $source = self::find($id);
        if ($source !== null && !empty($source['file_path'])) {
            $file = APP_STORAGE . '/uploads/' . $source['file_path'];
            if (is_file($file)) {
                @unlink($file);
            }
        }
        Db::run('DELETE FROM sources WHERE id = ?', [$id]);
    }

    public static function refreshProductsCount(int $id): int
    {
        $count = (int) Db::scalar('SELECT COUNT(*) FROM products WHERE source_id = ?', [$id]);
        Db::run('UPDATE sources SET products_count = ? WHERE id = ?', [$count, $id]);

        return $count;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function hydrate(array $row): array
    {
        $mapping = [];
        if (!empty($row['mapping'])) {
            $decoded = json_decode((string) $row['mapping'], true);
            if (is_array($decoded)) {
                $mapping = $decoded;
            }
        }
        $row['mapping_array'] = $mapping;

        return $row;
    }

    /** Человекочитаемый статус для списка источников. */
    public static function statusLabel(array $source): array
    {
        if ((int) $source['is_active'] === 0) {
            return ['disabled', 'Отключен'];
        }

        return match ($source['last_status']) {
            'ok'      => ['ok', 'Активен'],
            'error'   => ['error', 'Ошибка'],
            'running' => ['running', 'Импорт идёт'],
            default   => ['never', 'Активен (без импорта)'],
        };
    }
}
