<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLaboratoryRequest extends FormRequest
{
    /**
     * Route is wrapped in `role:super_admin` middleware (routes/api.php), so
     * non-super-admin requests never reach this FormRequest. No further role
     * check is needed here.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', 'unique:laboratories,code'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama laboratorium wajib diisi.',
            'name.max' => 'Nama laboratorium maksimal 255 karakter.',
            'code.required' => 'Kode laboratorium wajib diisi.',
            'code.max' => 'Kode laboratorium maksimal 100 karakter.',
            'code.unique' => 'Kode laboratorium sudah digunakan.',
            'department_id.required' => 'Departemen wajib dipilih.',
            'department_id.exists' => 'Departemen yang dipilih tidak ditemukan.',
        ];
    }
}
