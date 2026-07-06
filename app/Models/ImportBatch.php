<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const KIND_VERIFIED_MAHASISWA = 'verified_mahasiswa';

    protected $guarded = ['id'];

    protected $casts = [
        'override_existing_active' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Batches are addressed externally by UUID, never by auto-increment id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportBatchRow::class);
    }

    public function errorRows(): HasMany
    {
        return $this->rows()->whereIn('status', [
            ImportBatchRow::STATUS_INVALID,
            ImportBatchRow::STATUS_FAILED,
        ]);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
