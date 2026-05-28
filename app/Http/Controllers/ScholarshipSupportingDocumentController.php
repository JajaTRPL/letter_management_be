<?php

namespace App\Http\Controllers;

use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\AcademicRoutingService;
use App\Services\LetterAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ScholarshipSupportingDocumentController extends Controller
{
    private const FIELDS = [
        'transkrip_nilai' => [
            'attribute' => 'transkrip_nilai_path',
            'prefix' => 'scholarships/transcripts/',
            'filename_base' => 'Transkrip_Nilai',
        ],
        'slip_gaji_ayah' => [
            'attribute' => 'slip_gaji_ayah_path',
            'prefix' => 'scholarships/slips/',
            'filename_base' => 'Slip_Gaji_Ayah',
        ],
        'slip_gaji_ibu' => [
            'attribute' => 'slip_gaji_ibu_path',
            'prefix' => 'scholarships/slips/',
            'filename_base' => 'Slip_Gaji_Ibu',
        ],
    ];

    public function __construct(
        private LetterAssignmentService $assignmentService,
        private AcademicRoutingService $routingService,
    ) {
    }

    public function preview(Request $request, ScholarshipApplication $application, string $field)
    {
        if (!array_key_exists($field, self::FIELDS)) {
            abort(404);
        }

        $user = Auth::user();
        if (!$user || !$this->authorizes($user, $application)) {
            abort(403);
        }

        $config = self::FIELDS[$field];
        $stored = $application->getAttribute($config['attribute']);
        $relative = $this->normalizeStoredPath($stored);
        if (!$relative || !str_starts_with($relative, $config['prefix'])) {
            abort(404);
        }

        if (!Storage::disk('public')->exists($relative)) {
            abort(404);
        }

        return response()->make(Storage::disk('public')->get($relative), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizes(User $user, ScholarshipApplication $application): bool
    {
        return match ($user->role) {
            'super_admin' => true,
            'tendik' => $this->assignmentService->canHandle($user, ScholarshipApplication::LETTER_TYPE),
            'akademik' => $this->routingService->canHandleProdiStage($user, $application)
                || $this->routingService->canHandleDepartmentStage($user, $application),
            'mahasiswa' => (int) $application->user_id === (int) $user->id,
            default => false,
        };
    }

    private function normalizeStoredPath(?string $stored): ?string
    {
        if (!is_string($stored) || $stored === '') {
            return null;
        }

        $path = parse_url($stored, PHP_URL_PATH) ?: $stored;
        $path = str_replace('\\', '/', $path);
        $path = trim($path, '/');

        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'api/storage/')) {
            $path = substr($path, strlen('api/storage/'));
        }

        $path = ltrim($path, '/');

        return $path !== '' ? $path : null;
    }
}
