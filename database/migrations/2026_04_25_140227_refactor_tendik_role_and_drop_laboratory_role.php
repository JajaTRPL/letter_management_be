<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Data Migration (Transactional)
        DB::transaction(function () {
            // Map laboratory_role -> tendik_role (ONLY if tendik_role is null)
            DB::table('users')
                ->where('role', 'tendik')
                ->whereNull('tendik_role')
                ->whereNotNull('laboratory_role')
                ->whereIn('laboratory_role', ['kepala_lab', 'laboran'])
                ->update([
                    'tendik_role' => DB::raw('laboratory_role')
                ]);

            // Default value fallback: Fill remaining null tendik_roles with 'persuratan'
            DB::table('users')
                ->where('role', 'tendik')
                ->whereNull('tendik_role')
                ->update(['tendik_role' => 'persuratan']);
        });

        // 2. Safety Check (Log warning if non-tendik users had a lab role)
        $strayCount = DB::table('users')
            ->whereNotNull('laboratory_role')
            ->where('role', '!=', 'tendik')
            ->count();

        if ($strayCount > 0) {
            Log::warning("Found {$strayCount} non-tendik users with laboratory_role data.");

            if (app()->environment(['local', 'development', 'staging'])) {
                throw new \Exception("Stray laboratory_role data detected during migration.");
            }
        }

        // 3. Schema Change (Non-transactional)
        if (Schema::hasColumn('users', 'laboratory_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('laboratory_role');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('laboratory_role')->nullable()->after('tendik_role');
        });
        
        // NOTE: laboratory_role data is not restored (intentional destructive migration)
    }
};
