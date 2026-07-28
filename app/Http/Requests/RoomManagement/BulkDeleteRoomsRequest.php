<?php

namespace App\Http\Requests\RoomManagement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a bulk room removal request. Route middleware already gates the
 * surface (super_admin,tendik); per-room authority (canDeactivateRoom) is
 * checked in the controller so an unauthorized room fails the whole batch.
 */
class BulkDeleteRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_ids' => ['required', 'array', 'min:1', 'max:50'],
            'room_ids.*' => ['integer', 'distinct', Rule::exists('rooms', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'room_ids.required' => 'Pilih minimal satu ruangan untuk dihapus.',
            'room_ids.array' => 'Format ruangan yang dipilih tidak valid.',
            'room_ids.max' => 'Maksimal 50 ruangan dapat dihapus sekaligus.',
            'room_ids.*.exists' => 'Salah satu ruangan yang dipilih tidak ditemukan.',
        ];
    }

    /** @return list<int> */
    public function roomIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('room_ids'))));
    }
}
