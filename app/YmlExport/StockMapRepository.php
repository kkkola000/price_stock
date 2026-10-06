<?php
declare(strict_types=1);

namespace App\YmlExport;

use App\Support\Db;

/**
 * Соответствие текстовых остатков числам для фида:
 * поставщик передаёт «Более 5», «мало», «в наличии» — администратор задаёт,
 * какое количество уходит в instock.
 */
final class StockMapRepository
{
    /** @return list<array<string,mixed>> */
    public static function list(): array
    {
        return Db::all('SELECT * FROM yx_stock_map ORDER BY sort, id');
    }

    /**
     * Полная замена списка соответствий (список небольшой, редактируется целиком).
     *
     * @param list<array{pattern:string,qty:int}> $rows
     */
    public static function replaceAll(array $rows): void
    {
        Db::run('DELETE FROM yx_stock_map');
        $sort = 0;
        foreach ($rows as $row) {
            Db::run('INSERT INTO yx_stock_map (pattern, qty, sort) VALUES (?, ?, ?)', [
                mb_substr($row['pattern'], 0, 190),
                (int) $row['qty'],
                $sort++,
            ]);
        }
    }

    /**
     * Нормализованные правила для сопоставления.
     *
     * @return list<array{pattern:string,qty:int}>
     */
    public static function map(): array
    {
        $map = [];
        foreach (self::list() as $row) {
            $map[] = ['pattern' => self::normalize((string) $row['pattern']), 'qty' => (int) $row['qty']];
        }

        return $map;
    }

    /**
     * Текст остатка → количество по соответствиям администратора
     * (сначала точное совпадение, затем по вхождению).
     * Не нашлось — null, решение о fallback остаётся вызывающему.
     *
     * @param list<array{pattern:string,qty:int}> $map
     */
    public static function match(?string $text, array $map): ?int
    {
        $normalized = self::normalize((string) $text);
        if ($normalized === '') {
            return null;
        }
        foreach ($map as $rule) {
            if ($rule['pattern'] !== '' && $rule['pattern'] === $normalized) {
                return max(0, $rule['qty']);
            }
        }
        foreach ($map as $rule) {
            if ($rule['pattern'] !== '' && mb_strpos($normalized, $rule['pattern']) !== false) {
                return max(0, $rule['qty']);
            }
        }

        return null;
    }

    /**
     * Количество для фида: число из файла важнее текста; текст ищем
     * в соответствиях (сначала точное совпадение, затем по вхождению);
     * если не нашлось — fallback из настроек.
     *
     * @param list<array{pattern:string,qty:int}> $map
     */
    public static function resolve(?int $qty, ?string $text, array $map, int $fallback): int
    {
        if ($qty !== null) {
            return max(0, $qty);
        }

        $matched = self::match($text, $map);

        return $matched ?? max(0, $fallback);
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace('ё', 'е', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
