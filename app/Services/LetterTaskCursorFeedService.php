<?php

namespace App\Services;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LetterTaskCursorFeedService
{
    private const DEFAULT_PAGE_SIZE = 25;
    private const MAX_PAGE_SIZE = 100;

    private const MODEL_CLASSES = [
        ScholarshipApplication::LETTER_TYPE => ScholarshipApplication::class,
        SuratPengantarMagangApplication::LETTER_TYPE => SuratPengantarMagangApplication::class,
        SuratKeteranganAktifApplication::LETTER_TYPE => SuratKeteranganAktifApplication::class,
        ProsesLuarNegeriApplication::LETTER_TYPE => ProsesLuarNegeriApplication::class,
        SuratTugasApplication::LETTER_TYPE => SuratTugasApplication::class,
    ];

    private const TENDIK_RELATIONS = [
        'mahasiswaProfile.user',
        'user.studyProgram.department.faculty',
        'user.department.faculty',
    ];

    private const AKADEMIK_RELATIONS = [
        'user.studyProgram.department.faculty',
        'user.department.faculty',
        'mahasiswaProfile',
    ];

    public function __construct(
        private LetterAssignmentService $assignmentService,
        private AcademicRoutingService $academicRoutingService
    ) {
    }

    public function cursorModeRequested(Request $request): bool
    {
        return $request->query->has('cursor') || $request->query->has('page_size');
    }

    public function tendikDashboard(User $user, Request $request): array
    {
        return $this->buildFeed(
            [
                $this->tendikBranch(
                    $user,
                    ScholarshipApplication::LETTER_TYPE,
                    [
                        ScholarshipApplication::STATUS_SUBMITTED,
                    ],
                    true,
                    [ScholarshipApplication::STATUS_SUBMITTED]
                ),
                $this->tendikBranch(
                    $user,
                    SuratPengantarMagangApplication::LETTER_TYPE,
                    [
                        SuratPengantarMagangApplication::STATUS_SUBMITTED,
                    ],
                    true
                ),
                $this->tendikBranch(
                    $user,
                    SuratKeteranganAktifApplication::LETTER_TYPE,
                    [
                        SuratKeteranganAktifApplication::STATUS_SUBMITTED,
                    ],
                    true
                ),
                $this->tendikBranch(
                    $user,
                    ProsesLuarNegeriApplication::LETTER_TYPE,
                    [
                        ProsesLuarNegeriApplication::STATUS_SUBMITTED,
                    ],
                    true
                ),
                $this->tendikBranch(
                    $user,
                    SuratTugasApplication::LETTER_TYPE,
                    [
                        SuratTugasApplication::STATUS_SUBMITTED,
                    ],
                    true
                ),
            ],
            $request,
            self::TENDIK_RELATIONS
        );
    }

    public function tendikRiwayat(User $user, Request $request): array
    {
        return $this->buildFeed(
            [
                $this->tendikBranch(
                    $user,
                    ScholarshipApplication::LETTER_TYPE,
                    $this->historicalStatuses(ScholarshipApplication::class),
                    true
                ),
                $this->tendikBranch(
                    $user,
                    SuratPengantarMagangApplication::LETTER_TYPE,
                    $this->historicalStatuses(SuratPengantarMagangApplication::class),
                    true
                ),
                $this->tendikBranch(
                    $user,
                    SuratKeteranganAktifApplication::LETTER_TYPE,
                    $this->historicalStatuses(SuratKeteranganAktifApplication::class),
                    true
                ),
                $this->tendikBranch(
                    $user,
                    ProsesLuarNegeriApplication::LETTER_TYPE,
                    $this->historicalStatuses(ProsesLuarNegeriApplication::class),
                    true
                ),
                $this->tendikBranch(
                    $user,
                    SuratTugasApplication::LETTER_TYPE,
                    $this->historicalStatuses(SuratTugasApplication::class),
                    true
                ),
            ],
            $request,
            self::TENDIK_RELATIONS
        );
    }

