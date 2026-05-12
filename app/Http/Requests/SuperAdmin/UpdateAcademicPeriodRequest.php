<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\AcademicPeriod;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAcademicPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year'  => ['sometimes', 'string', 'max:20', 'regex:/^\d{4}\/\d{4}$/'],
            'semester_type'  => ['sometimes', 'string', 'in:' . implode(',', AcademicPeriod::SEMESTER_TYPES)],
            // Derived automatically in prepareForValidation() when driving fields are present
            'year_start'     => 'sometimes|integer|min:2000|max:2100',
            'semester_order' => 'sometimes|integer|min:1|max:3',
            'start_date'     => 'sometimes|date',
            'end_date'       => 'sometimes|date',
            'is_active'      => 'nullable|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Auto-derive year_start and semester_order when the user supplies the driving fields
        $academicYear = (string) $this->input('academic_year', '');
        $semesterType = (string) $this->input('semester_type', '');

        if ($this->has('academic_year') && str_contains($academicYear, '/')) {
            $this->merge(['year_start' => (int) explode('/', $academicYear)[0]]);
        }

        if ($this->has('semester_type') && isset(AcademicPeriod::SEMESTER_ORDER_MAP[$semesterType])) {
            $this->merge(['semester_order' => AcademicPeriod::SEMESTER_ORDER_MAP[$semesterType]]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var AcademicPeriod $currentPeriod */
            $currentPeriod = $this->route('academicPeriod');
            $data          = $this->validationData();

            $academicYear = $data['academic_year'] ?? $currentPeriod->academic_year;
            $yearStart    = $data['year_start']    ?? $currentPeriod->year_start;

            $effectiveIsActive = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $currentPeriod->is_active;

            $effectiveStart = $data['start_date'] ?? $currentPeriod->start_date?->toDateString();
            $effectiveEnd   = $data['end_date']   ?? $currentPeriod->end_date?->toDateString();

            // Rule 1: academic_year consistency
            if ($this->has('academic_year') || $this->has('year_start')) {
                $parts = explode('/', (string) $academicYear);
                if (count($parts) === 2) {
                    if ((int) $parts[0] !== (int) $yearStart) {
                        $validator->errors()->add('year_start', 'Tahun mulai harus sesuai dengan segmen pertama pada tahun akademik.');
                    }
                    if ((int) $parts[1] !== (int) $yearStart + 1) {
                        $validator->errors()->add('academic_year', 'Segmen kedua tahun akademik harus berupa tahun_mulai + 1 (contoh: 2025/2026).');
                    }
                }
            }

            // Rule 2: end_date >= start_date
            if ($effectiveStart && $effectiveEnd && $effectiveEnd < $effectiveStart) {
                $validator->errors()->add('end_date', 'Tanggal selesai harus sama dengan atau setelah tanggal mulai.');
            }

            // Single-active-period semantics are enforced atomically in the controller:
            // activating any period deactivates all other periods inside a DB transaction.
            // No overlap rejection — overlap is conceptually moot when only one period can be active.
            unset($effectiveIsActive, $effectiveStart, $effectiveEnd);
        });
    }
}
