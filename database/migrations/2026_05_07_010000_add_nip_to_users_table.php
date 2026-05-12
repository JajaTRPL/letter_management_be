<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'nip')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('nip', 50)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'nip')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['nip']);
            $table->dropColumn('nip');
        });
    }
};
