<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Enums\UserStatus;
use App\Models\Room;
use App\Models\RoomBookingAttachment;
use App\Models\RoomBookingRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

abstract class RoomBookingApiTestCase extends TestCase
{
    use RefreshDatabase;
    use RoomBookingTestHelpers;
    use WorkflowTestHelpers;

    protected const VALID_PDF_BYTES = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(
            '2026-06-18 09:00:00',
            config('app.timezone'),
        ));
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function student(array $attributes = []): User
    {
        [$student] = $this->completeMahasiswa($attributes);

        return $student;
    }

    protected function superAdmin(array $attributes = []): User
    {
        return $this->bookingUser(array_merge([
            'role' => 'super_admin',
            'role_level' => 'primary',
        ], $attributes));
    }

    protected function persuratan(array $attributes = []): User
    {
        return $this->bookingUser(array_merge([
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'status' => UserStatus::Active,
        ], $attributes));
    }

    protected function actingAsUser(User $user): void
    {
        Sanctum::actingAs($user);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validBookingPayload(Room $room, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $room->id,
            'activity_name' => 'API Contract Test Activity',
            'purpose' => 'API contract test purpose.',
            'participant_count' => min(10, $room->capacity),
            'start_at' => '2026-06-20T10:00:00+07:00',
            'end_at' => '2026-06-20T12:00:00+07:00',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validBookingPayloadWithPdf(Room $room, array $overrides = []): array
    {
        return array_merge(
            ['idempotency_key' => 'initial-submit-'.Str::uuid()],
            $this->validBookingPayload($room, $overrides),
            ['surat_peminjaman_pdf' => $this->validPdfUpload()]
        );
    }

    protected function validPdfUpload(string $name = 'surat-peminjaman.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::VALID_PDF_BYTES);
    }

    protected function createSuratPeminjamanAttachment(
        RoomBookingRequest $booking,
        ?User $uploader = null,
        string $originalName = 'existing-surat-peminjaman.pdf',
        string $body = self::VALID_PDF_BYTES,
    ): RoomBookingAttachment {
        $path = 'room-booking-attachments/surat-peminjaman/'
            .$booking->id.'/'.strtolower(str_replace(' ', '-', $originalName));
        Storage::disk('local')->put($path, $body);

        return RoomBookingAttachment::create([
            'room_booking_request_id' => $booking->id,
            'document_type' => RoomBookingAttachment::DOCUMENT_SURAT_PEMINJAMAN,
            'original_name' => $originalName,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($body),
            'storage_disk' => 'local',
            'storage_path' => $path,
            'checksum_sha256' => hash('sha256', $body),
            'uploaded_by' => $uploader?->id ?? $booking->requester_id,
        ]);
    }

    protected function mahasiswaUrl(string $suffix = ''): string
    {
        return '/api/mahasiswa/peminjaman-ruangan'.$suffix;
    }

    protected function reviewerUrl(string $suffix = ''): string
    {
        return '/api/tendik/peminjaman-ruangan/requests'.$suffix;
    }

    protected function reviewerCalendarUrl(string $suffix = ''): string
    {
        return '/api/tendik/peminjaman-ruangan/calendar'.$suffix;
    }

    protected function adminUrl(string $suffix = ''): string
    {
        return '/api/super-admin/peminjaman-ruangan'.$suffix;
    }

    protected function markRevisionRequested(
        Room $room,
        User $requester,
        array $attributes = [],
    ) {
        return $this->roomBooking(
            $room,
            $requester,
            RoomBookingStatus::RevisionRequested,
            attributes: $attributes,
        );
    }
}
