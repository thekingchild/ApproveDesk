<?php

namespace Database\Factories;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementDisplayMode;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Announcement> */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'creator_id' => User::factory(),
            'title' => fake()->sentence(4),
            'message' => '<p>'.fake()->sentence().'</p>',
            'audience' => AnnouncementAudience::All,
            'role_ids' => null,
            'display_mode' => AnnouncementDisplayMode::Sticky,
            'status' => AnnouncementStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => AnnouncementStatus::Published, 'published_at' => now(), 'archived_at' => null]);
    }
}
