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
        Schema::create('scholarship_siblings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_application_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('job_or_school');
            $table->enum('marital_status', ['Belum Kawin', 'Kawin']);
            $table->enum('relation', ['Kakak', 'Adik']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scholarship_siblings');
    }
};
