<?php

namespace Database\Factories;

use App\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Theme> */
class ThemeFactory extends Factory
{
    protected $model = Theme::class;

    public function definition(): array
    {
        $name = fake()->unique()->colorName();

        return ['key' => Str::slug($name), 'name' => Str::title($name), 'is_builtin' => false, 'is_enabled' => true, 'is_default' => false, 'primary_light' => '#2563EB', 'primary_dark' => '#60A5FA', 'foreground_light' => '#FFFFFF', 'foreground_dark' => '#172554'];
    }
}
