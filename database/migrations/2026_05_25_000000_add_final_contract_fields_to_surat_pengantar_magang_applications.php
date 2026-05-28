<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surat_pengantar_magang_applications', function (Blueprint $table) {
            $table->string('nomor_surat_pengantar')->nullable();
            $table->string('nomor_surat_tugas')->nullable();
            $table->string('jabatan_penerima')->nullable();
            $table->text('alamat_jalan')->nullable();
            $table->string('alamat_kelurahan')->nullable();
            $table->string('alamat_kecamatan')->nullable();
            $table->string('alamat_kota_kabupaten')->nullable();
            $table->string('alamat_provinsi')->nullable();
            $table->string('kode_pos')->nullable();
            $table->date('tgl_mulai')->nullable();
            $table->date('tgl_selesai')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('surat_pengantar_magang_applications', function (Blueprint $table) {
            $table->dropColumn([
                'nomor_surat_pengantar',
                'nomor_surat_tugas',
                'jabatan_penerima',
                'alamat_jalan',
                'alamat_kelurahan',
                'alamat_kecamatan',
                'alamat_kota_kabupaten',
                'alamat_provinsi',
                'kode_pos',
                'tgl_mulai',
                'tgl_selesai',
            ]);
        });
    }
};
