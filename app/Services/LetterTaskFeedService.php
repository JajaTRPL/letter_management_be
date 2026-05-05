<?php

namespace App\Services;

use App\Models\ScholarshipApplication;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Support\LetterTypeRegistry;
use Illuminate\Support\Collection;

class LetterTaskFeedService
{
    public function combinedTendikRows($scholarshipTasks, $magangTasks, $aktifTasks = null, $prosesLuarNegeriTasks = null): Collection
    {
        return $this->tendikScholarshipRows($scholarshipTasks)
            ->merge($this->tendikMagangRows($magangTasks))
            ->merge($this->tendikAktifRows($aktifTasks ?? collect()))
            ->merge($this->tendikProsesLuarNegeriRows($prosesLuarNegeriTasks ?? collect()))
            ->sortByDesc('_sort_at')
            ->take(100)
            ->map(fn (array $task): array => $this->withoutSortFields($task))
            ->values();
    }

    public function combinedAkademikRows($scholarshipTasks, $magangTasks, $aktifTasks = null, $prosesLuarNegeriTasks = null): Collection
    {
        return $this->akademikScholarshipRows($scholarshipTasks)
            ->merge($this->akademikMagangRows($magangTasks))
            ->merge($this->akademikAktifRows($aktifTasks ?? collect()))
            ->merge($this->akademikProsesLuarNegeriRows($prosesLuarNegeriTasks ?? collect()))
            ->sortByDesc('sort_timestamp')
            ->map(fn (array $task): array => $this->withoutSortFields($task))
            ->values();
    }

    public function tendikScholarshipRows($tasks): Collection
    {
        return collect($tasks)->map(fn (ScholarshipApplication $task): array => $this->tendikScholarshipRow($task));
    }

    public function tendikMagangRows($tasks): Collection
    {
        return collect($tasks)->map(fn (SuratPengantarMagangApplication $task): array => $this->tendikMagangRow($task));
    }

    public function tendikAktifRows($tasks): Collection
    {
        return collect($tasks)->map(fn (SuratKeteranganAktifApplication $task): array => $this->tendikAktifRow($task));
    }

    public function tendikProsesLuarNegeriRows($tasks): Collection
    {
        return collect($tasks)->map(fn (ProsesLuarNegeriApplication $task): array => $this->tendikProsesLuarNegeriRow($task));
    }

    public function akademikScholarshipRows($tasks): Collection
    {
        return collect($tasks)->map(fn (ScholarshipApplication $task): array => $this->akademikScholarshipRow($task));
    }

    public function akademikMagangRows($tasks): Collection
    {
        return collect($tasks)->map(fn (SuratPengantarMagangApplication $task): array => $this->akademikMagangRow($task));
    }

    public function akademikAktifRows($tasks): Collection
    {
        return collect($tasks)->map(fn (SuratKeteranganAktifApplication $task): array => $this->akademikAktifRow($task));
    }

    public function akademikProsesLuarNegeriRows($tasks): Collection
    {
        return collect($tasks)->map(fn (ProsesLuarNegeriApplication $task): array => $this->akademikProsesLuarNegeriRow($task));
    }

    public function tendikScholarshipRow(ScholarshipApplication $task): array
    {
        $metadata = $this->metadataFor(ScholarshipApplication::LETTER_TYPE);

        return [
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->submitted_at ?? $task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? ($task->mahasiswaProfile?->user?->name ?? $task->user?->name ?? '-'),
            'nim' => $task->mahasiswaProfile?->nim ?? '-',
            'type' => $metadata['letter_label'],
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'scholarship_name' => $task->scholarship_name,
            'status' => $task->status === ScholarshipApplication::STATUS_SUBMITTED ? 'Menunggu Verifikasi' : $task->status,
            'is_overdue' => $task->submitted_at && $task->submitted_at->diffInHours(now()) > 24,
            'docx_url' => $task->generated_docx_path ? '/api/storage/' . $task->generated_docx_path : null,
            '_sort_at' => $task->submitted_at ?? $task->created_at,
        ];
    }

    public function tendikMagangRow(SuratPengantarMagangApplication $task): array
    {
        $metadata = $this->metadataFor(SuratPengantarMagangApplication::LETTER_TYPE);

        return [
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->submitted_at ?? $task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? ($task->mahasiswaProfile?->user?->name ?? $task->user?->name ?? '-'),
            'nim' => $task->mahasiswaProfile?->nim ?? '-',
            'type' => $metadata['letter_label'],
            'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'status' => $task->status === SuratPengantarMagangApplication::STATUS_SUBMITTED ? 'Menunggu Verifikasi' : $task->status,
            'is_overdue' => $task->submitted_at && $task->submitted_at->diffInHours(now()) > 24,
            'docx_url' => null,
            '_sort_at' => $task->submitted_at ?? $task->created_at,
        ];
    }

