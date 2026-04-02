<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add mahasiswa_profile_id to scholarship_applications
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->foreignId('mahasiswa_profile_id')->nullable()->after('user_id')->constrained('mahasiswa_profiles')->onDelete('set null');
        });

        // 2. Data Migration: Move data from scholarship_applications to profiles and keluarga
        $applications = DB::table('scholarship_applications')->get();

        foreach ($applications as $app) {
            // Update or Create Profile
            DB::table('mahasiswa_profiles')->updateOrInsert(
                ['user_id' => $app->user_id],
                [
                    'nim' => $app->nim,
                    'fakultas' => $app->faculty,
                    'program_studi' => $app->study_program,
                    'tempat_lahir' => $app->pob,
                    'tanggal_lahir' => $app->dob,
                    'jenis_kelamin' => $app->gender === 'Laki-laki' ? 'L' : 'P',
                    'alamat_asal' => $app->origin_address,
                    'alamat_domisili' => $app->jogja_address,
                    'tanda_tangan_path' => $app->signature_path,
                    'updated_at' => now(),
                ]
            );

            $profile = DB::table('mahasiswa_profiles')->where('user_id', $app->user_id)->first();

            // Family: Ayah
            if ($app->father_name) {
                DB::table('keluarga_mahasiswas')->updateOrInsert(
                    ['mahasiswa_profile_id' => $profile->id, 'jenis_relasi' => 'ayah'],
                    [
                        'nama_lengkap' => $app->father_name,
                        'pekerjaan' => $app->father_job,
                        'penghasilan' => $app->father_income,
                        'status_hidup' => strtolower($app->father_status ?? 'hidup'),
                        'tanggal_meninggal' => $app->father_death_date,
                        'updated_at' => now(),
                    ]
                );
            }

            // Family: Ibu
            if ($app->mother_name) {
                DB::table('keluarga_mahasiswas')->updateOrInsert(
                    ['mahasiswa_profile_id' => $profile->id, 'jenis_relasi' => 'ibu'],
                    [
                        'nama_lengkap' => $app->mother_name,
                        'pekerjaan' => $app->mother_job,
                        'penghasilan' => $app->mother_income,
                        'status_hidup' => strtolower($app->mother_status ?? 'hidup'),
                        'tanggal_meninggal' => $app->mother_death_date,
                        'updated_at' => now(),
                    ]
                );
            }

            // Family: Wali
            if ($app->guardian_name) {
                DB::table('keluarga_mahasiswas')->updateOrInsert(
                    ['mahasiswa_profile_id' => $profile->id, 'jenis_relasi' => 'wali'],
                    [
                        'nama_lengkap' => $app->guardian_name,
                        'pekerjaan' => $app->guardian_job,
                        'penghasilan' => $app->guardian_income,
                        'status_hidup' => strtolower($app->guardian_status ?? 'hidup'),
                        'tanggal_meninggal' => $app->guardian_death_date,
                        'updated_at' => now(),
                    ]
                );
            }

            // Siblings
            $siblings = json_decode($app->siblings ?? '[]', true);
            if (is_array($siblings)) {
                foreach ($siblings as $sib) {
                    DB::table('keluarga_mahasiswas')->insert([
                        'mahasiswa_profile_id' => $profile->id,
                        'jenis_relasi' => 'saudara',
                        'nama_lengkap' => $sib['name'],
                        'pekerjaan' => $sib['job_or_school'],
                        'status_hidup' => 'hidup',
                        'status_kawin' => $sib['marital_status'] ?? null,
                        'keterangan' => $sib['relation'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Link application to profile
            DB::table('scholarship_applications')->where('id', $app->id)->update(['mahasiswa_profile_id' => $profile->id]);
        }

        // 3. Drop redundant columns
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->dropColumn([
                'nim',
                'faculty',
                'study_program',
                'pob',
                'dob',
                'gender',
                'origin_address',
                'jogja_address',
                'signature_path',
                'father_name',
                'father_job',
                'father_income',
                'father_status',
                'father_death_date',
                'mother_name',
                'mother_job',
                'mother_income',
                'mother_status',
                'mother_death_date',
                'guardian_name',
                'guardian_job',
                'guardian_income',
                'guardian_status',
                'guardian_death_date',
                'siblings'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-adding columns (Reverse logic omitted for brevity in common cases, but required for full safety)
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->string('nim')->nullable();
            $table->string('faculty')->nullable();
            $table->string('study_program')->nullable();
            $table->string('pob')->nullable();
            $table->date('dob')->nullable();
            $table->string('gender')->nullable();
            $table->text('origin_address')->nullable();
            $table->text('jogja_address')->nullable();
            $table->string('signature_path')->nullable();

            $table->string('father_name')->nullable();
            $table->string('father_job')->nullable();
            $table->decimal('father_income', 15, 2)->nullable();
            $table->string('father_status')->nullable();
            $table->date('father_death_date')->nullable();

            $table->string('mother_name')->nullable();
            $table->string('mother_job')->nullable();
            $table->decimal('mother_income', 15, 2)->nullable();
            $table->string('mother_status')->nullable();
            $table->date('mother_death_date')->nullable();

            $table->string('guardian_name')->nullable();
            $table->string('guardian_job')->nullable();
            $table->decimal('guardian_income', 15, 2)->nullable();
            $table->string('guardian_status')->nullable();
            $table->date('guardian_death_date')->nullable();

            $table->json('siblings')->nullable();

            $table->dropConstrainedForeignId('mahasiswa_profile_id');
        });
    }
};
