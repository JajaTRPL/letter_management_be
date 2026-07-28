<?php

namespace App\Http\Controllers;

use App\Services\Analytics\ReviewPerformanceService;
use App\Services\Analytics\ReviewScopeResolver;
use App\Support\Analytics\ReviewAnalyticsPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A reviewer's view of their OWN stage. One controller serves Tendik and Akademik
 * alike, because the logic is identical — the role middleware on each route group
 * is what differs, and the scope is derived from the authenticated user either
 * way.
 *
 * THE AUTHORISATION MODEL IS THE ABSENCE OF PARAMETERS. This endpoint accepts
 * exactly one input, `period`. There is no `stage`, no `scope`, no `unit_id`, no
 * `study_program_id` — so a Kaprodi cannot request another program's figures,
 * because there is no parameter in which to ask. Horizontal escalation is not
 * defended against here; it is unrepresentable.
 *
 * The response is deliberately narrow: this reviewer's own stage, no peer list,
 * no ranking, no other unit's numbers, and no person's name — not even their own.
 * A reviewer should get something to act on, not a scoreboard position.
 */
class ReviewPerformanceSelfController extends Controller
{
    public function __construct(
        private ReviewPerformanceService $performance,
        private ReviewScopeResolver $scopes,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = $this->scopes->forSelfView($user);

        if (! $scope) {
            // 200, not 403: a Laboran or an unscoped Kepala Lab has done nothing
            // wrong, and their dashboard should quietly omit the card rather than
            // render an error the user cannot act on.
            return response()->json([
                'message' => 'Ringkasan tahap pemeriksaan tidak tersedia untuk akun ini',
                'data' => [
                    'eligible' => false,
                    'reason_label' => $this->scopes->ineligibleReason($user),
                ],
            ]);
        }

        $period = ReviewAnalyticsPeriod::resolve($request->query('period'));
        $data = $this->performance->selfView($scope, $period);

        return response()->json([
            'message' => 'Ringkasan tahap pemeriksaan Anda berhasil diambil',
            'data' => $data ?? ['eligible' => false, 'reason_label' => $this->scopes->ineligibleReason($user)],
        ]);
    }
}
