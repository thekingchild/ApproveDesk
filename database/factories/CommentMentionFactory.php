<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\CommentMention;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommentMention> */
class CommentMentionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'comment_id' => Comment::factory(),
            'user_id' => User::factory(),
        ];
    }
}
