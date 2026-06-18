<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\LetterDocumentArtifact;
use App\Models\LetterRetentionAction;
use App\Services\LetterRetentionPolicyService;
use App\Services\LetterRetentionService;
use App\Services\SuperAdminRetentionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use RuntimeException;

class RetentionController extends Controller
{
    public function __construct(
        private readonly SuperAdminRetentionService $retentionApi,
        private readonly LetterRetentionPolicyService $policies,
    ) {
    }

    public function overview()
    {
        return response()->json([
            'message' => 'Ikhtisar retensi surat berhasil diambil',
            'data' => $this->retentionApi->overview(),
        ]);
    }

    public function policy()
    {
        return response()->json([
            'message' => 'Kebijakan retensi surat berhasil diambil',
            'data' => $this->retentionApi->policyPayload(),
        ]);
    }

    public function updatePolicy(Request $request)
    {
        $validated = $request->validate($this->policyRules());

        try {
            $this->policies->update($validated, Auth::id());
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'Skema kebijakan retensi belum tersedia.',
            ], 503);
        }

        return response()->json([
            'message' => 'Kebijakan retensi surat berhasil diperbarui',
            'data' => $this->retentionApi->policyPayload(),
        ]);
    }

    public function candidates(Request $request)
    {
        $filters = $request->validate($this->listRules());
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $batch = min(max($page * $perPage, 100), 1000);

        $result = $this->retentionApi->runFromPayload(array_merge($filters, [
            'batch' => $batch,
        ]), false);

        $items = array_slice($result->actions, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'message' => 'Daftar kandidat retensi surat berhasil diambil',
            'data' => array_map(
                fn ($action): array => $this->retentionApi->actionResultPayload($action),
                $items,
            ),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $result->total(),
                'last_page' => max(1, (int) ceil(max(1, $result->total()) / $perPage)),
                'truncated' => $result->total() >= $batch,
            ],
        ]);
    }

    public function archives(Request $request)
    {
        $request->validate([
            'letter_type' => 'sometimes|string|max:64',
            'application_id' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = LetterDocumentArtifact::query()
            ->whereNotNull('archived_at')
            ->orderByDesc('archived_at')
            ->orderByDesc('id');

        if ($request->filled('letter_type')) {
            $query->where('letter_type', $request->query('letter_type'));
        }
        if ($request->filled('application_id')) {
            $query->where('application_id', (int) $request->query('application_id'));
        }

        $paginated = $query->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'message' => 'Daftar arsip PDF final berhasil diambil',
            'data' => array_map(
                fn (LetterDocumentArtifact $artifact): array => $this->retentionApi->archivePayload($artifact),
                $paginated->items(),
            ),
            'meta' => $this->paginationMeta($paginated),
        ]);
    }

    public function actions(Request $request)
    {
        $request->validate([
            'letter_type' => 'sometimes|string|max:64',
            'application_id' => 'sometimes|integer|min:1',
            'category' => ['sometimes', 'string', Rule::in(LetterRetentionService::CATEGORIES)],
            'status' => 'sometimes|string|max:32',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = LetterRetentionAction::query()
            ->orderByDesc('executed_at')
            ->orderByDesc('id');

        foreach (['letter_type', 'category', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }
        if ($request->filled('application_id')) {
            $query->where('application_id', (int) $request->query('application_id'));
        }

        $paginated = $query->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'message' => 'Daftar aksi retensi surat berhasil diambil',
            'data' => array_map(
                fn (LetterRetentionAction $action): array => $this->retentionApi->actionModelPayload($action),
                $paginated->items(),
            ),
            'meta' => $this->paginationMeta($paginated),
        ]);
    }

    public function dryRun(Request $request)
    {
        $validated = $request->validate($this->manualRunRules(requireSubject: false, requireReason: false));
        $result = $this->retentionApi->runFromPayload($validated, false);

        return response()->json([
            'message' => 'Dry-run retensi surat berhasil dijalankan',
            'data' => [
                'schema_ready' => $result->schemaReady,
                'total' => $result->total(),
                'counts_by_status' => $result->countsByStatus(),
                'actions' => array_map(
                    fn ($action): array => $this->retentionApi->actionResultPayload($action),
                    $result->actions,
                ),
            ],
        ]);
    }

    public function execute(Request $request)
    {
        $validated = $request->validate($this->manualRunRules(requireSubject: true, requireReason: true));
        $result = $this->retentionApi->runFromPayload($validated, true, $request->user());
        $action = $result->actions[0] ?? null;

        if (!$action) {
            return response()->json([
                'message' => 'Tidak ada item retensi yang eligible untuk dieksekusi.',
            ], 422);
        }

        $statusCode = in_array($action->status, ['completed', 'already_missing'], true) ? 200 : 409;

        return response()->json([
            'message' => 'Eksekusi retensi surat selesai diproses',
            'data' => $this->retentionApi->actionResultPayload($action),
        ], $statusCode);
    }

    public function restore(Request $request, LetterDocumentArtifact $artifact)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
        ]);

        $action = $this->retentionApi->restoreArchive($artifact, $request->user(), $validated['reason']);
        $statusCode = $action->status === 'completed' ? 200 : 409;

        return response()->json([
            'message' => 'Pemulihan arsip PDF final selesai diproses',
            'data' => $this->retentionApi->actionResultPayload($action),
        ], $statusCode);
    }

    public function purge(Request $request, LetterDocumentArtifact $artifact)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
        ]);

        $result = $this->retentionApi->runFromPayload([
            'category' => LetterRetentionService::CATEGORY_ARCHIVED_FINAL_PDF,
            'letter_type' => $artifact->letter_type,
            'application_id' => (int) $artifact->application_id,
            'subject_type' => 'artifact',
            'subject_id' => (int) $artifact->id,
            'reason' => $validated['reason'],
        ], true, $request->user());
        $action = $result->actions[0] ?? null;

        if (!$action) {
            return response()->json([
                'message' => 'Arsip PDF final belum eligible untuk purge.',
            ], 422);
        }

        $statusCode = in_array($action->status, ['completed', 'already_missing'], true) ? 200 : 409;

        return response()->json([
            'message' => 'Purge arsip PDF final selesai diproses',
            'data' => $this->retentionApi->actionResultPayload($action),
        ], $statusCode);
    }

    /**
     * @return array<string, mixed>
     */
    private function policyRules(): array
    {
        return [
            'supporting_document_retention_days' => 'sometimes|required|integer|min:1|max:3650',
            'intermediate_artifact_retention_days' => 'sometimes|required|integer|min:1|max:3650',
            'final_pdf_active_days' => 'sometimes|required|integer|min:1|max:3650',
            'archive_retention_days' => 'sometimes|required|integer|min:1|max:3650',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listRules(): array
    {
        return [
            'letter_type' => 'sometimes|string|max:64',
            'application_id' => 'sometimes|integer|min:1',
            'category' => ['sometimes', 'string', Rule::in(LetterRetentionService::CATEGORIES)],
            'subject_type' => 'sometimes|string|in:attachment,artifact',
            'subject_id' => 'sometimes|integer|min:1',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manualRunRules(bool $requireSubject, bool $requireReason): array
    {
        return [
            'letter_type' => 'sometimes|string|max:64',
            'application_id' => 'sometimes|integer|min:1',
            'category' => ['required', 'string', Rule::in(LetterRetentionService::CATEGORIES)],
            'subject_type' => [$requireSubject ? 'required' : 'sometimes', 'string', 'in:attachment,artifact'],
            'subject_id' => [$requireSubject ? 'required' : 'sometimes', 'integer', 'min:1'],
            'batch' => 'sometimes|integer|min:1|max:500',
            'reason' => [$requireReason ? 'required' : 'sometimes', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta($paginated): array
    {
        return [
            'current_page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'last_page' => $paginated->lastPage(),
        ];
    }
}
