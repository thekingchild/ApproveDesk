<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Deleting while paging by offset skipped every other page of stale registrations. */
class PruneUnconfirmedPagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_stale_registration_is_pruned_in_one_run_beyond_a_single_page(): void
    {
        $now = now();
        $created = $now->copy()->subDays(4);

        foreach (array_chunk(range(1, 1050), 250) as $chunk) {
            DB::table('users')->insert(array_map(fn (int $i): array => [
                'name' => 'Registrant '.$i,
                'email' => "registrant{$i}@example.com",
                'password' => 'x',
                'email_verified_at' => null,
                'is_active' => true,
                'remember_token' => Str::random(10),
                'created_at' => $created,
                'updated_at' => $created,
            ], $chunk));
            DB::table('registration_otp_codes')->insert(array_map(fn (int $i): array => [
                'email' => "registrant{$i}@example.com",
                'code_hash' => 'x',
                'expires_at' => $created,
                'attempts' => 0,
                'created_at' => $created,
                'updated_at' => $created,
            ], $chunk));
        }

        $this->artisan('users:prune-unconfirmed')->assertSuccessful();

        $this->assertSame(0, User::query()->where('email', 'like', 'registrant%')->count());
    }
}
