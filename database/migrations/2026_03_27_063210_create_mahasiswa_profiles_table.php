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
        Schema::create('mahasiswa_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // SSO Data / Akademik Dasar
            $table->string('nim')->nullable()->unique();
            $table->string('fakultas')->nullable();
            $table->string('program_studi')->nullable();
            
            // Detail Profil
            $table->string('tempat_lahir')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->enum('jenis_kelamin', ['L', 'P'])->nullable();
            $table->string('no_hp')->nullable();
            $table->text('alamat_asal')->nullable();
            $table->text('alamat_domisili')->nullable();
            
            // Uploads
            $table->string('pas_foto_path')->nullable();
            $table->string('tanda_tangan_path')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mahasiswa_profiles');
    }
};