    public function akademikDashboard(User $user, Request $request): array
    {
        $targetStatus = $this->academicTargetStatus($user);
        $scope = $this->academicScope($user);

        return $this->buildFeed(
            collect(array_keys(self::MODEL_CLASSES))
                ->map(fn (string $letterType): QueryBuilder => $this->academicBranch($user, $letterType, $targetStatus, $scope))
                ->all(),
            $request,
            self::AKADEMIK_RELATIONS
        );
    }

    private function buildFeed(array $branches, Request $request, array $relations): array
    {
        [$pageSize, $cursor] = $this->cursorOptions($request);

        $union = array_shift($branches);
        foreach ($branches as $branch) {
            $union->unionAll($branch);
        }

        $query = DB::query()->fromSub($union, 'feed');

        if ($cursor) {
            $this->applyCursorPredicate($query, $cursor);
        }

        $rows = $query
            ->orderByDesc('sort_at')
            ->orderBy('letter_type')
            ->orderByDesc('source_id')
            ->limit($pageSize + 1)
            ->get();

        $hasMore = $rows->count() > $pageSize;
        $pageRows = $rows->take($pageSize)->values();

        return [
            'models' => $this->hydrateRows($pageRows, $relations),
            'meta' => $this->cursorMeta($pageRows, $pageSize, $hasMore),
        ];
    }

    private function tendikBranch(
        User $user,
        string $letterType,
        array $statuses,
        bool $sortBySubmittedAt,
        ?array $unassignedStatuses = null
    ): QueryBuilder {
        return $this->assignmentService->applyFeedVisibilityToQueryBuilder(
            $this->applicationBranch($letterType, $statuses, $sortBySubmittedAt),
            $user,
            $letterType,
            $unassignedStatuses
        );
    }