    public function tendikAktifRow(SuratKeteranganAktifApplication $task): array
    {
        $metadata = $this->metadataFor(SuratKeteranganAktifApplication::LETTER_TYPE);

        return [
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->submitted_at ?? $task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? ($task->mahasiswaProfile?->user?->name ?? $task->user?->name ?? '-'),
            'nim' => $task->mahasiswaProfile?->nim ?? '-',
            'type' => $metadata['letter_label'],
            'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'status' => $task->status === SuratKeteranganAktifApplication::STATUS_SUBMITTED ? 'Menunggu Verifikasi' : $task->status,
            'is_overdue' => $task->submitted_at && $task->submitted_at->diffInHours(now()) > 24,
            'docx_url' => null,
            '_sort_at' => $task->submitted_at ?? $task->created_at,
        ];
    }

    public function tendikProsesLuarNegeriRow(ProsesLuarNegeriApplication $task): array
    {
        $metadata = $this->metadataFor(ProsesLuarNegeriApplication::LETTER_TYPE);

        return [
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->submitted_at ?? $task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? ($task->mahasiswaProfile?->user?->name ?? $task->user?->name ?? '-'),
            'nim' => $task->mahasiswaProfile?->nim ?? '-',
            'type' => $metadata['letter_label'],
            'letter_type' => ProsesLuarNegeriApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'status' => $task->status === ProsesLuarNegeriApplication::STATUS_SUBMITTED ? 'Menunggu Verifikasi' : $task->status,
            'is_overdue' => $task->submitted_at && $task->submitted_at->diffInHours(now()) > 24,
            'docx_url' => null,
            '_sort_at' => $task->submitted_at ?? $task->created_at,
        ];
    }

    public function akademikScholarshipRow(ScholarshipApplication $task): array
    {
        $metadata = $this->metadataFor(ScholarshipApplication::LETTER_TYPE);

        return [
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? $task->user?->name,
            'nim' => $task->mahasiswaProfile?->nim,
            'type' => $task->scholarship_name ?? 'Beasiswa',
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'status' => $task->status,
            'docx_url' => $task->generated_docx_path ? '/api/storage/' . $task->generated_docx_path : null,
            'is_overdue' => $task->created_at->diffInHours(now()) > 24,
            'sort_timestamp' => $task->created_at->timestamp,
        ];
    }

    public function akademikMagangRow(SuratPengantarMagangApplication $task): array
    {
        $metadata = $this->metadataFor(SuratPengantarMagangApplication::LETTER_TYPE);

        return [
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? $task->user?->name,
            'nim' => $task->mahasiswaProfile?->nim,
            'type' => $metadata['letter_label'],
            'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'status' => $task->status,
            'docx_url' => $task->generated_pdf_path ? '/api/storage/' . ltrim(str_replace('/storage/', '', $task->generated_pdf_path), '/') : null,
            'is_overdue' => $task->created_at->diffInHours(now()) > 24,
            'sort_timestamp' => $task->created_at->timestamp,
        ];
    }

    public function akademikAktifRow(SuratKeteranganAktifApplication $task): array
    {
        $metadata = $this->metadataFor(SuratKeteranganAktifApplication::LETTER_TYPE);

        return [
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? $task->user?->name,
            'nim' => $task->mahasiswaProfile?->nim,
            'type' => $metadata['letter_label'],
            'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'status' => $task->status,
            'docx_url' => $task->generated_pdf_path ? '/api/storage/' . ltrim(str_replace('/storage/', '', $task->generated_pdf_path), '/') : null,
            'is_overdue' => $task->created_at->diffInHours(now()) > 24,
            'sort_timestamp' => $task->created_at->timestamp,
        ];
    }

    public function akademikProsesLuarNegeriRow(ProsesLuarNegeriApplication $task): array
    {
        $metadata = $this->metadataFor(ProsesLuarNegeriApplication::LETTER_TYPE);

        return [
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? $task->user?->name,
            'nim' => $task->mahasiswaProfile?->nim,
            'type' => $metadata['letter_label'],
            'letter_type' => ProsesLuarNegeriApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'status' => $task->status,
            'docx_url' => $task->generated_pdf_path ? '/api/storage/' . ltrim(str_replace('/storage/', '', $task->generated_pdf_path), '/') : null,
            'is_overdue' => $task->created_at->diffInHours(now()) > 24,
            'sort_timestamp' => $task->created_at->timestamp,
        ];
    }

    private function metadataFor(string $letterType): array
    {
        foreach (LetterTypeRegistry::all() as $type) {
            if (($type['key'] ?? null) === $letterType) {
                return [
                    'letter_label' => $type['label'] ?? $letterType,
                    'category' => $type['category'] ?? null,
                ];
            }
        }

        return [
            'letter_label' => LetterTypeRegistry::labelFor($letterType),
            'category' => null,
        ];
    }

    private function formatDate($value): string
    {
        return $value->format('d M Y, H.i');
    }

    private function withoutSortFields(array $task): array
    {
        unset($task['_sort_at'], $task['sort_timestamp']);

        return $task;
    }
}
