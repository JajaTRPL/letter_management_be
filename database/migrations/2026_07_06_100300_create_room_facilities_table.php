<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facilities attached to a room with quantity/condition/notes.
     * facility_type FK is restrict: a type in use cannot be deleted.
     * Condition values (baik|perlu_perbaikan|rusak) are enforced by app
     * validation, not a DB check, to stay portable.
     */
    public function up(): void
    {
        Schema::create('room_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('facility_type_id')->constrained('facility_types')->restrictOnDelete();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('condition', 32)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'facility_type_id'], 'room_facilities_room_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_facilities');
    }
};
