<?php

namespace App\Http\Requests\RoomManagement;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // resolver-checked in the controller with the Room instance
    }

    public function rules(): array
    {
        // Scope is resolved server-side from the room type — never client-sent.
        return [
            'template' => [
                'required',
                'file',
                'mimes:pdf,docx',
                'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'max:10240',
            ],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'template.required' => 'File template wajib diunggah.',
            'template.mimes' => 'Format template harus PDF atau DOCX.',
            'template.mimetypes' => 'Format template harus PDF atau DOCX.',
            'template.max' => 'Ukuran template maksimal 10 MB.',
        ];
    }
}
