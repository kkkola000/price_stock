<?php
declare(strict_types=1);

namespace App\Import;

use InvalidArgumentException;

/**
 * Преобразование ссылки на столбец, указанной админом,
 * в 0-based индекс. Принимаются как буквы («A», «b», «AA»),
 * так и номера («1», «2», ...). Пустая строка — поле не задано.
 */
final class ColumnRef
{
    public static function toIndex(?string $ref): ?int
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return null;
        }

        if (ctype_digit($ref)) {
            $number = (int) $ref;
            if ($number < 1) {
                throw new InvalidArgumentException('Номер столбца должен начинаться с 1, получено: ' . $ref);
            }
            return $number - 1;
        }

        $upper = strtoupper($ref);
        if (!preg_match('/^[A-Z]{1,3}$/', $upper)) {
            throw new InvalidArgumentException('Некорректный столбец: «' . $ref . '». Укажите букву (A, B, AA) или номер (1, 2, 3).');
        }

        $index = 0;
        foreach (str_split($upper) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }

    /** Ordered column references for a mapped field; an empty mapping stays unset.
     * @return list<int>
     */
    public static function toIndices(?string $ref): array
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return [];
        }
        $indices = [];
        foreach (explode(',', $ref) as $part) {
            $index = self::toIndex($part);
            if ($index === null) {
                throw new InvalidArgumentException('Укажите буквы столбцов через запятую без пустых элементов, например B,F.');
            }
            $indices[] = $index;
        }
        return $indices;
    }

    /** @param list<string> $row
     * @param list<int> $indices
     */
    public static function joinedValue(array $row, array $indices, bool $skipEmpty = true): string
    {
        $values = [];
        foreach ($indices as $index) {
            $value = self::value($row, $index);
            if (!$skipEmpty || $value !== '') {
                $values[] = $value;
            }
        }
        return implode(' ', $values);
    }

    /** Обратное преобразование — для подсказок в интерфейсе. */
    public static function toLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $rest = ($index - 1) % 26;
            $letter = chr(65 + $rest) . $letter;
            $index = (int) (($index - $rest - 1) / 26);
        }

        return $letter;
    }

    /** @param list<string> $row */
    public static function value(array $row, ?int $index): string
    {
        if ($index === null || !array_key_exists($index, $row)) {
            return '';
        }

        return trim((string) $row[$index]);
    }
}
