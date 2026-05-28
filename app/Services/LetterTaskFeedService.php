<?php

namespace App\Services;

use App\Models\ScholarshipApplication;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Support\LetterTypeRegistry;
use Illuminate\Database\Eloquent\Model;
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

    public function orderedTendikRows($tasks): Collection
    {
        return collect($tasks)
            ->map(fn (Model $task): array => $this->withoutSortFields($this->tendikRowForModel($task)))
            ->values();
    }

    public function orderedAkademikRows($tasks): Collection
    {
        return collect($tasks)
            ->map(fn (Model $task): array => $this->withoutSortFields($this->akademikRowForModel($task)))
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

        return array_merge([
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
            'docx_url' => null,
            '_sort_at' => $task->submitted_at ?? $task->created_at,
        ], $this->actorFields($task));
    }

    public function tendikMagangRow(SuratPengantarMagangApplication $task): array
    {
        $metadata = $this->metadataFor(SuratPengantarMagangApplication::LETTER_TYPE);

        return array_merge([
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
        ], $this->actorFields($task));
    }

    public function tendikAktifRow(SuratKeteranganAktifApplication $task): array
    {
        $metadata = $this->metadataFor(SuratKeteranganAktifApplication::LETTER_TYPE);

        return array_merge([
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
        ], $this->actorFields($task));
    }

    public function tendikProsesLuarNegeriRow(ProsesLuarNegeriApplication $task): array
    {
        $metadata = $this->metadataFor(ProsesLuarNegeriApplication::LETTER_TYPE);

        return array_merge([
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
        ], $this->actorFields($task));
    }

    /**
     * Additive actor metadata for Tendik feed rows. All keys are nullable and
     * back-fill with null on rows that pre-date the actor migration. The FE
     * uses these to populate the "Riwayat Pengajuan" timeline with names
     * (assigned tendik, verifier, reviser, rejector) and to surface nomor_surat
     * in Riwayat tables.
     */
    private function actorFields(Model $task): array
    {
        return [
            'assigned_to' => $task->getAttribute('assigned_to'),
            'assigned_tendik_name' => $this->relationName($task, 'assignedTendik'),
            'nomor_surat' => $task->getAttribute('nomor_surat'),
            'tendik_approved_by' => $task->getAttribute('tendik_approved_by'),
            'tendik_approved_by_name' => $this->relationName($task, 'tendikApprover'),
            'revised_by' => $task->getAttribute('revised_by'),
            'revised_by_name' => $this->relationName($task, 'reviser'),
            'rejected_by' => $task->getAttribute('rejected_by'),
            'rejected_by_name' => $this->relationName($task, 'rejector'),
        ];
    }

    private function relationName(Model $task, string $relation): ?string
    {
        if (!$task->relationLoaded($relation)) {
            return null;
        }
        $related = $task->getRelation($relation);
        return $related?->getAttribute('name');
    }

    public function akademikScholarshipRow(ScholarshipApplication $task): array
    {
        $metadata = $this->metadataFor(ScholarshipApplication::LETTER_TYPE);

        return array_merge([
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? $task->user?->name,
            'nim' => $task->mahasiswaProfile?->nim,
            'type' => $task->scholarship_name ?? 'Beasiswa',
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'status' => $task->status,
            'docx_url' => null,
            'action_at' => $this->formatNullableDate($this->actionTimestamp($task)),
            'is_overdue' => $task->created_at->diffInHours(now()) > 24,
            'sort_timestamp' => $task->created_at->timestamp,
        ], $this->actorFields($task), $this->academicActorFields($task));
    }

    public function akademikMagangRow(SuratPengantarMagangApplication $task): array
    {
        $metadata = $this->metadataFor(SuratPengantarMagangApplication::LETTER_TYPE);

        return array_merge([
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? $task->user?->name,
            'nim' => $task->mahasiswaProfile?->nim,
            'type' => $metadata['letter_label'],
            'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'status' => $task->status,
            'docx_url' => null,
            'action_at' => $this->formatNullableDate($this->actionTimestamp($task)),
            'is_overdue' => $task->created_at->diffInHours(now()) > 24,
            'sort_timestamp' => $task->created_at->timestamp,
        ], $this->actorFields($task), $this->academicActorFields($task));
    }

    public function akademikAktifRow(SuratKeteranganAktifApplication $task): array
    {
        $metadata = $this->metadataFor(SuratKeteranganAktifApplication::LETTER_TYPE);

        return array_merge([
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? $task->user?->name,
            'nim' => $task->mahasiswaProfile?->nim,
            'type' => $metadata['letter_label'],
            'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'status' => $task->status,
            'docx_url' => null,
            'action_at' => $this->formatNullableDate($this->actionTimestamp($task)),
            'is_overdue' => $task->created_at->diffInHours(now()) > 24,
            'sort_timestamp' => $task->created_at->timestamp,
        ], $this->actorFields($task), $this->academicActorFields($task));
    }

    public function akademikProsesLuarNegeriRow(ProsesLuarNegeriApplication $task): array
    {
        $metadata = $this->metadataFor(ProsesLuarNegeriApplication::LETTER_TYPE);

        return array_merge([
            'id' => $task->id,
            'submitted_at' => $this->formatDate($task->created_at),
            'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? $task->user?->name,
            'nim' => $task->mahasiswaProfile?->nim,
            'type' => $metadata['letter_label'],
            'letter_type' => ProsesLuarNegeriApplication::LETTER_TYPE,
            'letter_label' => $metadata['letter_label'],
            'category' => $metadata['category'],
            'status' => $task->status,
            'docx_url' => null,
            'action_at' => $this->formatNullableDate($this->actionTimestamp($task)),
            'is_overdue' => $task->created_at->diffInHours(now()) > 24,
            'sort_timestamp' => $task->created_at->timestamp,
        ], $this->actorFields($task), $this->academicActorFields($task));
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

    private function formatNullableDate($value): ?string
    {
        return $value ? $this->formatDate($value) : null;
    }

    private function academicActorFields(Model $task): array
    {
        return [
            'kaprodi_approved_by' => $task->getAttribute('kaprodi_approved_by'),
            'kaprodi_approved_by_name' => $this->relationName($task, 'kaprodiApprover'),
            'kadep_approved_by' => $task->getAttribute('kadep_approved_by'),
            'kadep_approved_by_name' => $this->relationName($task, 'kadepApprover'),
        ];
    }

    private function actionTimestamp(Model $task)
    {
        foreach ([
            'completed_at',
            'student_reviewed_at',
            'rejected_at',
            'revised_at',
            'kadep_approved_at',
            'kaprodi_approved_at',
            'tendik_approved_at',
            'submitted_at',
            'updated_at',
            'created_at',
        ] as $attribute) {
            $value = $task->getAttribute($attribute);
            if ($value) {
                return $value;
            }
        }

        return null;
    }

    private function tendikRowForModel(Model $task): array
    {
        return match (true) {
            $task instanceof ScholarshipApplication => $this->tendikScholarshipRow($task),
            $task instanceof SuratPengantarMagangApplication => $this->tendikMagangRow($task),
            $task instanceof SuratKeteranganAktifApplication => $this->tendikAktifRow($task),
            $task instanceof ProsesLuarNegeriApplication => $this->tendikProsesLuarNegeriRow($task),
        };
    }

    private function akademikRowForModel(Model $task): array
    {
        return match (true) {
            $task instanceof ScholarshipApplication => $this->akademikScholarshipRow($task),
            $task instanceof SuratPengantarMagangApplication => $this->akademikMagangRow($task),
            $task instanceof SuratKeteranganAktifApplication => $this->akademikAktifRow($task),
            $task instanceof ProsesLuarNegeriApplication => $this->akademikProsesLuarNegeriRow($task),
        };
    }

    private function withoutSortFields(array $task): array
    {
        unset($task['_sort_at'], $task['sort_timestamp']);

        return $task;
    }
}
