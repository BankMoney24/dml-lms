<?php

declare(strict_types=1);

namespace App\Modules\Reports;

/**
 * CSV for spreadsheets. Starts with a UTF-8 byte-order mark so Excel shows
 * names correctly, and neutralises cells a spreadsheet would run as a formula
 * (=, +, -, @, tab, carriage return) by prefixing an apostrophe.
 */
final class Csv
{
    /**
     * @param list<string> $header
     * @param iterable<list<string|int|null>> $rows
     */
    public static function build(array $header, iterable $rows): string
    {
        $out = "\xEF\xBB\xBF" . self::line($header);
        foreach ($rows as $row) {
            $out .= self::line($row);
        }

        return $out;
    }

    /** @param list<string|int|null> $cells */
    private static function line(array $cells): string
    {
        return implode(',', array_map(self::cell(...), $cells)) . "\r\n";
    }

    private static function cell(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            $value = "'" . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
