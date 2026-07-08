<?php

namespace App\Http\Requests\Peminjaman;

use App\Enums\RoomType;
use App\Models\Room;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends StoreRoomRequest
{
    public function rules(): array
    {
        /** @var Room $room */
        $room = $this->route('room');

        return [
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('rooms', 'code')->ignore($room),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(RoomType::class)],
            'capacity' => ['required', 'integer', 'min:1'],
            'location' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'rules' => ['nullable', 'string', 'max:5000'],
            'owning_laboratory_id' => ['nullable', 'integer', 'exists:laboratories,id'],
        ];
    }
}
