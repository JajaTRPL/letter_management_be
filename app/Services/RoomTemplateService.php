<?php

namespace App\Services;

use App\Enums\RoomType;
use App\Models\Room;
use App\Models\RoomAuditLog;
use App\Models\RoomDocumentTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloadable room booking templates, versioned per scope (+ optional
 * per-lab override). The scope is always resolved from the room — never
 * trusted from the client. version + checksum stay stable extension points
 * for the future generated-letter flow.
 */
class RoomTemplateService
{
    public const DISK = 'local';

    public const PATH_PREFIX = 'room-templates/';

    public function __construct(
        private RoomAuditService $audit,
    ) {
    }

    /**
     * Upload a new template version for the room's scope and activate it,
     * deactivating sibling active versions transactionally.
     */
    public function upload(Room $room, UploadedFile $file, ?User $actor, ?string $notes = null, ?string $ip = null): RoomDocumentTemplate
    {
        [$scope, $laboratoryId] = $this->resolveScope($room);

        $extension = strtolower($file->getClientOriginalExtension()) === 'docx' ? 'docx' : 'pdf';
        $directory = self::PATH_PREFIX . $scope . '/' . ($laboratoryId ?? 'global');
        $path = $directory . '/' . (string) Str::uuid() . '.' . $extension;

        if (! Storage::disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()))) {
            throw new RuntimeException('Template tidak dapat disimpan. Silakan coba lagi.');
        }

        try {
            return DB::transaction(function () use ($room, $file, $actor, $notes, $ip, $scope, $laboratoryId, $path) {
                // Lock the sibling rows, then aggregate in PHP — pgsql
                // forbids FOR UPDATE combined with aggregate functions.
                $siblings = RoomDocumentTemplate::query()
                    ->where('scope', $scope)
                    ->when(
                        $laboratoryId === null,
                        fn ($query) => $query->whereNull('laboratory_id'),
                        fn ($query) => $query->where('laboratory_id', $laboratoryId),
                    )
                    ->lockForUpdate()
                    ->get();

                $nextVersion = ((int) $siblings->max('version')) + 1;

                $activeIds = $siblings->where('is_active', true)->pluck('id');
                if ($activeIds->isNotEmpty()) {
                    RoomDocumentTemplate::whereIn('id', $activeIds)->update(['is_active' => false]);
                }

                $template = RoomDocumentTemplate::create([
                    'scope' => $scope,
                    'laboratory_id' => $laboratoryId,
                    'storage_disk' => self::DISK,
                    'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 180),
                    'mime' => $file->getMimeType() === RoomDocumentTemplate::MIME_DOCX
                        ? RoomDocumentTemplate::MIME_DOCX
                        : RoomDocumentTemplate::MIME_PDF,
                    'size_bytes' => $file->getSize(),
                    'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
                    'version' => $nextVersion,
                    'is_active' => true,
                    'notes' => $notes,
                    'uploaded_by' => $actor?->id,
                ]);

                $this->audit->record(
                    $room,
                    RoomAuditLog::SUBJECT_TEMPLATE,
                    $template->id,
                    'uploaded',
                    $actor,
                    "Template versi {$nextVersion} diunggah dan diaktifkan.",
                    $ip,
                );

                return $template;
            });
        } catch (\Throwable $e) {
            if (Storage::disk(self::DISK)->exists($path)) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $e;
        }
    }

    public function activate(Room $room, RoomDocumentTemplate $template, ?User $actor, ?string $ip = null): void
    {
        DB::transaction(function () use ($room, $template, $actor, $ip) {
            RoomDocumentTemplate::query()
                ->where('scope', $template->scope)
                ->when(
                    $template->laboratory_id === null,
                    fn ($query) => $query->whereNull('laboratory_id'),
                    fn ($query) => $query->where('laboratory_id', $template->laboratory_id),
                )
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $template->update(['is_active' => true]);

            $this->audit->record(
                $room,
                RoomAuditLog::SUBJECT_TEMPLATE,
                $template->id,
                'activated',
                $actor,
                "Template versi {$template->version} diaktifkan.",
                $ip,
            );
        });
    }

    public function deactivate(Room $room, RoomDocumentTemplate $template, ?User $actor, ?string $ip = null): void
    {
        $template->update(['is_active' => false]);

        $this->audit->record(
            $room,
            RoomAuditLog::SUBJECT_TEMPLATE,
            $template->id,
            'deactivated',
            $actor,
            "Template versi {$template->version} dinonaktifkan.",
            $ip,
        );
    }

    public function downloadResponse(RoomDocumentTemplate $template): StreamedResponse
    {
        abort_unless(Storage::disk($template->storage_disk)->exists($template->path), 404);

        $extension = $template->mime === RoomDocumentTemplate::MIME_DOCX ? 'docx' : 'pdf';
        $labSuffix = $template->laboratory_id ? "_lab{$template->laboratory_id}" : '';
        $filename = "template_peminjaman_{$template->scope}{$labSuffix}_v{$template->version}.{$extension}";

        return Storage::disk($template->storage_disk)->download($template->path, $filename, [
            'Content-Type' => $template->mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Templates a manager sees for a room: every version in the room's
     * scope (+ its lab override chain for laboratory rooms).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, RoomDocumentTemplate>
     */
    public function templatesForRoom(Room $room)
    {
        [$scope, $laboratoryId] = $this->resolveScope($room);

        return RoomDocumentTemplate::query()
            ->where('scope', $scope)
            ->where(function ($query) use ($laboratoryId) {
                $query->whereNull('laboratory_id');
                if ($laboratoryId !== null) {
                    $query->orWhere('laboratory_id', $laboratoryId);
                }
            })
            ->orderByDesc('laboratory_id')
            ->orderByDesc('version')
            ->get();
    }

    /** @return array{0: string, 1: int|null} */
    private function resolveScope(Room $room): array
    {
        return $room->type === RoomType::Laboratory
            ? [RoomDocumentTemplate::SCOPE_LABORATORY, $room->owning_laboratory_id]
            : [RoomDocumentTemplate::SCOPE_CLASSROOM, null];
    }
}
