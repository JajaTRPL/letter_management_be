<?php

namespace App\Http\Requests\RoomManagement;

use App\Services\RoomPermissionResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreFacilityTypeRequest extends FormRequest
{
    /**
     * The route group admits every Tendik subrole, so the global-dictionary
     * restriction (SuperAdmin + Sarpras/Kepala Lab/Laboran) is enforced here,
     * before validation runs.
     */
    public function authorize(RoomPermissionResolver $resolver): bool
    {
        $user = $this->user();

        return $user !== null && $resolver->canCreateFacilityType($user);
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(
            'Anda tidak memiliki akses untuk menambahkan jenis fasilitas.',
        );
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
