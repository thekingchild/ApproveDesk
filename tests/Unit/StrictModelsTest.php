<?php

namespace Tests\Unit;

use App\Models\Submission;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/** RV-LAR-03: outside production, reading an unloaded column or a misspelled attribute fails loudly. */
class StrictModelsTest extends TestCase
{
    public function test_strict_model_checks_are_on_outside_production(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());
        $this->assertTrue(Model::preventsAccessingMissingAttributes());
        $this->assertTrue(Model::preventsSilentlyDiscardingAttributes());
    }

    public function test_a_misspelled_attribute_throws_instead_of_reading_null(): void
    {
        $submission = (new Submission)->forceFill(['id' => 1, 'reference_number' => 'SUB-2026-00001']);
        $submission->exists = true;

        $this->expectException(MissingAttributeException::class);
        $submission->getAttribute('reference');
    }
}
