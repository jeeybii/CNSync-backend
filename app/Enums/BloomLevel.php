<?php

namespace App\Enums;

enum BloomLevel: string
{
    case Knowledge = 'knowledge';
    case Comprehension = 'comprehension';
    case Application = 'application';
    case Analysis = 'analysis';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
