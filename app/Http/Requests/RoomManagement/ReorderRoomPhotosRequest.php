<?php

namespace App\Http\Requests\RoomManagement;

use Illuminate\Foundation\Http\FormRequest;

class ReorderRoomPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // resolver-checked in the controller with the Room instance
    }

    public function rules(): array
    {
        return [
            'photo_ids' => ['required', 'array', 'min:1'],
            'photo_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo_ids.required' => 'Urutan foto wajib dikirim.',
            'photo_ids.*.distinct' => 'Urutan foto tidak boleh memuat foto yang sama dua kali.',
        ];
    }
}
