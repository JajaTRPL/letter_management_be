<?php

namespace App\Http\Controllers\Concerns;

use App\Services\AcademicRoutingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

trait AuthorizesAcademicApplications
{
    protected function authorizeAcademicDetail(Model $application, AcademicRoutingService $routingService): void
    {
        $user = Auth::user();

        // Read access is scope-only: Kaprodi/Sekprodi may view applications
        // from students in the same prodi, Kadep/Sekdep may view applications
        // from students in the same department — regardless of workflow status.
        // Action authorization remains stage-gated via guardAcademicAction.
        if (!$user || !$routingService->canViewDetail($user, $application)) {
            abort(403, 'Tidak berwenang melihat detail pengajuan ini.');
        }
    }

    protected function guardAcademicAction(
        Model $application,
        AcademicRoutingService $routingService,
        string $prodiStatus,
        string $departmentStatus,
        string $prodiInvalidStatusMessage,
        string $departmentInvalidStatusMessage
    ): ?JsonResponse {
        $user = Auth::user();

        if (!$user) {
            abort(403, 'Tidak berwenang memproses pengajuan ini.');
        }

        if ($routingService->isProdiApprover($user)) {
            if ($application->getAttribute('status') !== $prodiStatus) {
                return response()->json(['message' => $prodiInvalidStatusMessage], 422);
            }

            if (!$routingService->canHandleProdiStage($user, $application)) {
                abort(403, 'Tidak berwenang memproses pengajuan ini.');
            }

            return null;
        }

        if ($routingService->isDepartmentApprover($user)) {
            if ($application->getAttribute('status') !== $departmentStatus) {
                return response()->json(['message' => $departmentInvalidStatusMessage], 422);
            }

            if (!$routingService->canHandleDepartmentStage($user, $application)) {
                abort(403, 'Tidak berwenang memproses pengajuan ini.');
            }

            return null;
        }

        abort(403, 'Sub-role akademik tidak dikenali.');
    }
}
