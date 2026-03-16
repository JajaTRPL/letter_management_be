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
        Schema::create('scholarship_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Process 1: Biodata
            $table->string('nim');
            $table->string('faculty');
            $table->string('study_program');

            // Process 2: Personal & Family
            $table->string('pob'); // Tempat lahir
            $table->date('dob'); // Tanggal lahir
            $table->enum('gender', ['Laki-laki', 'Perempuan']);
            $table->text('origin_address');
            $table->text('jogja_address');
            $table->string('signature_path')->nullable();

            $table->string('father_name');
            $table->string('father_job');
            $table->decimal('father_income', 15, 2);
            $table->enum('father_status', ['Hidup', 'Meninggal']);
            $table->date('father_death_date')->nullable();

            $table->string('mother_name');
            $table->string('mother_job');
            $table->decimal('mother_income', 15, 2);
            $table->enum('mother_status', ['Hidup', 'Meninggal']);
            $table->date('mother_death_date')->nullable();

            $table->string('guardian_name')->nullable();
            $table->string('guardian_job')->nullable();
            $table->decimal('guardian_income', 15, 2)->nullable();
            $table->enum('guardian_status', ['Hidup', 'Meninggal'])->nullable();
            $table->date('guardian_death_date')->nullable();

            // Process 3: Academic & History
            $table->string('scholarship_name');
            $table->string('study_level')->default('D4');
            $table->integer('current_semester');
            $table->integer('family_dependents');
            $table->float('gpa_last_2_semesters');
            $table->float('ipk');
            $table->integer('sks_last_2_semesters');
            $table->integer('total_sks_passed');
            $table->enum('on_leave', ['Sudah', 'Belum'])->default('Belum');
            $table->integer('leave_semester')->nullable();
            $table->enum('thesis_status', ['Sudah', 'Belum'])->default('Belum');
            $table->string('exam_plan_month')->nullable(); // Bulan
            $table->string('exam_plan_year')->nullable(); // Tahun

            $table->boolean('has_scholarship_history')->default(false);
            $table->string('history_source')->nullable();
            $table->string('history_period')->nullable();
            $table->decimal('history_amount', 15, 2)->nullable();
            $table->enum('history_status', ['Masih Menerima', 'Tidak'])->nullable();

            $table->string('ktm_path')->nullable();
            $table->enum('status', ['Draft', 'Submitted', 'Approved', 'Rejected'])->default('Draft');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scholarship_applications');
    }
};
