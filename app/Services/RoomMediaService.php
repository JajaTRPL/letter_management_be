<?php

namespace App\Services;

use App\Models\Room;
use App\Models\RoomAuditLog;
use App\Models\RoomPhoto;
use App\Support\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Room photo lifecycle: processed upload (GD variants on the private disk),
 * cover selection, ordering, deletion — every mutation audited and every
 * file written under a server-generated UUID (client filenames are display
 * metadata only, never paths).
 */
class RoomMediaService
{
    public const DISK = 'local';

    public const PATH_PREFIX = 'room-photos/';

    public const MAX_PHOTOS_PER_ROOM = 8;

    private const MAX_ORIGINAL_NAME = 180;

    public function __construct(
        private ImageProcessor $images,
        private RoomAuditService $audit,
    ) {
    }

    /**
     * @throws RuntimeException with a UI-safe Indonesian message.
     */
    public function storePhoto(Room $room, UploadedFile $file, ?\App\Models\User $actor, ?string $ip = null): RoomPhoto
    {
        if ($room->photos()->count() >= self::MAX_PHOTOS_PER_ROOM) {
            throw new RuntimeException(
                'Jumlah foto ruangan sudah mencapai batas ' . self::MAX_PHOTOS_PER_ROOM . '. Hapus foto lama terlebih dahulu.'
            );
        }

        $processed = $this->images->process($file->getRealPath());

        $uuid = (string) Str::uuid();
        $directory = self::PATH_PREFIX . $room->id;
        $paths = [];

        // Write files first; if the DB write below fails, clean them up.
        foreach ($processed['variants'] as $variant => $data) {
            $path = "{$directory}/{$uuid}_{$variant}.jpg";
            if (! Storage::disk(self::DISK)->put($path, $data['binary'])) {
                $this->deleteFiles(array_values($paths));
                throw new RuntimeException('Foto tidak dapat disimpan. Silakan coba lagi.');
            }
            $paths[$variant] = $path;
        }

        try {
            return DB::transaction(function () use ($room, $file, $actor, $ip, $processed, $paths) {
                // Serialize concurrent uploads per room via the room row —
                // pgsql forbids FOR UPDATE combined with aggregates.
                Room::whereKey($room->id)->lockForUpdate()->value('id');
                $isFirst = $room->photos()->count() === 0;

                $photo = RoomPhoto::create([
                    'room_id' => $room->id,
                    'storage_disk' => self::DISK,
                    'thumb_path' => $paths['thumb'],
                    'display_path' => $paths['display'],
                    'full_path' => $paths['full'] ?? null,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, self::MAX_ORIGINAL_NAME),
                    'mime' => 'image/jpeg',
                    'size_bytes' => $processed['variants']['display']['size_bytes'],
                    'width' => $processed['source_width'],
                    'height' => $processed['source_height'],
                    'checksum_sha256' => $processed['variants']['display']['checksum_sha256'],
                    'is_cover' => $isFirst,
                    'sort_order' => ($room->photos()->max('sort_order') ?? 0) + 1,
                    'uploaded_by' => $actor?->id,
                ]);

                $this->audit->record(
                    $room,
                    RoomAuditLog::SUBJECT_PHOTO,
                    $photo->id,
                    'uploaded',
                    $actor,
                    'Foto ruangan diunggah.' . ($isFirst ? ' Otomatis menjadi foto sampul.' : ''),
                    $ip,
                );

                return $photo;
            });
        } catch (\Throwable $e) {
            $this->deleteFiles(array_values($paths));

            throw $e;
        }
    }

    public function setCover(Room $room, RoomPhoto $photo, ?\App\Models\User $actor, ?string $ip = null): void
    {
        DB::transaction(function () use ($room, $photo, $actor, $ip) {
            $room->photos()->where('is_cover', true)->update(['is_cover' => false]);
            $photo->update(['is_cover' => true]);

            $this->audit->record(
                $room,
                RoomAuditLog::SUBJECT_PHOTO,
                $photo->id,
                'cover_set',
                $actor,
                'Foto sampul ruangan diganti.',
                $ip,
            );
        });
    }

    /** @param list<int> $orderedIds */
    public function reorder(Room $room, array $orderedIds, ?\App\Models\User $actor, ?string $ip = null): void
    {
        $ownedIds = $room->photos()->pluck('id')->all();

        if (array_diff($orderedIds, $ownedIds) !== [] || count($orderedIds) !== count($ownedIds)) {
            throw new RuntimeException('Urutan foto tidak sesuai dengan foto ruangan ini.');
        }

        DB::transaction(function () use ($room, $orderedIds, $actor, $ip) {
            foreach ($orderedIds as $index => $photoId) {
                RoomPhoto::where('id', $photoId)->update(['sort_order' => $index + 1]);
            }

            $this->audit->record(
                $room,
                RoomAuditLog::SUBJECT_PHOTO,
                null,
                'reordered',
                $actor,
                'Urutan foto ruangan diubah.',
                $ip,
            );
        });
    }

    public function deletePhoto(Room $room, RoomPhoto $photo, ?\App\Models\User $actor, ?string $ip = null): void
    {
        $paths = array_filter([
            $photo->thumb_path,
            $photo->display_path,
            $photo->full_path,
        ]);
        $wasCover = (bool) $photo->is_cover;
        $photoId = $photo->id;

        DB::transaction(function () use ($room, $photo, $wasCover, $photoId, $actor, $ip) {
            $photo->delete();

            // Keep the catalog presentable: promote the next photo as cover.
            if ($wasCover) {
                $room->photos()->first()?->update(['is_cover' => true]);
            }

            $this->audit->record(
                $room,
                RoomAuditLog::SUBJECT_PHOTO,
                $photoId,
                'deleted',
                $actor,
                'Foto ruangan dihapus.',
                $ip,
            );
        });

        $this->deleteFiles($paths);
    }

    public function variantResponse(RoomPhoto $photo, string $variant): StreamedResponse
    {
        $path = $photo->pathForVariant($variant);
        abort_unless($path && Storage::disk($photo->storage_disk)->exists($path), 404);

        return Storage::disk($photo->storage_disk)->response($path, null, [
            'Content-Type' => 'image/jpeg',
            // Variants are immutable per row — safe to cache privately.
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => '"' . $photo->checksum_sha256 . '-' . $variant . '"',
            'Content-Disposition' => 'inline',
        ]);
    }

    /** @param list<string> $paths */
    private function deleteFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if ($path && Storage::disk(self::DISK)->exists($path)) {
                Storage::disk(self::DISK)->delete($path);
            }
        }
    }
}
