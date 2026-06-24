<?php

namespace App\Enums;

enum BloomLevel: string
{
    case Remembering = 'remembering';
    case Understanding = 'understanding';
    case Applying = 'applying';
    case Analyzing = 'analyzing';
    case Evaluating = 'evaluating';
    case Creating = 'creating';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
