<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->change(); // Change from enum to string for flexibility
            $table->string('sub_role')->nullable()->after('role');
        });

        // Migrate existing roles if they were somehow stored as kadep, etc.
        $subRoles = ['kadep', 'kaprodi', 'sekprodi', 'sekdep'];
        foreach ($subRoles as $role) {
            DB::table('users')->where('role', $role)->update([
                'role' => 'akademik',
                'sub_role' => $role
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sub_role');
            // Reverting back to enum is complex and might lose data if roles don't match exactly.
            // Keeping it as string is safer for down migration too.
        });
    }
};
