<?php

namespace App\Util;

final class HumanSize
{
    public static function format(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 KB';
        }

        if ($bytes < 1024 * 1024) {
            return self::formatValue($bytes / 1024) . ' KB';
        }

        return self::formatValue($bytes / (1024 * 1024)) . ' MB';
    }

    private static function formatValue(float $value): string
    {
        if ($value >= 100) {
            return number_format($value, 0, '.', '');
        }

        if ($value >= 10) {
            return number_format($value, 1, '.', '');
        }

        return number_format($value, 2, '.', '');
    }
}
