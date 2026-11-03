<?php
declare(strict_types=1);

namespace App\Import\Readers;

use Generator;
use RuntimeException;

/**
 * Чтение старого формата Excel 97–2003 (.xls, записи BIFF5/BIFF8)
 * средствами самого PHP — без внешних библиотек.
 *
 * Разбираются только те записи, из которых состоит обычный прайс:
 * строки (общая таблица строк SST), числа, RK-числа и результаты формул.
 * Оформление, картинки и формулы как таковые не нужны и пропускаются.
 */
final class XlsReader implements RowReader, SkipsHidden
{
    /* Служебные записи */
    private const BOF        = 0x0809;
    private const EOF        = 0x000A;
    private const BOUNDSHEET = 0x0085;
    private const SST        = 0x00FC;
    private const CONTINUE   = 0x003C;
    private const CODEPAGE   = 0x0042;

    /* Записи с данными ячеек */
    private const LABELSST = 0x00FD;
    private const LABEL    = 0x0204;
    private const RSTRING  = 0x00D6;
    private const NUMBER   = 0x0203;
    private const RK       = 0x027E;
    private const MULRK    = 0x00BD;
    private const FORMULA  = 0x0006;
    private const STRING   = 0x0207;
    private const BOOLERR  = 0x0205;

    /* Служебные записи со сведениями о скрытых строках и столбцах */
    private const ROW     = 0x0208;
    private const COLINFO = 0x007D;

    /** Насколько далеко назад допускаем «неупорядоченные» строки перед выдачей. */
    private const ROW_WINDOW = 32;

    private string $stream = '';
    /** @var list<string> */
    private array $sst = [];
    /** @var list<array{name:string,position:int}> */
    private array $sheets = [];
    private int $version = 0x0600;
    private string $encoding = 'CP1251';
    private array $formats = ExcelDateFormat::BUILTIN;
    private array $xfFormats = [];
    private bool $date1904 = false;

    /** @var array<int,true> */
    private array $hiddenColumns = [];
    /** @var array<int,true> */
    private array $hiddenRowIndexes = [];

    public function __construct(
        private readonly string $path,
        private readonly int $sheetIndex = 1,
        private readonly bool $skipHidden = true,
    ) {
    }

    public function hiddenRowsSkipped(): int
    {
        return count($this->hiddenRowIndexes);
    }

    public function hiddenColumnsSkipped(): int
    {
        return count($this->hiddenColumns);
    }

    public function rows(): Generator
    {
        $this->load();

        $number = max(1, $this->sheetIndex);
        if ($this->sheets === []) {
            throw new RuntimeException('В книге .xls не найдено ни одного листа.');
        }
        if (!isset($this->sheets[$number - 1])) {
            throw new RuntimeException(sprintf(
                'В книге всего %d лист(ов), а указан лист №%d.',
                count($this->sheets),
                $number
            ));
        }

        yield from $this->readSheet($this->sheets[$number - 1]['position']);
    }

