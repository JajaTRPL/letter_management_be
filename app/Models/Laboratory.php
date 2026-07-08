<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Laboratory extends Model
{
    protected $fillable = ['name', 'code', 'department_id'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function users()
    {
        return $this->hasMany(User::class, 'laboratory_id');
    }

    public function rooms()
    {
        return $this->hasMany(Room::class, 'owning_laboratory_id');
    }

    public function roomDocumentTemplates()
    {
        return $this->hasMany(RoomDocumentTemplate::class);
    }
}
