<?php

namespace Tests\Feature\Workflow;

use App\Services\MahasiswaProfileDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MahasiswaProfileCompletenessContractTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_complete_profile_returns_canonical_completion_summary(): void
    {
        $response = $this->profileResponse();

        $response
            ->assertOk()
            ->assertJsonPath('completeness.is_complete', true)
            ->assertJsonPath('completeness.missing_fields', [])
            ->assertJsonPath('completeness.percentage', 100)
            ->assertJsonPath('completeness.filled_count', 14)
            ->assertJsonPath('completeness.total_count', 14);
    }

    public function test_optional_parent_fields_do_not_reduce_completion(): void
    {
        $response = $this->profileResponse(
            father: ['pekerjaan' => null, 'penghasilan' => null],
            mother: ['pekerjaan' => null, 'penghasilan' => null],
        );

        $response
            ->assertOk()
            ->assertJsonPath('completeness.is_complete', true)
            ->assertJsonPath('completeness.percentage', 100)
            ->assertJsonPath('completeness.filled_count', 14)
            ->assertJsonPath('completeness.total_count', 14);
    }

    #[DataProvider('missingPersonalFieldProvider')]
    public function test_missing_personal_field_reduces_canonical_percentage(string $field, string $label): void
    {
        $response = $this->profileResponse(profile: [$field => null]);

        $response
            ->assertOk()
            ->assertJsonPath('completeness.is_complete', false)
            ->assertJsonPath('completeness.percentage', 93)
            ->assertJsonPath('completeness.filled_count', 13)
            ->assertJsonPath('completeness.total_count', 14);
        $this->assertContains($label, $response->json('completeness.missing_fields'));
    }

    public static function missingPersonalFieldProvider(): array
    {
        return [
            'tempat lahir' => ['tempat_lahir', 'Tempat Lahir'],
            'tanggal lahir' => ['tanggal_lahir', 'Tanggal Lahir'],
            'jenis kelamin' => ['jenis_kelamin', 'Jenis Kelamin'],
            'canonical no hp' => ['no_hp', 'No. HP'],
            'alamat asal' => ['alamat_asal', 'Alamat Asal'],
            'alamat domisili' => ['alamat_domisili', 'Alamat Domisili'],
            'pas foto' => ['pas_foto_path', 'Pas Foto'],
            'tanda tangan' => ['tanda_tangan_path', 'Tanda Tangan'],
        ];
    }

    #[DataProvider('blankParentNameProvider')]
    public function test_whitespace_only_parent_name_is_missing(string $relation, string $label): void
    {
        $response = $this->profileResponse(
            father: $relation === 'ayah' ? ['nama_lengkap' => '   '] : [],
            mother: $relation === 'ibu' ? ['nama_lengkap' => '   '] : [],
        );

        $response
            ->assertOk()
            ->assertJsonPath('completeness.is_complete', false)
            ->assertJsonPath('completeness.percentage', 93)
            ->assertJsonPath('completeness.filled_count', 13)
            ->assertJsonPath('completeness.total_count', 14);
        $this->assertContains($label, $response->json('completeness.missing_fields'));
    }

    public static function blankParentNameProvider(): array
    {
        return [
            'father' => ['ayah', 'Data Ayah'],
            'mother' => ['ibu', 'Data Ibu'],
        ];
    }

    public function test_missing_academic_context_reduces_percentage(): void
    {
        $completion = $this->completion(user: [
            'study_program_id' => null,
            'department_id' => null,
        ]);

        $this->assertFalse($completion['is_complete']);
        $this->assertSame(79, $completion['percentage']);
        $this->assertSame(11, $completion['filled_count']);
        $this->assertSame(14, $completion['total_count']);
        $this->assertContains('Program Studi', $completion['missing_fields']);
        $this->assertContains('Departemen', $completion['missing_fields']);
        $this->assertContains('Fakultas', $completion['missing_fields']);
    }

    public function test_missing_nim_reduces_percentage(): void
    {
        $completion = $this->completion(profile: ['nim' => null]);

        $this->assertFalse($completion['is_complete']);
        $this->assertSame(93, $completion['percentage']);
        $this->assertSame(13, $completion['filled_count']);
        $this->assertSame(14, $completion['total_count']);
        $this->assertContains('NIM', $completion['missing_fields']);
    }

    public function test_completion_summary_treats_non_empty_zero_string_as_filled(): void
    {
        $response = $this->profileResponse(profile: ['tempat_lahir' => '0']);

        $response
            ->assertOk()
            ->assertJsonPath('completeness.is_complete', true)
            ->assertJsonPath('completeness.percentage', 100);
    }

    public function test_legacy_phone_alias_does_not_replace_missing_canonical_no_hp(): void
    {
        [$student, $studentProfile] = $this->createProfile(['no_hp' => null]);
        $studentProfile->load('keluarga');
        $studentProfile->setAttribute('no_telp', '081234567890');
        $student->setRelation('mahasiswaProfile', $studentProfile);

        $completion = app(MahasiswaProfileDataService::class)->completionForUser($student);

        $this->assertFalse($completion['is_complete']);
        $this->assertSame(93, $completion['percentage']);
        $this->assertContains('No. HP', $completion['missing_fields']);
    }

    private function profileResponse(
        array $profile = [],
        array $user = [],
        array $father = [],
        array $mother = [],
    ): TestResponse {
        [$student] = $this->createProfile($profile, $user, $father, $mother);

        return $this->actingAs($student, 'sanctum')->getJson('/api/profile');
    }

    private function completion(
        array $profile = [],
        array $user = [],
        array $father = [],
        array $mother = [],
    ): array {
        [$student] = $this->createProfile($profile, $user, $father, $mother);

        return app(MahasiswaProfileDataService::class)->completionForUser($student->fresh());
    }

    private function createProfile(
        array $profile = [],
        array $user = [],
        array $father = [],
        array $mother = [],
    ): array {
        [$student, $studentProfile] = $this->completeMahasiswa($user, array_merge([
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-01-15',
            'jenis_kelamin' => 'L',
            'no_hp' => '081234567890',
            'alamat_asal' => 'Alamat asal',
            'alamat_domisili' => 'Alamat domisili',
            'pas_foto_path' => '/storage/profiles/fotos/student.jpg',
            'tanda_tangan_path' => '/storage/profiles/signatures/student.png',
        ], $profile));

        $studentProfile->keluarga()->create(array_merge([
            'jenis_relasi' => 'ayah',
            'nama_lengkap' => 'Ayah Test',
        ], $father));
        $studentProfile->keluarga()->create(array_merge([
            'jenis_relasi' => 'ibu',
            'nama_lengkap' => 'Ibu Test',
        ], $mother));

        return [$student, $studentProfile];
    }
}
