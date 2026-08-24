<?php

namespace Database\Factories;

use App\Models\EvidencePack;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EvidencePack> */
class EvidencePackFactory extends Factory
{
    protected $model = EvidencePack::class;

    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'generated_by' => User::factory(),
            'stored_path' => 'evidence/'.fake()->uuid().'.zip',
            'disk' => 'local',
            'manifest_hash' => hash('sha256', 'manifest'),
            'signature' => hash('sha256', 'signature'),
        ];
    }
}
