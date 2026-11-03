<?php
declare(strict_types=1);

namespace App\Import;

/**
 * Приведение «сырых» значений прайса к типам витрины.
 */
final class ValueParser
{
    /** Цена: «1 234,50 руб.» / «1234.5» / «1 234" -> 1234.50 */
    public static function price(string $raw, float $multiplier = 1.0): ?float
    {
        $value = str_replace(["\xc2\xa0", "\xe2\x80\xaf", ' ', "'"], '', trim($raw));
        $value = preg_replace('/[^0-9,.\-]/u', '', $value) ?? '';
        if ($value === '' || $value === '-') {
            return null;
        }

        // Разделители: последний из «,» или «.» считаем десятичным.
        $lastComma = strrpos($value, ',');
        $lastDot   = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        $price = round((float) $value * $multiplier, 2);

        return $price < 0 ? null : $price;
    }

    /**
     * Остаток/наличие. Значение поставщика сохраняется дословно — именно оно
     * показывается на витрине («Есть в наличии», «Более 5», «3 шт»).
     * Статус нужен только для фильтра и определяется по порядку:
     * сначала слова «нет в наличии», затем числа, затем слова «в наличии».
     *
     * @return array{qty:?int,status:string,text:string}
     */
    public static function stock(string $raw, string $inStockValues, string $outOfStockValues = ''): array
    {
        $text = self::text($raw, 190);
        if ($text === '') {
            return ['qty' => null, 'status' => 'out_of_stock', 'text' => ''];
        }

        $normalized = self::normalizeText($text);

        // Проверяем первым: «Нет в наличии» содержит «в наличии», иначе попадёт не туда
        foreach (self::splitList($outOfStockValues) as $needle) {
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return ['qty' => null, 'status' => 'out_of_stock', 'text' => $text];
            }
        }

        // Чистое число — это количество
        $numeric = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], $text);
        $numeric = preg_replace('/^[>~≥+]+/u', '', $numeric) ?? $numeric;
        if (is_numeric($numeric)) {
            $qty = (int) round((float) $numeric);
            return ['qty' => $qty, 'status' => $qty > 0 ? 'in_stock' : 'out_of_stock', 'text' => $text];
        }

        // Число внутри текста: «Более 5», «от 10 шт», «5+ на складе»
        if (preg_match('/\d+/u', $normalized, $number) === 1) {
            $value = (int) $number[0];
            return ['qty' => null, 'status' => $value > 0 ? 'in_stock' : 'out_of_stock', 'text' => $text];
        }

        foreach (self::splitList($inStockValues) as $needle) {
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return ['qty' => null, 'status' => 'in_stock', 'text' => $text];
            }
        }

        return ['qty' => null, 'status' => 'out_of_stock', 'text' => $text];
    }

    /** @return list<string> */
    public static function splitList(string $list): array
    {
        $items = preg_split('/[,;\n]+/u', $list) ?: [];
        $result = [];
        foreach ($items as $item) {
            $item = self::normalizeText($item);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }

    public static function normalizeText(string $value): string
    {
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = trim(mb_strtolower($value, 'UTF-8'));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    /** Название/артикул: чистим переносы и лишние пробелы. */
    public static function text(string $value, int $maxLength, bool $collapseWhitespace = true): string
    {
        $value = str_replace(["\r", "\n", "\t", "\xc2\xa0"], ' ', $value);
        $value = $collapseWhitespace ? trim(preg_replace('/\s+/u', ' ', $value) ?? $value) : (trim($value) === '' ? '' : $value);

        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    /**
     * Валюта. Код из файла приводим к стандартному виду, но произвольное
     * обозначение поставщика («тг», «сум», «৳») тоже сохраняем как есть —
     * админ может задать свою валюту вручную.
     */
    public static function currency(string $raw, string $default): string
    {
        $value = trim($raw);
        if ($value === '') {
            return $default;
        }

        $map = [
            'РУБ' => 'RUB', 'Р' => 'RUB', 'Р.' => 'RUB', '₽' => 'RUB', 'RUR' => 'RUB',
            'ТГ' => 'KZT', 'ТЕНГЕ' => 'KZT', '₸' => 'KZT',
            'ГРН' => 'UAH', '₴' => 'UAH', 'БЕЛ.РУБ' => 'BYN',
            '$' => 'USD', 'ДОЛЛ' => 'USD', '€' => 'EUR', 'ЕВРО' => 'EUR',
        ];
        $upper = mb_strtoupper($value, 'UTF-8');
        if (isset($map[$upper])) {
            return $map[$upper];
        }
        if (preg_match('/^[A-Za-z]{2,8}$/', $value) === 1) {
            return strtoupper($value);
        }

        return mb_substr($value, 0, 8, 'UTF-8');
    }

    public static function imageUrl(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '' || mb_strlen($value) > 1000) {
            return null;
        }

        return preg_match('~^https?://~i', $value) === 1 ? $value : null;
    }
}
