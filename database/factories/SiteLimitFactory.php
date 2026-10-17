<?php

namespace Database\Factories;

use App\Models\SiteLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteLimit>
 */
class SiteLimitFactory extends Factory
{
    protected $model = SiteLimit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => SiteLimit::SINGLETON_ID,
            'max_active_users' => null,
        ];
    }
}
