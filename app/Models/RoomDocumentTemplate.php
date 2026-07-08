<?php

namespace App\Models;

use App\Enums\RoomType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomDocumentTemplate extends Model
{
    public const SCOPE_CLASSROOM = 'classroom';
    public const SCOPE_LABORATORY = 'laboratory';

    public const SCOPES = [
        self::SCOPE_CLASSROOM,
        self::SCOPE_LABORATORY,
    ];

    public const MIME_PDF = 'application/pdf';
    public const MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** Upload allowlist, enforced by CP2 validation. */
    public const MIMES = [
        self::MIME_PDF,
        self::MIME_DOCX,
    ];

    protected $fillable = [
        'scope',
        'laboratory_id',
        'storage_disk',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'checksum_sha256',
        'version',
        'is_active',
        'notes',
        'uploaded_by',
    ];

    /**
     * Storage internals never leave the API by default; downloads go
     * through the authenticated template route (CP2), addressed by id.
     */
    protected $hidden = [
        'storage_disk',
        'path',
    ];

    protected $casts = [
        'laboratory_id' => 'integer',
        'size_bytes' => 'integer',
        'version' => 'integer',
        'is_active' => 'boolean',
        'uploaded_by' => 'integer',
    ];

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Resolve the template a mahasiswa should download for a room:
     * the room's lab-specific active template wins, otherwise the
     * category-wide active template for the room type.
     */
    public static function activeForRoom(Room $room): ?self
    {
        $scope = $room->type === RoomType::Laboratory
            ? self::SCOPE_LABORATORY
            : self::SCOPE_CLASSROOM;

        if ($scope === self::SCOPE_LABORATORY && $room->owning_laboratory_id) {
            $labTemplate = self::query()
                ->active()
                ->where('scope', $scope)
                ->where('laboratory_id', $room->owning_laboratory_id)
                ->latest('version')
                ->first();

            if ($labTemplate) {
                return $labTemplate;
            }
        }

        return self::query()
            ->active()
            ->where('scope', $scope)
            ->whereNull('laboratory_id')
            ->latest('version')
            ->first();
    }
}
