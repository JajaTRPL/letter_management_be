<?php

namespace Tests\Feature\RoomManagement;

use App\Models\RoomDocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RoomManagement\Concerns\InteractsWithRoomManagement;
use Tests\TestCase;

class RoomTemplateApiTest extends TestCase
{
    use InteractsWithRoomManagement;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpRoomFixtures();
    }

    public function test_upload_versions_and_single_active_invariant(): void
    {
        $this->actingAsSarpras();
        $url = "/api/room-management/rooms/{$this->classroom->id}/templates";

        $v1 = $this->postJson($url, ['template' => $this->pdf(), 'notes' => 'Versi awal.'])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.scope', 'classroom')
            ->json('data.id');

        $v2 = $this->postJson($url, ['template' => $this->pdf()])
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->json('data.id');

        // Uploading v2 deactivated v1.
        $this->assertFalse(RoomDocumentTemplate::findOrFail($v1)->is_active);
        $this->assertTrue(RoomDocumentTemplate::findOrFail($v2)->is_active);

        // Re-activating v1 flips them back.
        $this->postJson("{$url}/{$v1}/activate")->assertOk();
        $this->assertTrue(RoomDocumentTemplate::findOrFail($v1)->fresh()->is_active);
        $this->assertFalse(RoomDocumentTemplate::findOrFail($v2)->fresh()->is_active);

        $this->postJson("{$url}/{$v1}/deactivate")->assertOk();
        $this->assertFalse(RoomDocumentTemplate::findOrFail($v1)->fresh()->is_active);

        foreach (['uploaded', 'activated', 'deactivated'] as $action) {
            $this->assertDatabaseHas('room_audit_logs', [
                'subject_type' => 'template',
                'action' => $action,
            ]);
        }
    }

    public function test_scope_is_resolved_from_room_never_from_client(): void
    {
        $this->actingAsSuperAdmin();

        // Client-sent scope/laboratory fields are ignored: the lab room
        // upload lands in the laboratory scope of its owning lab.
        $this->postJson("/api/room-management/rooms/{$this->labARoom->id}/templates", [
            'template' => $this->pdf(),
            'scope' => 'classroom',
            'laboratory_id' => $this->labB->id,
        ])->assertCreated()
            ->assertJsonPath('data.scope', 'laboratory')
            ->assertJsonPath('data.laboratory_id', $this->labA->id);
    }

    public function test_docx_accepted_and_other_mimes_rejected(): void
    {
        $this->actingAsSarpras();
        $url = "/api/room-management/rooms/{$this->classroom->id}/templates";

        $this->postJson($url, [
            'template' => UploadedFile::fake()->create(
                'template.docx',
                64,
                RoomDocumentTemplate::MIME_DOCX,
            ),
        ])->assertCreated()
            ->assertJsonPath('data.mime', RoomDocumentTemplate::MIME_DOCX);

        $this->postJson($url, [
            'template' => UploadedFile::fake()->create('template.txt', 10, 'text/plain'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('template');
    }

    public function test_download_streams_sanitized_filename_and_no_path_leak(): void
    {
        $this->actingAsSarpras();
        $url = "/api/room-management/rooms/{$this->classroom->id}/templates";

        $templateId = $this->postJson($url, [
            'template' => $this->pdf('nama file <aneh> ..\\..\\template.pdf'),
        ])->json('data.id');

        // List payload never exposes storage internals.
        $listRaw = json_encode($this->getJson($url)->assertOk()->json());
        foreach (['storage_disk', '"path"', 'room-templates/'] as $secret) {
            $this->assertStringNotContainsString($secret, $listRaw);
        }

        $download = $this->get("{$url}/{$templateId}/download")->assertOk();
        $disposition = (string) $download->headers->get('Content-Disposition');
        $this->assertStringContainsString('template_peminjaman_classroom_v1.pdf', $disposition);
        $this->assertStringNotContainsString('aneh', $disposition);
    }

    public function test_template_permission_matrix(): void
    {
        $upload = fn () => ['template' => $this->pdf()];

        $this->actingAsSarpras();
        $this->postJson("/api/room-management/rooms/{$this->labARoom->id}/templates", $upload())->assertNotFound();

        $this->actingAsKalab();
        $this->postJson("/api/room-management/rooms/{$this->labARoom->id}/templates", $upload())->assertCreated();
        $this->postJson("/api/room-management/rooms/{$this->labBRoom->id}/templates", $upload())->assertNotFound();

        $this->actingAsLaboran();
        $this->postJson("/api/room-management/rooms/{$this->labBRoom->id}/templates", $upload())->assertCreated();
        $this->postJson("/api/room-management/rooms/{$this->classroom->id}/templates", $upload())->assertNotFound();
    }

    private function pdf(string $name = 'template.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Size 1>>\n%%EOF\n",
        );
    }
}
