<?php

namespace App\Http\Requests\RoomManagement;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // resolver-checked in the controller with the Room instance
    }

    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:5120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Foto ruangan wajib diunggah.',
            'photo.mimes' => 'Format foto harus JPG, PNG, atau WebP.',
            'photo.mimetypes' => 'Format foto harus JPG, PNG, atau WebP.',
            'photo.max' => 'Ukuran foto maksimal 5 MB.',
        ];
    }
}