    public function preview(int $limit = 10): array
    {
        $rows = [];
        foreach ($this->rows() as $row) {
            $rows[] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<string> названия листов книги */
    public function sheetNames(): array
    {
        $this->load();

        return array_map(static fn (array $sheet): string => $sheet['name'], $this->sheets);
    }

    /* ---------------- чтение книги ---------------- */

    private function load(): void
    {
        if ($this->stream !== '') {
            return;
        }

        $cfb = new Cfb($this->path);
        $stream = $cfb->stream('Workbook') ?? $cfb->stream('Book');
        if ($stream === null) {
            throw new RuntimeException(
                'В файле .xls нет потока с данными книги. Возможно, это другой документ Office. '
                . 'Потоки в файле: ' . implode(', ', array_slice($cfb->streamNames(), 0, 5)) . '.'
            );
        }

        $this->stream = $stream;
        $this->readGlobals();
    }

    /** Первый проход: версия, кодировка, список листов, таблица строк. */
    private function readGlobals(): void
    {
        $position = 0;
        $length = strlen($this->stream);

        while ($position + 4 <= $length) {
            [$type, $size, $data, $next] = $this->record($position);

            if ($type === self::BOF && $position === 0) {
                $this->version = $this->uint16($data, 0);
            } elseif ($type === self::CODEPAGE) {
                $this->encoding = match ($this->uint16($data, 0)) {
                    1252    => 'CP1252',
                    10007   => 'x-mac-cyrillic',
                    65001   => 'UTF-8',
                    default => 'CP1251',
                };
            } elseif ($type === 0x0022) { // DATEMODE
                $this->date1904 = $this->uint16($data, 0) === 1;
            } elseif ($type === 0x00E0) { // XF: index of number format
                $this->xfFormats[] = $this->uint16($data, 2);
            } elseif ($type === 0x041E) { // FORMAT
                $this->formats[$this->uint16($data, 0)] = $this->version >= 0x0600
                    ? $this->longString($data, 2)
                    : $this->decodeBytes(substr($data, 3, ord($data[2] ?? "\0")));
            } elseif ($type === self::BOUNDSHEET) {
                $this->sheets[] = [
                    'name'     => $this->shortString($data, 6),
                    'position' => $this->uint32($data, 0),
                ];
            } elseif ($type === self::SST) {
                [$this->sst, $next] = $this->readSst($data, $next);
            } elseif ($type === self::EOF) {
                break;
            }

            $position = $next;
            if ($size === 0 && $type === 0) {
                break; // защита от мусора в конце потока
            }
        }
    }

    /**
     * Общая таблица строк. Длинные таблицы разрезаны на записи CONTINUE,
     * причём строка может обрываться посередине, а в следующей записи
     * первый байт заново сообщает кодировку остатка.
     *
     * @return array{0:list<string>,1:int}
     */
    private function readSst(string $data, int $next): array
    {
        $segments = [$data];
        $position = $next;
        $length = strlen($this->stream);

        while ($position + 4 <= $length) {
            $type = $this->uint16($this->stream, $position);
            if ($type !== self::CONTINUE) {
                break;
            }
            [, , $chunk, $position] = $this->record($position);
            $segments[] = $chunk;
        }

        $strings = [];
        $unique = $this->uint32($segments[0], 4);
        $segment = 0;
        $offset = 8;

        $available = static fn (int $seg, int $off): int => strlen($segments[$seg] ?? '') - $off;

        for ($i = 0; $i < $unique; $i++) {
            // Заголовок строки не разрезается — если он не помещается, берём следующий кусок
            if ($available($segment, $offset) < 3) {
                $segment++;
                $offset = 0;
                if (!isset($segments[$segment])) {
                    break;
                }
            }

            $chars = $this->uint16($segments[$segment], $offset);
            $flags = ord($segments[$segment][$offset + 2]);
            $offset += 3;

            $wide = ($flags & 0x01) === 0x01;
            $rich = ($flags & 0x08) === 0x08;
            $far  = ($flags & 0x04) === 0x04;

            $runs = 0;
            if ($rich) {
                $runs = $this->uint16($segments[$segment], $offset);
                $offset += 2;
            }
            $extra = 0;
            if ($far) {
                $extra = $this->uint32($segments[$segment], $offset);
                $offset += 4;
            }

            $text = '';
            $left = $chars;
            while ($left > 0) {
                $step = $wide ? 2 : 1;
                $canRead = intdiv(max(0, $available($segment, $offset)), $step);

                if ($canRead <= 0) {
                    // Строка продолжается в следующей записи, её кодировка — в первом байте
                    $segment++;
                    $offset = 0;
                    if (!isset($segments[$segment])) {
                        break 2;
                    }
                    $wide = (ord($segments[$segment][0]) & 0x01) === 0x01;
                    $offset = 1;
                    continue;
                }

                $take = min($left, $canRead);
                $raw = substr($segments[$segment], $offset, $take * $step);
                $text .= $wide
                    ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
                    : $this->decodeBytes($raw);

                $offset += $take * $step;
                $left -= $take;
            }

            // Оформление и фонетику пропускаем, они тоже могут уезжать в CONTINUE
            $skip = $runs * 4 + $extra;
            while ($skip > 0) {
                $canSkip = min($skip, max(0, $available($segment, $offset)));
                $offset += $canSkip;
                $skip -= $canSkip;
                if ($skip > 0) {
                    $segment++;
                    $offset = 0;
                    if (!isset($segments[$segment])) {
                        break 2;
                    }
                }
            }

            $strings[] = $text;
        }

        return [$strings, $position];
    }

    /** @return Generator<int, list<string>> */
    private function readSheet(int $start): Generator
    {
        $position = $start;
        $length = strlen($this->stream);
        $rows = [];
        $maxRow = -1;
        $this->hiddenColumns = [];
        $this->hiddenRowIndexes = [];
        $emitted = -1;
        $pendingFormula = null; // [row, col] — ждём запись STRING с результатом

        while ($position + 4 <= $length) {
            [$type, $size, $data, $next] = $this->record($position);
            $position = $next;

            if ($type === self::EOF) {
                break;
            }

            // Сведения о скрытых строках и столбцах идут до самих ячеек
            if ($type === self::ROW && $this->skipHidden) {
                if (($this->uint16($data, 12) & 0x20) === 0x20) {
                    $this->hiddenRowIndexes[$this->uint16($data, 0)] = true;
                }
                continue;
            }
            if ($type === self::COLINFO && $this->skipHidden) {
                if (($this->uint16($data, 8) & 0x0001) === 0x0001) {
                    $first = $this->uint16($data, 0);
                    $last = max($first, $this->uint16($data, 2));
                    for ($column = $first; $column <= $last; $column++) {
                        $this->hiddenColumns[$column] = true;
                    }
                }
                continue;
            }

            $row = null;
            $col = null;
            $value = null;

            switch ($type) {
                case self::LABELSST:
                    $row = $this->uint16($data, 0);
                    $col = $this->uint16($data, 2);
                    $value = $this->sst[$this->uint32($data, 6)] ?? '';
                    break;

                case self::LABEL:
                case self::RSTRING:
                    $row = $this->uint16($data, 0);
                    $col = $this->uint16($data, 2);
                    $value = $this->version >= 0x0600
                        ? $this->longString($data, 6)
                        : $this->decodeBytes(substr($data, 8, $this->uint16($data, 6)));
                    break;

                case self::NUMBER:
                    $row = $this->uint16($data, 0);
                    $col = $this->uint16($data, 2);
                    $value = $this->formatCell((float) unpack('e', substr($data, 6, 8))[1], $this->uint16($data, 4));
                    break;

                case self::RK:
                    $row = $this->uint16($data, 0);
                    $col = $this->uint16($data, 2);
                    $value = $this->formatCell($this->rkValue(substr($data, 6, 4)), $this->uint16($data, 4));
                    break;

                case self::MULRK:
                    $row = $this->uint16($data, 0);
                    $first = $this->uint16($data, 2);
                    $count = intdiv(strlen($data) - 6, 6);
                    // строка(2) + первый столбец(2), далее пары: индекс оформления(2) + число(4)
                    for ($i = 0; $i < $count; $i++) {
                        $rows[$row][$first + $i] = $this->formatCell(
                            $this->rkValue(substr($data, 4 + $i * 6 + 2, 4)), $this->uint16($data, 4 + $i * 6)
                        );
                    }
                    $maxRow = max($maxRow, $row);
                    break;

                case self::BOOLERR:
                    $row = $this->uint16($data, 0);
                    $col = $this->uint16($data, 2);
                    $value = ord($data[7] ?? "\0") === 0 ? (ord($data[6]) === 1 ? '1' : '0') : '';
                    break;

                case self::FORMULA:
                    $row = $this->uint16($data, 0);
                    $col = $this->uint16($data, 2);
                    $result = substr($data, 6, 8);
                    if (substr($result, 6, 2) === "\xFF\xFF") {
                        $kind = ord($result[0]);
                        if ($kind === 0) {
                            $pendingFormula = [$row, $col]; // текст придёт следующей записью
                            $row = null;
                        } elseif ($kind === 1) {
                            $value = ord($result[2]) === 1 ? '1' : '0';
                        } else {
                            $value = ''; // ошибка или пусто
                        }
                    } else {
                        $value = $this->formatCell((float) unpack('e', $result)[1], $this->uint16($data, 4));
                    }
                    break;

                case self::STRING:
                    if ($pendingFormula !== null) {
                        [$row, $col] = $pendingFormula;
                        $value = $this->version >= 0x0600
                            ? $this->longString($data, 0)
                            : $this->decodeBytes(substr($data, 2, $this->uint16($data, 0)));
                        $pendingFormula = null;
                    }
                    break;
            }

            if ($row !== null && $col !== null && $value !== null) {
                $rows[$row][$col] = $value;
                $maxRow = max($maxRow, $row);
            }

            // Отдаём строки, которые уже точно дописаны
            while ($emitted + 1 <= $maxRow - self::ROW_WINDOW) {
                $emitted++;
                if (isset($this->hiddenRowIndexes[$emitted])) {
                    unset($rows[$emitted]);
                    continue;
                }
                yield $this->buildRow($rows[$emitted] ?? []);
                unset($rows[$emitted]);
            }
        }

        for ($index = $emitted + 1; $index <= $maxRow; $index++) {
            if (isset($this->hiddenRowIndexes[$index])) {
                continue;
            }
            yield $this->buildRow($rows[$index] ?? []);
        }
    }

    /**
     * @param array<int,string> $cells
     * @return list<string>
     */
    private function buildRow(array $cells): array
    {
        if ($cells === []) {
            return [];
        }
        ksort($cells);
        $row = [];
        for ($i = 0; $i <= array_key_last($cells); $i++) {
            // Скрытые столбцы очищаем, позиции сохраняем — маппинг по буквам не съедет
            $row[$i] = isset($this->hiddenColumns[$i]) ? '' : ($cells[$i] ?? '');
        }

        return $row;
    }

    /* ---------------- элементарные операции ---------------- */

    /** @return array{0:int,1:int,2:string,3:int} тип, длина, данные, позиция следующей записи */
    private function record(int $position): array
    {
        $type = $this->uint16($this->stream, $position);
        $size = $this->uint16($this->stream, $position + 2);

        return [$type, $size, substr($this->stream, $position + 4, $size), $position + 4 + $size];
    }

    private function uint16(string $string, int $offset): int
    {
        $chunk = substr($string, $offset, 2);
        return strlen($chunk) < 2 ? 0 : (int) unpack('v', $chunk)[1];
    }

    private function uint32(string $string, int $offset): int
    {
        $chunk = substr($string, $offset, 4);
        return strlen($chunk) < 4 ? 0 : (int) unpack('V', $chunk)[1];
    }

    /** Строка с однобайтовой длиной (название листа). */
    private function shortString(string $data, int $offset): string
    {
        $length = ord($data[$offset] ?? "\0");
        if ($this->version < 0x0600) {
            return $this->decodeBytes(substr($data, $offset + 1, $length));
        }

        $wide = (ord($data[$offset + 1] ?? "\0") & 0x01) === 0x01;
        $raw = substr($data, $offset + 2, $length * ($wide ? 2 : 1));

        return $wide ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : $this->decodeBytes($raw);
    }

    /** Строка с двухбайтовой длиной (значение ячейки). */
    private function longString(string $data, int $offset): string
    {
        $length = $this->uint16($data, $offset);
        $wide = (ord($data[$offset + 2] ?? "\0") & 0x01) === 0x01;
        $raw = substr($data, $offset + 3, $length * ($wide ? 2 : 1));

        return $wide ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : $this->decodeBytes($raw);
    }

    /** Однобайтовый текст: в BIFF он в кодировке книги, обычно Windows-1251. */
    private function decodeBytes(string $raw): string
    {
        if ($raw === '' || $this->encoding === 'UTF-8') {
            return $raw;
        }
        $converted = @iconv($this->encoding, 'UTF-8//TRANSLIT', $raw);

        return $converted === false ? $raw : $converted;
    }

    /** Число в упакованном формате RK. */
    private function rkValue(string $raw): float
    {
        if (strlen($raw) < 4) {
            return 0.0;
        }
        $bits = (int) unpack('V', $raw)[1];

        if (($bits & 0x02) === 0x02) {
            $number = $bits >> 2;
            if (($number & 0x20000000) === 0x20000000) {
                $number -= 0x40000000; // отрицательное 30-битное
            }
            $value = (float) $number;
        } else {
            $value = (float) unpack('e', "\x00\x00\x00\x00" . pack('V', $bits & 0xFFFFFFFC))[1];
        }

        return ($bits & 0x01) === 0x01 ? $value / 100 : $value;
    }

    private function formatCell(float $value, int $xf): string
    {
        $raw = $this->formatNumber($value);
        $format = $this->formats[$this->xfFormats[$xf] ?? -1] ?? '';
        return ExcelDateFormat::render($raw, $format, $this->date1904) ?? $raw;
    }

    /** Число в строку без экспоненты и лишних нулей — дальше его разбирает ValueParser. */
    private function formatNumber(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return '';
        }
        if (floor($value) === $value && abs($value) < 1.0e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }
}
