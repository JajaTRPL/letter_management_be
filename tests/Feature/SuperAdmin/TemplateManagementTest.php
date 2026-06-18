<?php

namespace Tests\Feature\SuperAdmin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

class TemplateManagementTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private string $tempCachePath;
    private array $tempCachePaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempCachePath = sys_get_temp_dir() . '/tmgmt_test_' . uniqid('', true) . '.docx';
        $this->tempCachePaths[] = $this->tempCachePath;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempCachePaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_super_admin_can_list_templates(): void
    {
        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/super-admin/templates')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'surat-permohonan-beasiswa')
            ->assertJsonPath('data.0.name', 'Surat Permohonan Beasiswa')
            ->assertJsonPath('data.0.can_refresh', true);

        $templates = collect($response->json('data'))->keyBy('key');

        $this->assertSame([
            'surat-permohonan-beasiswa',
            'surat-keterangan-aktif',
            'proses-luar-negeri',
            'surat-pengantar-magang',
            'surat-tugas',
        ], $templates->keys()->all());

        foreach (self::managedTemplateExpectations() as $key => $expected) {
            $this->assertTrue($templates->has($key), "{$key} should be listed.");
            $template = $templates->get($key);

            $this->assertSame($expected['name'], $template['name']);
            $this->assertSame($expected['category'], $template['category'], "{$key} should expose its canonical Jenis category.");
            $this->assertSame('google_docs', $template['source_type']);
            $this->assertTrue($template['can_refresh']);
            $this->assertSame($expected['template_id_config_key'], $template['template_id_config_key']);
            $this->assertSame($expected['cache_path_config_key'], $template['cache_path_config_key']);
            $this->assertIsString(config('surat.' . $expected['template_id_config_key']));
            $this->assertIsString(config('surat.' . $expected['cache_path_config_key']));
            $this->assertNotEmpty($template['template_id_masked']);
            $this->assertStringStartsWith('storage/app/templates/', $template['cache_path_display']);
        }
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

        foreach ($response->json('data') as $template) {
            $display = $template['cache_path_display'] ?? null;
            if ($display !== null) {
                $this->assertStringNotContainsString('public', strtolower($display));
            }
        }

        $this->assertTrue(true);
    }

    // ── Refresh behavior ──────────────────────────────────────────────────────

    #[DataProvider('managedTemplateProvider')]
    public function test_refresh_writes_configured_cache_path_when_google_returns_valid_docx(
        string $key,
        string $templateIdConfigKey,
        string $cachePathConfigKey,
    ): void
    {
        $docx = $this->minimalDocxBytes();
        $tempCachePath = sys_get_temp_dir() . '/tmgmt_' . str_replace('-', '_', $key) . '_' . uniqid('', true) . '.docx';
        $this->tempCachePaths[] = $tempCachePath;
        $templateId = 'test-template-id-' . $key;

        config([
            'surat.' . $templateIdConfigKey => $templateId,
            'surat.' . $cachePathConfigKey => $tempCachePath,
        ]);

        $controller = new class extends \App\Http\Controllers\SuperAdmin\TemplateManagementController {
            public string $injectedContent = '';
            public array $requestedTemplateIds = [];

            protected function fetchFromGoogleDocx(string $templateId): string|false
            {
                $this->requestedTemplateIds[] = $templateId;
                return $this->injectedContent !== '' ? $this->injectedContent : false;
            }
        };
        $controller->injectedContent = $docx;
        $this->app->instance(\App\Http\Controllers\SuperAdmin\TemplateManagementController::class, $controller);

        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $this->postJson("/api/super-admin/templates/{$key}/refresh")
            ->assertOk()
            ->assertJsonPath('message', 'Cache template berhasil diperbarui');

        $this->assertSame([$templateId], $controller->requestedTemplateIds);
        $this->assertTrue(file_exists($tempCachePath));
        $this->assertGreaterThan(0, filesize($tempCachePath));
    }

    public function test_invalid_response_does_not_replace_existing_cache(): void
    {
        $original = $this->minimalDocxBytes();
        file_put_contents($this->tempCachePath, $original);
        config(['surat.template_beasiswa_cache_path' => $this->tempCachePath]);

        $this->app->bind(
            \App\Http\Controllers\SuperAdmin\TemplateManagementController::class,
            fn () => new class extends \App\Http\Controllers\SuperAdmin\TemplateManagementController {
                protected function fetchFromGoogleDocx(string $templateId): string|false
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
                protected function fetchFromGoogleDocx(string $templateId): string|false { return false; }
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

    public static function managedTemplateProvider(): array
    {
        return array_map(
            fn (array $expected): array => [
                $expected['key'],
                $expected['template_id_config_key'],
                $expected['cache_path_config_key'],
            ],
            self::managedTemplateExpectations(),
        );
    }

    private static function managedTemplateExpectations(): array
    {
        return [
            'surat-permohonan-beasiswa' => [
                'key' => 'surat-permohonan-beasiswa',
                'name' => 'Surat Permohonan Beasiswa',
                'category' => 'Surat Beasiswa',
                'template_id_config_key' => 'template_beasiswa_id',
                'cache_path_config_key' => 'template_beasiswa_cache_path',
            ],
            'surat-keterangan-aktif' => [
                'key' => 'surat-keterangan-aktif',
                'name' => 'Surat Keterangan Aktif',
                'category' => 'Surat Keaktifan',
                'template_id_config_key' => 'template_surat_keterangan_aktif_id',
                'cache_path_config_key' => 'template_surat_keterangan_aktif_cache_path',
            ],
            'proses-luar-negeri' => [
                'key' => 'proses-luar-negeri',
                'name' => 'Proses Luar Negeri',
                'category' => 'Surat Luar Negeri',
                'template_id_config_key' => 'template_proses_luar_negeri_id',
                'cache_path_config_key' => 'template_proses_luar_negeri_cache_path',
            ],
            'surat-pengantar-magang' => [
                'key' => 'surat-pengantar-magang',
                'name' => 'Surat Pengantar Magang',
                'category' => 'Surat Magang',
                'template_id_config_key' => 'template_surat_pengantar_magang_id',
                'cache_path_config_key' => 'template_surat_pengantar_magang_cache_path',
            ],
            'surat-tugas' => [
                'key' => 'surat-tugas',
                'name' => 'Surat Tugas',
                'category' => 'Surat Tugas',
                'template_id_config_key' => 'template_surat_tugas_id',
                'cache_path_config_key' => 'template_surat_tugas_cache_path',
            ],
        ];
    }

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
