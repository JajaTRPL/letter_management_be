<?php

namespace App\Http\Requests\RoomManagement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdateFacilityTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // manager-only route group; facility types are global master data
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.min' => 'Nama fasilitas minimal 2 karakter.',
            'name.max' => 'Nama fasilitas maksimal 100 karakter.',
        ];
    }

    public function slugValue(): string
    {
        return Str::slug((string) $this->input('name'), '_');
    }
}
