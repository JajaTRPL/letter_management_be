<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Enums\PasswordSetMethod;
use App\Enums\UserStatus;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'nip',
        'password',
        'password_set_method',
        'password_set_at',
        'password_set_by_user_id',
        'password_must_rotate',
        'google_id',
        'avatar_url',
        'role',
        'sub_role',
        'tendik_role',
        'laboratory_id',
        'study_program_id',
        'department_id',
        'role_level',
        'status',
        'last_login_at',
        'assigned_tasks',
        'photo_path',
        'signature_path',
    ];

    protected $hidden = [
        'password',
        'password_set_method',
        'password_set_at',
        'password_set_by_user_id',
        'password_must_rotate',
        'remember_token',
        'google_id',
        'last_login_at',
    ];

    protected $appends = ['akademik_label'];

    protected $casts = [
        'assigned_tasks' => 'array',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
        'password_set_method' => PasswordSetMethod::class,
        'password_set_at' => 'datetime',
        'password_must_rotate' => 'boolean',
        'status' => UserStatus::class,
    ];

    public function activityLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function passwordSetBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'password_set_by_user_id');
    }

    public function mahasiswaProfile()
    {
        return $this->hasOne(MahasiswaProfile::class);
    }

    public function studyProgram()
    {
        return $this->belongsTo(StudyProgram::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function laboratory()
    {
        return $this->belongsTo(Laboratory::class);
    }

    public function requestedRoomBookings()
    {
        return $this->hasMany(RoomBookingRequest::class, 'requester_id');
    }

    public function reviewedRoomBookings()
    {
        return $this->hasMany(RoomBookingRequest::class, 'reviewer_id');
    }

    /**
     * Check if this user is a Primary Super Admin
     */
    public function isPrimarySuperAdmin(): bool
    {
        return $this->role === 'super_admin' && $this->role_level === 'primary';
    }

    /**
     * Check if this user is a Secondary Super Admin
     */
    public function isSecondarySuperAdmin(): bool
    {
        return $this->role === 'super_admin' && $this->role_level === 'secondary';
    }

    /**
     * Accessor: Returns formatted akademik label.
     * Kaprodi TRPL, Sekprodi TRI, Kadep, Sekdep
     */
    public function getAkademikLabelAttribute(): ?string
    {
        if ($this->role !== 'akademik' || !$this->sub_role) {
            return null;
        }

        $labels = [
            'kaprodi' => 'Kaprodi',
            'sekprodi' => 'Sekprodi',
            'kadep' => 'Kadep',
            'sekdep' => 'Sekdep',
        ];

        $label = $labels[$this->sub_role] ?? ucfirst($this->sub_role);

        // Append study program code for kaprodi/sekprodi
        if (in_array($this->sub_role, ['kaprodi', 'sekprodi']) && $this->study_program_id) {
            $program = $this->relationLoaded('studyProgram')
                ? $this->studyProgram
                : $this->studyProgram()->first();
            if ($program) {
                $label .= ' ' . $program->code;
            }
        }

        return $label;
    }

    /**
     * Check if this user is a Tendik with Persuratan specialization.
     */
    public function isTendikPersuratan(): bool
    {
        return $this->role === 'tendik' && $this->tendik_role === 'persuratan';
    }

    /**
     * Check if this user is a Tendik with Sarpras specialization.
     */
    public function isTendikSarpras(): bool
    {
        return $this->role === 'tendik' && $this->tendik_role === 'sarpras';
    }

    /**
     * Check if this user is Kepala Lab.
     */
    public function isKalab(): bool
    {
        return $this->role === 'tendik' && $this->tendik_role === 'kepala_lab';
    }

    /**
     * Check if this user is Laboran.
     */
    public function isLaboran(): bool
    {
        return $this->role === 'tendik' && $this->tendik_role === 'laboran';
    }
}