    private function academicBranch(User $user, string $letterType, ?string $targetStatus, ?string $scope): QueryBuilder
    {
        $query = $this->applicationBranch($letterType, $targetStatus ? [$targetStatus] : [], false);

        return match ($scope) {
            'prodi' => $this->academicRoutingService->applyProdiStageQueryScope($query, $user),
            'department' => $this->academicRoutingService->applyDepartmentStageQueryScope($query, $user),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function applicationBranch(string $letterType, array $statuses, bool $sortBySubmittedAt): QueryBuilder
    {
        $modelClass = self::MODEL_CLASSES[$letterType];
        $table = (new $modelClass())->getTable();
        $sortExpression = $sortBySubmittedAt
            ? "COALESCE({$table}.submitted_at, {$table}.created_at)"
            : "{$table}.created_at";

        $query = DB::table($table)
            ->selectRaw('? as letter_type', [$letterType])
            ->addSelect([
                DB::raw("{$table}.id as source_id"),
                DB::raw("{$sortExpression} as sort_at"),
                DB::raw("{$table}.status as status"),
                DB::raw("{$table}.assigned_to as assigned_to"),
                DB::raw("{$table}.user_id as user_id"),
                DB::raw("{$table}.mahasiswa_profile_id as mahasiswa_profile_id"),
            ]);

        return $statuses === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn("{$table}.status", $statuses);
    }

    private function applyCursorPredicate(QueryBuilder $query, array $cursor): void
    {
        $query->where(function (QueryBuilder $query) use ($cursor) {
            $query->where('sort_at', '<', $cursor['sort_at'])
                ->orWhere(function (QueryBuilder $query) use ($cursor) {
                    $query->where('sort_at', '=', $cursor['sort_at'])
                        ->where('letter_type', '>', $cursor['letter_type']);
                })
                ->orWhere(function (QueryBuilder $query) use ($cursor) {
                    $query->where('sort_at', '=', $cursor['sort_at'])
                        ->where('letter_type', '=', $cursor['letter_type'])
                        ->where('source_id', '<', $cursor['source_id']);
                });
        });
    }

    private function hydrateRows(Collection $rows, array $relations): Collection
    {
        $modelsByType = [];

        foreach ($rows->groupBy('letter_type') as $letterType => $typeRows) {
            $modelClass = self::MODEL_CLASSES[$letterType] ?? null;
            if (!$modelClass) {
                continue;
            }

            $modelsByType[$letterType] = $modelClass::query()
                ->with($relations)
                ->whereIn('id', $typeRows->pluck('source_id')->map(fn ($id): int => (int) $id)->all())
                ->get()
                ->keyBy('id');
        }

        return $rows
            ->map(function ($row) use ($modelsByType): ?Model {
                return $modelsByType[$row->letter_type][(int) $row->source_id] ?? null;
            })
            ->filter()
            ->values();
    }

    private function cursorOptions(Request $request): array
    {
        return [
            $this->pageSize($request),
            $this->cursor($request),
        ];
    }

    private function pageSize(Request $request): int
    {
        if (!$request->query->has('page_size')) {
            return self::DEFAULT_PAGE_SIZE;
        }

        $value = $request->query('page_size');
        if (is_array($value) || $value === '') {
            throw ValidationException::withMessages([
                'page_size' => 'The page size must be an integer.',
            ]);
        }

        $pageSize = filter_var($value, FILTER_VALIDATE_INT);
        if ($pageSize === false) {
            throw ValidationException::withMessages([
                'page_size' => 'The page size must be an integer.',
            ]);
        }

        return max(1, min(self::MAX_PAGE_SIZE, (int) $pageSize));
    }

    private function cursor(Request $request): ?array
    {
        if (!$request->query->has('cursor')) {
            return null;
        }

        $value = $request->query('cursor');
        if (is_array($value) || $value === '') {
            throw ValidationException::withMessages([
                'cursor' => 'The cursor is invalid.',
            ]);
        }

        $decoded = $this->base64UrlDecode((string) $value);
        if ($decoded === false) {
            throw ValidationException::withMessages([
                'cursor' => 'The cursor is invalid.',
            ]);
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload) || !$this->validCursorPayload($payload)) {
            throw ValidationException::withMessages([
                'cursor' => 'The cursor is invalid.',
            ]);
        }

        return [
            'version' => 1,
            'sort_at' => $payload['sort_at'],
            'letter_type' => $payload['letter_type'],
            'source_id' => (int) $payload['source_id'],
        ];
    }

    private function validCursorPayload(array $payload): bool
    {
        return ($payload['version'] ?? null) === 1
            && isset($payload['sort_at'], $payload['letter_type'], $payload['source_id'])
            && is_string($payload['sort_at'])
            && $payload['sort_at'] !== ''
            && is_string($payload['letter_type'])
            && array_key_exists($payload['letter_type'], self::MODEL_CLASSES)
            && filter_var($payload['source_id'], FILTER_VALIDATE_INT) !== false
            && (int) $payload['source_id'] > 0;
    }

    private function cursorMeta(Collection $pageRows, int $pageSize, bool $hasMore): array
    {
        $lastRow = $pageRows->last();

        return [
            'pagination_type' => 'cursor',
            'page_size' => $pageSize,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $lastRow ? $this->encodeCursor($lastRow) : null,
            'sort' => [
                'primary' => 'sort_at',
                'tie_breakers' => ['letter_type', 'source_id'],
            ],
        ];
    }

    private function encodeCursor(object $row): string
    {
        return $this->base64UrlEncode(json_encode([
            'version' => 1,
            'sort_at' => (string) $row->sort_at,
            'letter_type' => (string) $row->letter_type,
            'source_id' => (int) $row->source_id,
        ], JSON_THROW_ON_ERROR));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function historicalStatuses(string $modelClass): array
    {
        return [
            $modelClass::STATUS_APPROVED_TENDIK,
            $modelClass::STATUS_REVISION,
            $modelClass::STATUS_REJECTED,
            $modelClass::STATUS_APPROVED_KAPRODI,
            $modelClass::STATUS_READY_FOR_STUDENT_REVIEW,
            $modelClass::STATUS_COMPLETED,
        ];
    }

    private function academicTargetStatus(User $user): ?string
    {
        return match (true) {
            in_array($user->sub_role, ['kaprodi', 'sekprodi'], true) => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            in_array($user->sub_role, ['kadep', 'sekdep'], true) => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            default => null,
        };
    }

    private function academicScope(User $user): ?string
    {
        return match (true) {
            in_array($user->sub_role, ['kaprodi', 'sekprodi'], true) => 'prodi',
            in_array($user->sub_role, ['kadep', 'sekdep'], true) => 'department',
            default => null,
        };
    }
}
