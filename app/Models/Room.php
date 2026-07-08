<?php

namespace App\Models;

use App\Enums\RoomType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'capacity',
        'location',
        'description',
        'rules',
        'is_active',
        'owning_laboratory_id',
    ];

    protected $casts = [
        'type' => RoomType::class,
        'capacity' => 'integer',
        'is_active' => 'boolean',
        'owning_laboratory_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Room $room): void {
            if ((int) $room->capacity < 1) {
                throw new InvalidArgumentException('Room capacity must be a positive integer.');
            }

            if ($room->type === RoomType::Classroom && $room->owning_laboratory_id !== null) {
                throw new InvalidArgumentException('Classroom rooms cannot have an owning laboratory.');
            }

            if ($room->type === RoomType::Laboratory && $room->owning_laboratory_id === null) {
                throw new InvalidArgumentException('Laboratory rooms must have an owning laboratory.');
            }
        });
    }

    public function owningLaboratory()
    {
        return $this->belongsTo(Laboratory::class, 'owning_laboratory_id');
    }

    public function roomBookingRequests()
    {
        return $this->hasMany(RoomBookingRequest::class);
    }

    public function photos()
    {
        return $this->hasMany(RoomPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function coverPhoto()
    {
        return $this->hasOne(RoomPhoto::class)->where('is_cover', true);
    }

    public function facilityItems()
    {
        return $this->hasMany(RoomFacility::class);
    }

    public function facilities()
    {
        return $this->belongsToMany(FacilityType::class, 'room_facilities')
            ->withPivot(['quantity', 'condition', 'notes'])
            ->withTimestamps();
    }

    public function auditLogs()
    {
        return $this->hasMany(RoomAuditLog::class)->latest('created_at');
    }

    /**
     * Templates are scoped by room type + owning lab, not by room id;
     * this resolves the one a mahasiswa should download for this room.
     */
    public function activeDocumentTemplate(): ?RoomDocumentTemplate
    {
        return RoomDocumentTemplate::activeForRoom($this);
    }
}
