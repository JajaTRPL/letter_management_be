<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('keluarga_mahasiswas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mahasiswa_profile_id')->constrained('mahasiswa_profiles')->onDelete('cascade');
            
            $table->enum('jenis_relasi', ['ayah', 'ibu', 'wali', 'saudara']);
            $table->string('nama_lengkap');
            $table->string('pekerjaan')->nullable();
            $table->string('penghasilan')->nullable();
            $table->enum('status_hidup', ['hidup', 'meninggal'])->default('hidup');
            $table->date('tanggal_meninggal')->nullable();
            
            // Khusus saudara / keterangan tambahan
            $table->string('status_kawin')->nullable();
            $table->text('keterangan')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('keluarga_mahasiswas');
    }
};
