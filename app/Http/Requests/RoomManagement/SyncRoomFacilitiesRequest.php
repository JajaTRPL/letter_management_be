<?php

namespace App\Http\Requests\RoomManagement;

use App\Models\RoomFacility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncRoomFacilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // resolver-checked in the controller with the Room instance
    }

    public function rules(): array
    {
        return [
            'facilities' => ['present', 'array'],
            'facilities.*.facility_type_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('facility_types', 'id'),
            ],
            'facilities.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'facilities.*.condition' => ['nullable', Rule::in(RoomFacility::CONDITIONS)],
            'facilities.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'facilities.*.facility_type_id.distinct' => 'Fasilitas yang sama tidak boleh dikirim dua kali.',
            'facilities.*.facility_type_id.exists' => 'Jenis fasilitas tidak ditemukan.',
            'facilities.*.condition.in' => 'Kondisi fasilitas harus baik, perlu_perbaikan, atau rusak.',
            'facilities.*.quantity.min' => 'Jumlah fasilitas minimal 1.',
        ];
    }
}
