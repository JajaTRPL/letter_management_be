<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AcademicPeriod extends Model
{
    public const SEMESTER_TYPE_GANJIL = 'ganjil';
    public const SEMESTER_TYPE_GENAP  = 'genap';

    public const SEMESTER_TYPES = [
        self::SEMESTER_TYPE_GANJIL,
        self::SEMESTER_TYPE_GENAP,
    ];

    public const SEMESTER_ORDER_MAP = [
        self::SEMESTER_TYPE_GANJIL => 1,
        self::SEMESTER_TYPE_GENAP  => 2,
    ];

    protected $fillable = [
        'academic_year',
        'year_start',
        'semester_type',
        'semester_order',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'year_start' => 'integer',
        'semester_order' => 'integer',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
