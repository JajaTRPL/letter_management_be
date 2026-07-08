<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type', 32);
            $table->unsignedInteger('capacity');
            $table->string('location');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('owning_laboratory_id')
                ->nullable()
                ->constrained('laboratories')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active'], 'rooms_type_active_idx');
            $table->index('owning_laboratory_id', 'rooms_owning_lab_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
