<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use Illuminate\Support\Facades\Storage;
use Throwable;

class LetterRetentionArchiveService
{
    /**
     * @return array{status: string, error_code: string|null, archive_disk: string|null, archive_path: string|null, checksum_sha256: string|null}
     */
    public function archiveFinalPdf(LetterDocumentArtifact $artifact): array
    {
        $activePath = $this->normalizePath($artifact->pdf_path);
        if (!$activePath || !$this->isArtifactPdfPath($artifact, $activePath)) {
            return $this->archiveResult('blocked', 'invalid_path');
        }

        $local = Storage::disk('local');
        if (!$local->exists($activePath)) {
            return $this->archiveResult('already_missing', null);
        }

        $contents = $local->get($activePath);
        if (!is_string($contents)) {
            return $this->archiveResult('blocked', 'active_read_failed');
        }

        $checksum = hash('sha256', $contents);
        $archiveDisk = (string) config('letter_retention.archive.disk', 'archive');
        $archivePath = sprintf(
            'final-pdfs/%s/%s/%s/v%s-%s.pdf',
            $artifact->letter_type,
            $artifact->application_id,
            $artifact->id,
            $artifact->version,
            substr($checksum, 0, 12),
        );

        try {
            $archive = Storage::disk($archiveDisk);
            if (!$archive->exists($archivePath) && !$archive->put($archivePath, $contents)) {
                return $this->archiveResult('blocked', 'archive_write_failed');
            }

            $archivedContents = $archive->get($archivePath);
            if (!is_string($archivedContents) || hash('sha256', $archivedContents) !== $checksum) {
                return $this->archiveResult('blocked', 'archive_checksum_mismatch');
            }
        } catch (Throwable) {
            return $this->archiveResult('blocked', 'archive_write_failed');
        }

        return [
            'status' => 'completed',
            'error_code' => null,
            'archive_disk' => $archiveDisk,
            'archive_path' => $archivePath,
            'checksum_sha256' => $checksum,
        ];
    }

    /**
     * @return array{status: string, error_code: string|null}
     */
    public function purgeArchivedFinalPdf(LetterDocumentArtifact $artifact): array
    {
        $archiveDisk = $artifact->archive_disk;
        $archivePath = $this->normalizePath($artifact->archive_path);
        if (!is_string($archiveDisk) || $archiveDisk === '' || !$archivePath) {
            return ['status' => 'blocked', 'error_code' => 'archive_metadata_missing'];
        }

        try {
            $archive = Storage::disk($archiveDisk);
            if (!$archive->exists($archivePath)) {
                return ['status' => 'already_missing', 'error_code' => null];
            }

            $contents = $archive->get($archivePath);
            if (!is_string($contents)) {
                return ['status' => 'blocked', 'error_code' => 'archive_read_failed'];
            }

            if (
                is_string($artifact->archive_checksum_sha256)
                && $artifact->archive_checksum_sha256 !== ''
                && hash('sha256', $contents) !== $artifact->archive_checksum_sha256
            ) {
                return ['status' => 'blocked', 'error_code' => 'archive_checksum_mismatch'];
            }

            if (!$archive->delete($archivePath)) {
                return ['status' => 'failed', 'error_code' => 'archive_delete_failed'];
            }
        } catch (Throwable) {
            return ['status' => 'failed', 'error_code' => 'archive_delete_failed'];
        }

        return ['status' => 'completed', 'error_code' => null];
    }

    /**
     * @return array{status: string, error_code: string|null, archive_disk: string|null, archive_path: string|null, checksum_sha256: string|null}
     */
    private function archiveResult(string $status, ?string $errorCode): array
    {
        return [
            'status' => $status,
            'error_code' => $errorCode,
            'archive_disk' => null,
            'archive_path' => null,
            'checksum_sha256' => null,
        ];
    }

    private function normalizePath(?string $stored): ?string
    {
        if (!is_string($stored) || trim($stored) === '') {
            return null;
        }

        $path = str_replace('\\', '/', trim($stored, '/'));
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));

        if (
            $path === ''
            || str_contains($path, "\0")
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            return null;
        }

        return $path;
    }

    private function isArtifactPdfPath(LetterDocumentArtifact $artifact, string $path): bool
    {
        return str_starts_with(
            $path,
            'letter-document-artifacts/' . $artifact->letter_type . '/' . $artifact->application_id . '/',
        ) && str_ends_with(strtolower($path), '.pdf');
    }
}
