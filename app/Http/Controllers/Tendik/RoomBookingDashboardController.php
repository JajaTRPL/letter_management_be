<?php

namespace App\Http\Controllers\Tendik;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RoomBookingTaskFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard feed for the three room-booking roles.
 *
 * ONE endpoint, role resolved server-side — the same shape as the
 * review-performance self-view, and for the same reason: the request carries no
 * role or scope, so there is nothing for a caller to tamper with, and the
 * dashboard cannot ask for work its user is not allowed to do.
 *
 * This exists because `/api/tendik/dashboard/tasks` is letter-only: its loop is
 * gated by LetterAssignmentService::canHandle(), which rejects any tendik whose
 * sub-role is not `persuratan`. A Kepala Lab with real bookings in their lab was
 * therefore shown 0/0/0 and an empty queue that claimed nothing was assigned to
 * them.
 */
class RoomBookingDashboardController extends Controller
{
    private const ROLE_LABELS = [
        'sarpras' => 'Sarana & Prasarana',
        'kepala_lab' => 'Kepala Laboratorium',
        'laboran' => 'Laboran',
    ];

    public function __construct(private RoomBookingTaskFeedService $feed) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $role = (string) ($user->tendik_role ?? '');

        if (! array_key_exists($role, self::ROLE_LABELS)) {
            // A Persuratan officer has their own letter dashboard; answering with
            // an empty booking feed (rather than 403) keeps the frontend's role
            // switch simple and side-effect free.
            return response()->json([
                'message' => 'Dasbor peminjaman tidak tersedia untuk peran ini',
                'data' => $this->emptyPayload($role),
            ]);
        }

        return response()->json([
            'message' => 'Dasbor peminjaman ruangan berhasil diambil',
            'data' => array_merge(
                [
                    'role' => $role,
                    'role_label' => self::ROLE_LABELS[$role],
                    'scope_label' => $this->scopeLabel($user, $role),
                ],
                $this->feed->dashboardFor($user),
            ),
        ]);
    }

    /** Which slice of the campus this user is responsible for. */
    private function scopeLabel(User $user, string $role): string
    {
        if ($role === 'sarpras') {
            return 'Ruang kelas';
        }

        $laboratory = $user->laboratory;

        return $laboratory
            ? trim(($laboratory->code ? $laboratory->code.' · ' : '').$laboratory->name)
            : 'Laboratorium belum ditetapkan';
    }

    private function emptyPayload(string $role): array
    {
        return [
            'role' => $role,
            'role_label' => '',
            'scope_label' => '',
            'actionable' => [],
            'awareness' => [],
            'today' => [],
            'history' => [],
            'stats' => ['actionable' => 0, 'overdue' => 0, 'finished_this_month' => 0],
        ];
    }
}
