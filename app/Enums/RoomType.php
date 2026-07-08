<?php

namespace App\Enums;

enum RoomType: string
{
    case Classroom = 'classroom';
    case Laboratory = 'laboratory';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
