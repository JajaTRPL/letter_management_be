<?php

namespace Tests\Feature\Workflow;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use App\Services\ProsesLuarNegeriService;
use App\Services\SuratKeteranganAktifService;
use App\Services\SuratPengantarMagangService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

class AdministrativePdfEnterpriseContextTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_magang_final_pdf_context_uses_official_kadep_not_sekdep_actor(): void
    {
        Storage::fake('public');

        [$application, $officialKadep, $sekdepActor] = $this->applicationWithOfficialKadep(
            SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            fn (User $student, array $attributes) => $this->magangApplication($student, $attributes),
        );

        $html = $this->renderDocumentHtml(app(SuratPengantarMagangService::class), $application, $sekdepActor);

        $this->assertEnterpriseDocumentContext($html, $officialKadep, $sekdepActor);
    }

    public function test_aktif_final_pdf_context_uses_official_kadep_not_sekdep_actor(): void
    {
        Storage::fake('public');

        [$application, $officialKadep, $sekdepActor] = $this->applicationWithOfficialKadep(
            SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            fn (User $student, array $attributes) => $this->aktifApplication($student, $attributes),
        );

        $html = $this->renderDocumentHtml(app(SuratKeteranganAktifService::class), $application, $sekdepActor);

        $this->assertEnterpriseDocumentContext($html, $officialKadep, $sekdepActor);
    }

    public function test_pln_final_pdf_context_uses_official_kadep_not_sekdep_actor(): void
    {
        Storage::fake('public');

        [$application, $officialKadep, $sekdepActor] = $this->applicationWithOfficialKadep(
            ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            fn (User $student, array $attributes) => $this->prosesLuarNegeriApplication($student, $attributes),
        );

        $html = $this->renderDocumentHtml(app(ProsesLuarNegeriService::class), $application, $sekdepActor);

        $this->assertEnterpriseDocumentContext($html, $officialKadep, $sekdepActor);
    }

    private function applicationWithOfficialKadep(string $status, callable $factory): array
    {
        $department = $this->department([
            'code' => 'DTEDI',
            'name' => 'Canonical DTEDI Department',
        ]);
        $program = $this->studyProgram($department, [
            'code' => 'TRPL',
            'name' => 'Canonical TRPL Program',
        ]);

        [$student] = $this->completeMahasiswa([], [
            'nim' => '22/493038/SV/20654',
        ], $program);

        $officialKadep = $this->akademik('kadep', [
            'name' => 'Official Kadep Enterprise',
            'department_id' => $department->id,
            'nip' => '198001012006041001',
            'signature_path' => Storage::url('signatures/official-kadep.png'),
        ]);
        $sekdepActor = $this->akademik('sekdep', [
            'name' => 'Sekdep Approval Actor',
            'department_id' => $department->id,
            'signature_path' => Storage::url('signatures/sekdep-actor.png'),
        ]);

        Storage::disk('public')->put('signatures/official-kadep.png', $this->pngBytes('official-kadep-marker'));
        Storage::disk('public')->put('signatures/sekdep-actor.png', $this->pngBytes('sekdep-actor-marker'));

        $application = $factory($student, [
            'status' => $status,
            'nomor_surat' => 'DOC-ENTERPRISE-001',
            'kaprodi_approved_at' => now()->subHour(),
            'kadep_approved_at' => now(),
        ]);

        return [$application, $officialKadep, $sekdepActor];
    }

    private function renderDocumentHtml(object $service, Model $application, User $actor): string
    {
        $method = new ReflectionMethod($service, 'buildDocumentHtml');
        $method->setAccessible(true);

        return $method->invoke($service, $application, $actor);
    }

    private function assertEnterpriseDocumentContext(
        string $html,
        User $officialKadep,
        User $sekdepActor
    ): void {
        $this->assertStringContainsString('Canonical TRPL Program', $html);
        $this->assertStringContainsString('Canonical DTEDI Department', $html);

        $this->assertStringContainsString($officialKadep->name, $html);
        $this->assertStringContainsString('NIP. 198001012006041001', $html);
        $this->assertStringNotContainsString($sekdepActor->name, $html);

        $this->assertStringContainsString('alt="Paraf"', $html);
        $this->assertStringContainsString('alt="Tanda tangan"', $html);
        $this->assertStringContainsString('data:image/png;base64', $html);
    }

    private function pngBytes(string $marker): string
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';

        return base64_decode($png) . $marker;
    }
}
