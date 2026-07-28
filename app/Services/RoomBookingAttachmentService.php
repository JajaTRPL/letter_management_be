<?php

namespace App\Services;

use App\Models\RoomBookingAttachment;
use App\Models\RoomBookingAuditLog;
use App\Models\RoomBookingRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RoomBookingAttachmentService
{
    public const INPUT_SURAT_PEMINJAMAN = 'surat_peminjaman_pdf';

    public const DOCUMENT_SURAT_PEMINJAMAN = RoomBookingAttachment::DOCUMENT_SURAT_PEMINJAMAN;

    public const MAX_KB = 5120;

    private const DISK = 'local';

    private const PREFIX = 'room-booking-attachments/surat-peminjaman/';

    private const MIME_TYPE = 'application/pdf';

    private const MAX_FILENAME_LENGTH = 180;

    public function hasSuratPeminjaman(RoomBookingRequest $booking): bool
    {
        return $this->suratPeminjamanAttachment($booking) !== null;
    }

    public function suratPeminjamanAttachment(RoomBookingRequest $booking): ?RoomBookingAttachment
    {
        if ($booking->relationLoaded('suratPeminjamanAttachment')) {
            return $booking->suratPeminjamanAttachment;
        }

        return $booking->suratPeminjamanAttachment()->first();
    }

    /**
     * Canonical identity of a validated initial-submission upload. The raw
     * path and bytes never leave this method; callers persist only the hash of
     * the complete request identity in the idempotency record.
     *
     * @return array{checksum_sha256: string, size_bytes: int, mime_type: string}
     */
    public function canonicalUploadIdentity(UploadedFile $file): array
    {
        $this->validatePdf($file);

        $path = $file->getRealPath();
        $size = $file->getSize();
        if (! is_string($path) || $path === '' || $size === false) {
            throw new RuntimeException('Failed to read room booking upload identity.');
        }

        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum) || strlen($checksum) !== 64) {
            throw new RuntimeException('Failed to checksum room booking upload.');
        }

        return [
            'checksum_sha256' => $checksum,
            'size_bytes' => (int) $size,
            'mime_type' => self::MIME_TYPE,
        ];
    }

    /**
     * @return array{
     *     exists: bool,
     *     has_surat_peminjaman_pdf: bool,
     *     original_name: ?string,
     *     size_bytes: ?int,
     *     uploaded_at: ?string,
     *     preview_url: ?string,
     *     download_url: ?string
     * }
     */
    public function publicMetadata(RoomBookingRequest $booking): array
    {
        $attachment = $this->suratPeminjamanAttachment($booking);
        $exists = $attachment !== null;

        return [
            'exists' => $exists,
            'has_surat_peminjaman_pdf' => $exists,
            'original_name' => $attachment?->original_name,
            'size_bytes' => $attachment?->size_bytes,
            'uploaded_at' => $attachment?->created_at?->toIso8601String(),
            'preview_url' => $exists
                ? "/api/peminjaman-ruangan/{$booking->id}/attachment/surat-peminjaman/preview"
                : null,
            'download_url' => $exists
                ? "/api/peminjaman-ruangan/{$booking->id}/attachment/surat-peminjaman/download"
                : null,
        ];
    }

    /**
     * @param  callable(RoomBookingRequest): void|null  $lockedGuard
     *         Runs inside the transaction against the LOCKED booking, before
     *         any metadata is written. Callers use it to reauthorize
     *         ownership/status/pending-cancellation state authoritatively;
     *         domain exceptions it throws propagate unwrapped (after the new
     *         file is cleaned up).
     */
    public function storeSuratPeminjaman(
        RoomBookingRequest $booking,
        UploadedFile $file,
        User $actor,
        string $action,
        ?Request $request = null,
        ?callable $lockedGuard = null,
    ): RoomBookingAttachment {
        if (! in_array($action, ['upload', 'replacement'], true)) {
            throw new RuntimeException('Unsupported room booking attachment audit action.');
        }

        $this->validatePdf($file);

        $newPath = $this->writePrivateFile($booking, $file);

        try {
            $metadata = $this->metadata($file, $newPath);
        } catch (Throwable $exception) {
            $this->safeDelete($newPath);
            throw new RuntimeException('Failed to read uploaded room booking attachment metadata.', 0, $exception);
        }

        try {
            [$attachment, $replacedPath] = DB::transaction(function () use (
                $booking,
                $actor,
                $action,
                $metadata,
                $newPath,
                $request,
                $lockedGuard,
            ) {
                $lockedBooking = RoomBookingRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($booking->id);

                if ($lockedGuard !== null) {
                    $lockedGuard($lockedBooking);
                }

                $existing = RoomBookingAttachment::query()
                    ->where('room_booking_request_id', $lockedBooking->id)
                    ->where('document_type', self::DOCUMENT_SURAT_PEMINJAMAN)
                    ->lockForUpdate()
                    ->first();

                $replacedPath = $existing?->storage_disk === self::DISK
                    ? $existing->storage_path
                    : null;

                $attachment = RoomBookingAttachment::query()->updateOrCreate(
                    [
                        'room_booking_request_id' => $lockedBooking->id,
                        'document_type' => self::DOCUMENT_SURAT_PEMINJAMAN,
                    ],
                    [
                        'original_name' => $metadata['original_name'],
                        'mime_type' => self::MIME_TYPE,
                        'size_bytes' => $metadata['size_bytes'],
                        'storage_disk' => self::DISK,
                        'storage_path' => $newPath,
                        'checksum_sha256' => $metadata['checksum_sha256'],
                        'uploaded_by' => $actor->id,
                    ],
                );

                RoomBookingAuditLog::create([
                    'room_booking_request_id' => $lockedBooking->id,
                    'room_booking_attachment_id' => $attachment->id,
                    'actor_id' => $actor->id,
                    'action' => $action,
                    'document_type' => self::DOCUMENT_SURAT_PEMINJAMAN,
                    'original_name' => $metadata['original_name'],
                    'size_bytes' => $metadata['size_bytes'],
                    'checksum_sha256' => $metadata['checksum_sha256'],
                    'storage_path_hash' => hash('sha256', $newPath),
                    'ip_address' => $request?->ip(),
                    'user_agent' => $request?->userAgent(),
                ]);

                return [$attachment->fresh(), $replacedPath];
            });
        } catch (RoomBookingDomainException $exception) {
            // Locked-guard denials keep their domain semantics (409/403);
            // the newly written file never survives a refused replacement.
            $this->safeDelete($newPath);
            throw $exception;
        } catch (Throwable $exception) {
            $this->safeDelete($newPath);
            throw new RuntimeException('Failed to persist room booking attachment metadata.', 0, $exception);
        }

        // The previous valid attachment file is deleted only AFTER the
        // replacement transaction committed successfully.
        if ($replacedPath && $replacedPath !== $newPath) {
            $this->safeDelete($replacedPath);
        }

        return $attachment;
    }

    /**
     * Compensating cleanup for a failed authoritative submission: when the
     * surrounding database transaction rolls back AFTER the physical file was
     * written, the metadata row disappears but the file would survive. This
     * removes exactly that newly written file. Path/disk/prefix validation is
     * the same safeDelete/isManagedPath rule set used everywhere else, so an
     * arbitrary or user-controlled path can never be deleted. A cleanup
     * failure is reported internally and never masks the original business
     * exception (callers rethrow it).
     */
    public function cleanupFailedPersistedAttachment(RoomBookingAttachment $attachment): void
    {
        try {
            if ($attachment->storage_disk !== self::DISK) {
                return;
            }

            $this->safeDelete((string) $attachment->storage_path);
        } catch (Throwable $exception) {
            report(new RuntimeException(
                "Failed to clean up room booking attachment file after rollback (attachment {$attachment->id}).",
                0,
                $exception,
            ));
        }
    }

    public function previewResponse(RoomBookingAttachment $attachment): StreamedResponse
    {
        $this->assertReadable($attachment);

        return response()->stream(
            function () use ($attachment): void {
                $this->streamAttachment($attachment);
            },
            200,
            [
                'Content-Type' => self::MIME_TYPE,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function downloadResponse(RoomBookingAttachment $attachment): StreamedResponse
    {
        $this->assertReadable($attachment);
        $filename = $this->downloadFilename($attachment);

        return response()->streamDownload(
            function () use ($attachment): void {
                $this->streamAttachment($attachment);
            },
            $filename,
            [
                'Content-Type' => self::MIME_TYPE,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function validatePdf(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::INVALID_ATTACHMENT,
                'Berkas surat peminjaman tidak valid.',
            );
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $guessedMime = $file->getMimeType();

        if ($extension !== 'pdf' || $guessedMime !== self::MIME_TYPE) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::INVALID_ATTACHMENT,
                'Surat peminjaman harus berupa berkas PDF.',
            );
        }

        if ($file->getSize() !== false && $file->getSize() > self::MAX_KB * 1024) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::INVALID_ATTACHMENT,
                'Ukuran surat peminjaman melebihi batas yang diizinkan.',
            );
        }
    }

    private function writePrivateFile(RoomBookingRequest $booking, UploadedFile $file): string
    {
        $directory = self::PREFIX.$booking->id;
        $filename = (string) Str::uuid().'.pdf';
        $path = $file->storeAs($directory, $filename, self::DISK);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Failed to store surat peminjaman on private storage.');
        }

        return str_replace('\\', '/', $path);
    }

    /**
     * @return array{original_name: string, size_bytes: int, checksum_sha256: string}
     */
    private function metadata(UploadedFile $file, string $path): array
    {
        $contents = Storage::disk(self::DISK)->get($path);
        if (! is_string($contents)) {
            throw new RuntimeException('Stored surat peminjaman could not be read for checksum.');
        }

        return [
            'original_name' => $this->sanitizeFilename($file->getClientOriginalName()),
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
        ];
    }

    private function sanitizeFilename(?string $filename): string
    {
        $candidate = str_replace('\\', '/', (string) $filename);
        $candidate = basename($candidate);
        $candidate = preg_replace('/[\x00-\x1F\x7F]/u', '', $candidate) ?? '';
        $candidate = str_replace(["\r", "\n"], '', $candidate);
        $candidate = preg_replace('/[^A-Za-z0-9._ -]/', '_', $candidate) ?? '';
        $candidate = trim($candidate, " .\t\n\r\0\x0B");

        if ($candidate === '' || ! str_ends_with(strtolower($candidate), '.pdf')) {
            $candidate = 'surat-peminjaman.pdf';
        }

        if (strlen($candidate) > self::MAX_FILENAME_LENGTH) {
            $extension = '.pdf';
            $stem = substr($candidate, 0, self::MAX_FILENAME_LENGTH - strlen($extension));
            $candidate = rtrim($stem, " ._\t\n\r\0\x0B").$extension;
        }

        return $candidate;
    }

    private function downloadFilename(RoomBookingAttachment $attachment): string
    {
        $filename = $this->sanitizeFilename($attachment->original_name);

        return $filename !== 'surat-peminjaman.pdf'
            ? $filename
            : "surat-peminjaman-{$attachment->room_booking_request_id}.pdf";
    }

    private function assertReadable(RoomBookingAttachment $attachment): void
    {
        abort_unless($attachment->storage_disk === self::DISK, 404);
        abort_unless($this->isManagedPath($attachment->storage_path), 404);
        abort_unless(Storage::disk(self::DISK)->exists($attachment->storage_path), 404);
    }

    private function streamAttachment(RoomBookingAttachment $attachment): void
    {
        $stream = Storage::disk(self::DISK)->readStream($attachment->storage_path);
        if (! is_resource($stream)) {
            return;
        }

        try {
            fpassthru($stream);
        } finally {
            fclose($stream);
        }
    }

    private function safeDelete(string $path): void
    {
        if (! $this->isManagedPath($path)) {
            return;
        }

        if (Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function isManagedPath(?string $path): bool
    {
        $normalized = str_replace('\\', '/', trim((string) $path));

        return $normalized !== ''
            && ! str_contains($normalized, '../')
            && ! str_contains($normalized, '/..')
            && str_starts_with($normalized, self::PREFIX);
    }
}
