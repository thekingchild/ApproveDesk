<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnnouncementReceipt> */
class AnnouncementReceiptFactory extends Factory
{
    protected $model = AnnouncementReceipt::class;

    public function definition(): array
    {
        return ['announcement_id' => Announcement::factory(), 'user_id' => User::factory(), 'first_displayed_at' => now(), 'acknowledged_at' => null];
    }
}
