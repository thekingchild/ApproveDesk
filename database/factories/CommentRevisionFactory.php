<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\CommentRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommentRevision> */
class CommentRevisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'comment_id' => Comment::factory(),
            'editor_id' => User::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
