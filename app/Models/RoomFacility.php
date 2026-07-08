<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomFacility extends Model
{
    public const CONDITION_BAIK = 'baik';
    public const CONDITION_PERLU_PERBAIKAN = 'perlu_perbaikan';
    public const CONDITION_RUSAK = 'rusak';

    /** Valid values for the condition column (app-enforced, not DB check). */
    public const CONDITIONS = [
        self::CONDITION_BAIK,
        self::CONDITION_PERLU_PERBAIKAN,
        self::CONDITION_RUSAK,
    ];

    protected $fillable = [
        'room_id',
        'facility_type_id',
        'quantity',
        'condition',
        'notes',
    ];

    protected $casts = [
        'room_id' => 'integer',
        'facility_type_id' => 'integer',
        'quantity' => 'integer',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function facilityType(): BelongsTo
    {
        return $this->belongsTo(FacilityType::class);
    }
}
