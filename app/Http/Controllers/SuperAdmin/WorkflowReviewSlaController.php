<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\WorkflowReviewSlaPolicy;
use App\Services\Notifications\WorkflowReviewSlaPolicyService;
use App\Support\DurationHumanizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * SuperAdmin governance surface for the review SLA. Read + update one policy per
 * workflow scope. Every write is validated (bounds + threshold ordering) and
 * audited through the policy service; the response speaks human product language
 * (who acts, when a reminder starts, when it is overdue, when it escalates) so
 * the UI never has to expose scanner/enum/minute internals raw.
 */
class WorkflowReviewSlaController extends Controller
{
    /** Human labels per scope — the product-facing name of each governed workflow. */
    private const SCOPE_LABELS = [
        WorkflowReviewSlaPolicyService::SCOPE_ROOM_BOOKING => 'Peminjaman Ruangan',
        WorkflowReviewSlaPolicyService::SCOPE_LETTER => 'Surat Administrasi',
    ];

    /** Who owns the review action + who receives escalation, per scope. */
    private const SCOPE_RESPONSIBILITY = [
        WorkflowReviewSlaPolicyService::SCOPE_ROOM_BOOKING => [
            'reviewer' => 'Tim Sarpras (ruang kelas) atau Kepala Lab (ruang lab)',
            'escalation' => 'SuperAdmin',
        ],
        WorkflowReviewSlaPolicyService::SCOPE_LETTER => [
            'reviewer' => 'Tendik Persuratan, lalu Kaprodi/Sekprodi, lalu Kadep/Sekdep sesuai tahap pemeriksaan',
            'escalation' => 'SuperAdmin',
        ],
    ];

    public function __construct(private readonly WorkflowReviewSlaPolicyService $policies) {}

    public function show(string $scope): JsonResponse
    {
        $this->assertKnownScope($scope);

        try {
            $model = $this->policies->policyModel($scope);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Pengaturan belum tersedia.'], 503);
        }

        return response()->json([
            'message' => 'Pengaturan batas waktu pemeriksaan berhasil diambil',
            'data' => $this->payload($scope, $model),
        ]);
    }

    public function update(Request $request, string $scope): JsonResponse
    {
        $this->assertKnownScope($scope);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'warning_minutes' => ['required', 'integer', 'min:'.WorkflowReviewSlaPolicyService::MIN_MINUTES, 'max:'.WorkflowReviewSlaPolicyService::MAX_MINUTES],
            'overdue_minutes' => ['required', 'integer', 'min:'.WorkflowReviewSlaPolicyService::MIN_MINUTES, 'max:'.WorkflowReviewSlaPolicyService::MAX_MINUTES],
            'escalation_minutes' => ['required', 'integer', 'min:'.WorkflowReviewSlaPolicyService::MIN_MINUTES, 'max:'.WorkflowReviewSlaPolicyService::MAX_MINUTES],
        ], [], [
            'warning_minutes' => 'waktu mulai diingatkan',
            'overdue_minutes' => 'waktu dianggap terlambat',
            'escalation_minutes' => 'waktu naik ke SuperAdmin',
        ]);

        try {
            $model = $this->policies->update($scope, [
                'enabled' => (bool) $validated['enabled'],
                'warning_minutes' => (int) $validated['warning_minutes'],
                'overdue_minutes' => (int) $validated['overdue_minutes'],
                'escalation_minutes' => (int) $validated['escalation_minutes'],
            ], $request->user());
        } catch (InvalidArgumentException $e) {
            // Ordering / bounds invariant rejected — a human, actionable 422.
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Pengaturan belum tersedia.'], 503);
        }

        return response()->json([
            'message' => 'Pengaturan batas waktu pemeriksaan berhasil disimpan',
            'data' => $this->payload($scope, $model),
        ]);
    }

    private function assertKnownScope(string $scope): void
    {
        if (! in_array($scope, WorkflowReviewSlaPolicyService::scopes(), true)) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function payload(string $scope, WorkflowReviewSlaPolicy $model): array
    {
        $responsibility = self::SCOPE_RESPONSIBILITY[$scope];
        $warning = (int) $model->warning_minutes;
        $overdue = (int) $model->overdue_minutes;
        $escalation = (int) $model->escalation_minutes;

        return [
            'scope' => $scope,
            'scope_label' => self::SCOPE_LABELS[$scope],
            'enabled' => (bool) $model->enabled,
            'thresholds' => [
                'warning_minutes' => $warning,
                'overdue_minutes' => $overdue,
                'escalation_minutes' => $escalation,
            ],
            // Plain-language explanation so the UI needs no minute math, enums, or
            // technical terms (no "SLA"/"eskalasi") in what an awam user reads.
            'explanation' => [
                'subject' => 'Permohonan yang belum diperiksa',
                'reviewer' => $responsibility['reviewer'],
                'escalates_to' => $responsibility['escalation'],
                'warning' => 'Pemeriksa mulai diingatkan setelah menunggu '.$this->humanize($warning).'.',
                'overdue' => 'Dianggap terlambat setelah '.$this->humanize($overdue).'.',
                'escalation' => 'Naik ke '.$responsibility['escalation'].' setelah '.$this->humanize($escalation).'.',
                'effect' => 'Berlaku untuk permohonan baru dan yang masih menunggu pemeriksaan, dihitung sejak waktu pengajuan. Jika dinonaktifkan, sistem berhenti mengirim pengingat pemeriksaan.',
            ],
            'bounds' => [
                'min_minutes' => WorkflowReviewSlaPolicyService::MIN_MINUTES,
                'max_minutes' => WorkflowReviewSlaPolicyService::MAX_MINUTES,
            ],
            'audit' => [
                'updated_by' => $model->updatedBy?->name,
                'updated_at' => $model->updated_at?->toIso8601String(),
                'enabled_updated_by' => $model->enabledUpdatedBy?->name,
                'enabled_at' => $model->enabled_at?->toIso8601String(),
                'disabled_at' => $model->disabled_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Delegated to the shared humanizer so the threshold wording here and the
     * measured wording on the analytics surfaces cannot drift apart.
     */
    private function humanize(int $minutes): string
    {
        return DurationHumanizer::coarse($minutes);
    }
}
