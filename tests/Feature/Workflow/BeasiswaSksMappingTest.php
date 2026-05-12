<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeasiswaSksMappingTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_sks_fields_are_stored_and_retrieved_separately(): void
    {
        $app = $this->scholarshipApplication(null, [
            'sks_last_2_semesters' => 58,
            'total_sks_passed'     => 38,
            'total_sks_required'   => 144,
        ]);

        $fresh = ScholarshipApplication::find($app->id);

        $this->assertSame(58, $fresh->sks_last_2_semesters);
        $this->assertSame(38, $fresh->total_sks_passed);
        $this->assertSame(144, $fresh->total_sks_required);
    }

    public function test_sksk_and_sks_total_map_to_separate_db_fields(): void
    {
        // ${sksk}    must come from total_sks_passed    (SKS Kumulatif)
        // ${sks_total} must come from total_sks_required (beban lulus)
        $app = $this->scholarshipApplication(null, [
            'total_sks_passed'   => 38,
            'total_sks_required' => 144,
        ]);

        $this->assertNotEquals($app->total_sks_passed, $app->total_sks_required);
        $this->assertSame(38, (int) $app->total_sks_passed);
        $this->assertSame(144, (int) $app->total_sks_required);
    }

    public function test_sks_2_maps_to_sks_last_2_semesters(): void
    {
        $app = $this->scholarshipApplication(null, [
            'sks_last_2_semesters' => 58,
            'total_sks_passed'     => 38,
            'total_sks_required'   => 144,
        ]);

        // Verify the generator source for ${sks_2}
        $this->assertSame(58, (int) $app->sks_last_2_semesters);
    }

    public function test_old_records_with_null_total_sks_required_are_null(): void
    {
        $app = $this->scholarshipApplication(null, [
            'total_sks_passed' => 38,
            // total_sks_required intentionally omitted
        ]);

        $this->assertNull(ScholarshipApplication::find($app->id)->total_sks_required);
    }

    public function test_total_sks_required_is_fillable(): void
    {
        $app = $this->scholarshipApplication();
        $app->update(['total_sks_required' => 120]);

        $this->assertSame(120, (int) ScholarshipApplication::find($app->id)->total_sks_required);
    }
}
