<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_set_method', 32)->nullable()->after('password');
            $table->timestamp('password_set_at')->nullable()->after('password_set_method');
            $table->foreignId('password_set_by_user_id')
                ->nullable()
                ->after('password_set_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->boolean('password_must_rotate')
                ->default(false)
                ->after('password_set_by_user_id');
        });

        // Existing non-null credentials have no trustworthy provenance.
        // Do not infer that they came from OTP reset, and do not force rotation
        // until a separate policy/change campaign is explicitly approved.
        DB::table('users')
            ->whereNotNull('password')
            ->whereNull('password_set_method')
            ->update([
                'password_set_method' => 'legacy_unknown',
                'password_set_at' => null,
                'password_set_by_user_id' => null,
                'password_must_rotate' => false,
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('password_set_by_user_id');
            $table->dropColumn([
                'password_set_method',
                'password_set_at',
                'password_must_rotate',
            ]);
        });
    }
};
