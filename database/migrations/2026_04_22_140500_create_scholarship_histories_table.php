<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mahasiswa_profile_id');
            $table->string('nama_beasiswa');
            $table->string('periode')->nullable();
            $table->string('jumlah')->nullable();
            $table->string('status')->default('Selesai');
            $table->timestamps();

            $table->foreign('mahasiswa_profile_id')
                  ->references('id')
                  ->on('mahasiswa_profiles')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_histories');
    }
};
