<?php

namespace Tests\Feature\SuperAdmin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

class TemplateManagementTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private string $tempCachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempCachePath = sys_get_temp_dir() . '/tmgmt_test_' . uniqid('', true) . '.docx';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempCachePath)) {
            @unlink($this->tempCachePath);
        }
        parent::tearDown();
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_super_admin_can_list_templates(): void
    {
        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/super-admin/templates')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'surat-permohonan-beasiswa')
            ->assertJsonPath('data.0.can_refresh', true);
    }

    public function test_non_super_admin_cannot_list_templates(): void
    {
        $tendik = $this->tendikPersuratan();
        Sanctum::actingAs($tendik);

        $this->getJson('/api/super-admin/templates')
            ->assertForbidden();
    }

    public function test_unauthenticated_cannot_list_templates(): void
    {
        $this->getJson('/api/super-admin/templates')
            ->assertUnauthorized();
    }

    public function test_non_super_admin_cannot_refresh_template(): void
    {
        $tendik = $this->tendikPersuratan();
        Sanctum::actingAs($tendik);

        $this->postJson('/api/super-admin/templates/surat-permohonan-beasiswa/refresh')
            ->assertForbidden();
    }

    // ── Cache info ────────────────────────────────────────────────────────────

    public function test_index_reports_cache_exists_when_file_present(): void
    {
        $docx = $this->minimalDocxBytes();
        file_put_contents($this->tempCachePath, $docx);
        config(['surat.template_beasiswa_cache_path' => $this->tempCachePath]);

        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/super-admin/templates')
            ->assertOk()
            ->assertJsonPath('data.0.cache_exists', true);
    }

    public function test_index_reports_cache_missing_when_file_absent(): void
    {
        config(['surat.template_beasiswa_cache_path' => $this->tempCachePath]);

        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/super-admin/templates')
            ->assertOk()
            ->assertJsonPath('data.0.cache_exists', false);
    }

    // ── Cache path security ───────────────────────────────────────────────────

    public function test_cache_path_is_not_under_public_storage(): void
    {
        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/super-admin/templates')->assertOk();
        $display  = $response->json('data.0.cache_path_display');

        if ($display !== null) {
            $this->assertStringNotContainsString('public', strtolower($display));
        }

        $this->assertTrue(true);
    }

    // ── Refresh behavior ──────────────────────────────────────────────────────

    public function test_refresh_writes_cache_when_google_returns_valid_docx(): void
    {
        $docx = $this->minimalDocxBytes();
        config(['surat.template_beasiswa_cache_path' => $this->tempCachePath]);

        $controller = new class extends \App\Http\Controllers\SuperAdmin\TemplateManagementController {
            public string $injectedContent = '';
            protected function fetchFromGoogleDocx(): string|false
            {
                return $this->injectedContent !== '' ? $this->injectedContent : false;
            }
        };
        $controller->injectedContent = $docx;
        $this->app->instance(\App\Http\Controllers\SuperAdmin\TemplateManagementController::class, $controller);

        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/super-admin/templates/surat-permohonan-beasiswa/refresh')
            ->assertOk()
            ->assertJsonPath('message', 'Cache template berhasil diperbarui');

        $this->assertTrue(file_exists($this->tempCachePath));
        $this->assertGreaterThan(0, filesize($this->tempCachePath));
    }

    public function test_invalid_response_does_not_replace_existing_cache(): void
    {
        $original = $this->minimalDocxBytes();
        file_put_contents($this->tempCachePath, $original);
        config(['surat.template_beasiswa_cache_path' => $this->tempCachePath]);

        $this->app->bind(
            \App\Http\Controllers\SuperAdmin\TemplateManagementController::class,
            fn () => new class extends \App\Http\Controllers\SuperAdmin\TemplateManagementController {
                protected function fetchFromGoogleDocx(): string|false
                {
                    return 'not-a-docx-content';
                }
            }
        );

        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/super-admin/templates/surat-permohonan-beasiswa/refresh')
            ->assertStatus(422);

        $this->assertSame($original, file_get_contents($this->tempCachePath), 'Existing cache must be preserved');
    }

    public function test_refresh_returns_502_when_google_is_unreachable(): void
    {
        config(['surat.template_beasiswa_cache_path' => $this->tempCachePath]);

        $this->app->bind(
            \App\Http\Controllers\SuperAdmin\TemplateManagementController::class,
            fn () => new class extends \App\Http\Controllers\SuperAdmin\TemplateManagementController {
                protected function fetchFromGoogleDocx(): string|false { return false; }
            }
        );

        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/super-admin/templates/surat-permohonan-beasiswa/refresh')
            ->assertStatus(502);
    }

    // ── Unknown key ───────────────────────────────────────────────────────────

    public function test_refresh_unknown_key_returns_404(): void
    {
        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/super-admin/templates/unknown-template/refresh')
            ->assertNotFound();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function minimalDocxBytes(): string
    {
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText('Test template');
        $tmp = tempnam(sys_get_temp_dir(), 'tmgmt_min_') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tmp);
        $content = file_get_contents($tmp);
        @unlink($tmp);
        return $content;
    }
}
