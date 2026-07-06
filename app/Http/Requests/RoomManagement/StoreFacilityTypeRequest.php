<?php

namespace App\Http\Requests\RoomManagement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreFacilityTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // manager-only route group; creation is not room-scoped
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', 'unique:facility_types,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama fasilitas wajib diisi.',
            'name.unique' => 'Fasilitas dengan nama tersebut sudah ada.',
        ];
    }

    public function slugValue(): string
    {
        return Str::slug((string) $this->input('name'), '_');
    }
}
