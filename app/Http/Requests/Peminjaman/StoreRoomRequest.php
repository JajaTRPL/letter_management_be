<?php

namespace App\Http\Requests\Peminjaman;

use App\Enums\RoomType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100', 'unique:rooms,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(RoomType::class)],
            'capacity' => ['required', 'integer', 'min:1'],
            'location' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'owning_laboratory_id' => ['nullable', 'integer', 'exists:laboratories,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateOwnership($validator, $this->input('type'), $this->input('owning_laboratory_id'));
        });
    }

    protected function validateOwnership($validator, mixed $type, mixed $laboratoryId): void
    {
        if ($type === RoomType::Classroom->value && $laboratoryId !== null) {
            $validator->errors()->add(
                'owning_laboratory_id',
                'Ruang kelas tidak boleh memiliki laboratorium pemilik.',
            );
        }

        if ($type === RoomType::Laboratory->value && $laboratoryId === null) {
            $validator->errors()->add(
                'owning_laboratory_id',
                'Ruang laboratorium wajib memiliki laboratorium pemilik.',
            );
        }
    }
}
