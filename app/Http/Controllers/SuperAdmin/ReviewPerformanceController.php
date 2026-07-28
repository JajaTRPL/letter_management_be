<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\ReviewPerformanceService;
use App\Support\Analytics\ReviewAnalyticsPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SuperAdmin governance surface for review speed ("Monitoring Kinerja").
 *
 * Reports on STAGES and organisational UNITS. There is no endpoint, parameter, or
 * response field here that identifies an individual reviewer — the service layer
 * cannot express one — so this cannot become an employee scoreboard by a later
 * accident.
 *
 * Every response is render-ready Indonesian: labels, status wording, and the
 * measurement caveats all come from the backend, so the SuperAdmin page and each
 * reviewer's own card literally read the same sentences.
 */
class ReviewPerformanceController extends Controller
{
    public function __construct(private ReviewPerformanceService $performance) {}

    /** Summary across both scopes and every stage. */
    public function index(Request $request): JsonResponse
    {
        $period = ReviewAnalyticsPeriod::resolve($request->query('period'));

        return response()->json([
            'message' => 'Ringkasan kinerja pemeriksaan berhasil diambil',
            'data' => $this->performance->summary($period),
        ]);
    }

    /** Per-unit drill-down for one stage. */
    public function breakdown(Request $request): JsonResponse
    {
        $period = ReviewAnalyticsPeriod::resolve($request->query('period'));
        $data = $this->performance->breakdown(
            (string) $request->query('scope'),
            (string) $request->query('stage'),
            $period,
        );

        if ($data === null) {
            return response()->json(['message' => 'Tahap pemeriksaan tidak dikenali.'], 404);
        }

        return response()->json([
            'message' => 'Rincian per unit berhasil diambil',
            'data' => $data,
        ]);
    }

    /** Time series for one stage, optionally narrowed to a single unit. */
    public function trend(Request $request): JsonResponse
    {
        $period = ReviewAnalyticsPeriod::resolve($request->query('period'));
        $unitId = $request->query('unit_id');

        $data = $this->performance->trend(
            (string) $request->query('scope'),
            (string) $request->query('stage'),
            $period,
            $unitId === null || $unitId === '' ? null : (int) $unitId,
        );

        if ($data === null) {
            return response()->json(['message' => 'Tahap pemeriksaan tidak dikenali.'], 404);
        }

        return response()->json([
            'message' => 'Tren kinerja pemeriksaan berhasil diambil',
            'data' => $data,
        ]);
    }
}
