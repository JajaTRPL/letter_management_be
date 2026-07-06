<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomPhoto extends Model
{
    public const VARIANT_THUMB = 'thumb';
    public const VARIANT_DISPLAY = 'display';
    public const VARIANT_FULL = 'full';

    public const VARIANTS = [
        self::VARIANT_THUMB,
        self::VARIANT_DISPLAY,
        self::VARIANT_FULL,
    ];

    protected $fillable = [
        'room_id',
        'storage_disk',
        'thumb_path',
        'display_path',
        'full_path',
        'original_name',
        'mime',
        'size_bytes',
        'width',
        'height',
        'checksum_sha256',
        'is_cover',
        'sort_order',
        'uploaded_by',
    ];

    /**
     * Storage internals never leave the API by default; delivery goes
     * through the authenticated media route (CP2), addressed by id+variant.
     */
    protected $hidden = [
        'storage_disk',
        'thumb_path',
        'display_path',
        'full_path',
    ];

    protected $casts = [
        'room_id' => 'integer',
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'is_cover' => 'boolean',
        'sort_order' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function pathForVariant(string $variant): ?string
    {
        return match ($variant) {
            self::VARIANT_THUMB => $this->thumb_path,
            self::VARIANT_DISPLAY => $this->display_path,
            self::VARIANT_FULL => $this->full_path,
            default => null,
        };
    }
}
