<?php

namespace App\Services;

use App\Enums\PasswordSetMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordCredentialService
{
    private const METADATA_COLUMNS = [
        'password_set_method',
        'password_set_at',
        'password_set_by_user_id',
        'password_must_rotate',
    ];

    /**
     * Build password attributes for a single model create/update operation.
     *
     * The schema check keeps deployment ordering safe: application code may be
     * deployed before the additive metadata migration without breaking password
     * reset or staff provisioning.
     *
     * @return array<string, mixed>
     */
    public function attributes(
        string $plainPassword,
        PasswordSetMethod $method,
        ?int $setByUserId = null,
        bool $mustRotate = false,
    ): array {
        $attributes = [
            'password' => Hash::make($plainPassword),
        ];

        if ($this->metadataAvailable()) {
            $attributes += [
                'password_set_method' => $method->value,
                'password_set_at' => now(),
                'password_set_by_user_id' => $setByUserId,
                'password_must_rotate' => $mustRotate,
            ];
        }

        return $attributes;
    }

    public function fill(
        User $user,
        string $plainPassword,
        PasswordSetMethod $method,
        ?int $setByUserId = null,
        bool $mustRotate = false,
    ): User {
        return $user->forceFill($this->attributes(
            $plainPassword,
            $method,
            $setByUserId,
            $mustRotate,
        ));
    }

    public function metadataAvailable(): bool
    {
        return Schema::hasTable('users')
            && Schema::hasColumns('users', self::METADATA_COLUMNS);
    }

    public function revokeAccess(User $user): void
    {
        $user->tokens()->delete();

        if (Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }
    }

    public function completeRequiredRotation(User $user, string $plainPassword): bool
    {
        return DB::transaction(function () use ($user, $plainPassword): bool {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if (!$lockedUser || !$lockedUser->password_must_rotate) {
                return false;
            }

            if ($lockedUser->password && Hash::check($plainPassword, $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'password' => 'Kata sandi baru tidak boleh sama dengan kata sandi saat ini.',
                ]);
            }

            $this->fill(
                $lockedUser,
                $plainPassword,
                PasswordSetMethod::SelfServiceChange,
                $lockedUser->getKey(),
            );

            if (Schema::hasColumn('users', 'remember_token')) {
                $lockedUser->setRememberToken(Str::random(60));
            }

            $lockedUser->save();
            $this->invalidatePasswordResetState($lockedUser);
            $this->revokeAccess($lockedUser);

            return true;
        });
    }

    public function invalidatePasswordResetState(User $user): void
    {
        if (!Schema::hasTable('password_reset_tokens')) {
            return;
        }

        $updates = [
            'token' => Hash::make(Str::random(64)),
            'created_at' => now(),
        ];

        $optionalUpdates = [
            'expires_at' => now(),
            'is_verified' => false,
            'attempts' => 0,
            'verified_at' => null,
            'reset_token' => null,
            'reset_token_expires_at' => null,
            'used_at' => now(),
        ];

        foreach ($optionalUpdates as $column => $value) {
            if (Schema::hasColumn('password_reset_tokens', $column)) {
                $updates[$column] = $value;
            }
        }

        DB::table('password_reset_tokens')
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim((string) $user->email))])
            ->update($updates);
    }
}
