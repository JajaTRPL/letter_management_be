<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreAcademicPeriodRequest;
use App\Http\Requests\SuperAdmin\UpdateAcademicPeriodRequest;
use App\Models\AcademicPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcademicPeriodController extends Controller
{
    public function index(Request $request)
    {
        $query = AcademicPeriod::query();

        if ($request->filled('semester_type')) {
            $query->where('semester_type', $request->input('semester_type'));
        }

        if ($request->has('is_active') && $request->input('is_active') !== null && $request->input('is_active') !== '') {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->input('academic_year'));
        }

        if ($request->filled('year_start')) {
            $query->where('year_start', $request->input('year_start'));
        }

        $periods = $query
            ->orderByDesc('is_active')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'message' => 'Daftar periode akademik berhasil diambil',
            'count'   => $periods->count(),
            'data'    => $periods,
        ]);
    }

    public function store(StoreAcademicPeriodRequest $request)
    {
        $validated = $request->validated();
        $shouldActivate = (bool) ($validated['is_active'] ?? false);

        $period = DB::transaction(function () use ($validated, $shouldActivate) {
            if ($shouldActivate) {
                AcademicPeriod::where('is_active', true)->update(['is_active' => false]);
            }

            return AcademicPeriod::create($validated);
        });

        return response()->json([
            'message' => 'Periode akademik berhasil dibuat',
            'data'    => $period,
        ], 201);
    }

    public function show(AcademicPeriod $academicPeriod)
    {
        return response()->json([
            'message' => 'Detail periode akademik berhasil diambil',
            'data'    => $academicPeriod,
        ]);
    }

    public function update(UpdateAcademicPeriodRequest $request, AcademicPeriod $academicPeriod)
    {
        $validated = $request->validated();
        $willBeActive = array_key_exists('is_active', $validated)
            ? (bool) $validated['is_active']
            : (bool) $academicPeriod->is_active;

        DB::transaction(function () use ($academicPeriod, $validated, $willBeActive) {
            if ($willBeActive) {
                AcademicPeriod::where('is_active', true)
                    ->where('id', '!=', $academicPeriod->id)
                    ->update(['is_active' => false]);
            }

            $academicPeriod->update($validated);
        });

        return response()->json([
            'message' => 'Periode akademik berhasil diperbarui',
            'data'    => $academicPeriod->fresh(),
        ]);
    }

    public function destroy(AcademicPeriod $academicPeriod)
    {
        $academicPeriod->delete();

        return response()->json([
            'message' => 'Periode akademik berhasil dihapus',
        ]);
    }

    public function toggleActive(AcademicPeriod $academicPeriod)
    {
        $newActive = !$academicPeriod->is_active;

        DB::transaction(function () use ($academicPeriod, $newActive) {
            if ($newActive) {
                AcademicPeriod::where('is_active', true)
                    ->where('id', '!=', $academicPeriod->id)
                    ->update(['is_active' => false]);
            }

            $academicPeriod->is_active = $newActive;
            $academicPeriod->save();
        });

        return response()->json([
            'message' => 'Status periode akademik berhasil diubah',
            'data'    => $academicPeriod->fresh(),
        ]);
    }
}
