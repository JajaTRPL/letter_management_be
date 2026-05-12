<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\AcademicPeriod;
use Illuminate\Foundation\Http\FormRequest;

class StoreAcademicPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year'  => ['required', 'string', 'max:20', 'regex:/^\d{4}\/\d{4}$/'],
            'semester_type'  => ['required', 'string', 'in:' . implode(',', AcademicPeriod::SEMESTER_TYPES)],
            // Derived automatically in prepareForValidation() — must be in rules() to appear in validated()
            'year_start'     => 'required|integer|min:2000|max:2100',
            'semester_order' => 'required|integer|min:1|max:3',
            'start_date'     => 'required|date',
            'end_date'       => 'required|date|after_or_equal:start_date',
            'is_active'      => 'nullable|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('is_active')) {
            $this->merge(['is_active' => false]);
        }

        // Auto-derive year_start and semester_order from user-supplied fields
        $academicYear = (string) $this->input('academic_year', '');
        $semesterType = (string) $this->input('semester_type', '');

        if (str_contains($academicYear, '/')) {
            $this->merge(['year_start' => (int) explode('/', $academicYear)[0]]);
        }

        if (isset(AcademicPeriod::SEMESTER_ORDER_MAP[$semesterType])) {
            $this->merge(['semester_order' => AcademicPeriod::SEMESTER_ORDER_MAP[$semesterType]]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $data = $this->validationData();
            $this->validateAcademicYearConsistency($validator, $data);
        });
    }

    private function validateAcademicYearConsistency($validator, array $data): void
    {
        $academicYear = $data['academic_year'] ?? null;
        $yearStart    = $data['year_start']    ?? null;

        if (!$academicYear || !$yearStart) {
            return;
        }

        $parts = explode('/', (string) $academicYear);
        if (count($parts) !== 2) {
            return;
        }

        if ((int) $parts[0] !== (int) $yearStart) {
            $validator->errors()->add('year_start', 'Tahun mulai harus sesuai dengan segmen pertama pada tahun akademik.');
        }

        if ((int) $parts[1] !== (int) $yearStart + 1) {
            $validator->errors()->add('academic_year', 'Segmen kedua tahun akademik harus berupa tahun_mulai + 1 (contoh: 2025/2026).');
        }
    }

}
