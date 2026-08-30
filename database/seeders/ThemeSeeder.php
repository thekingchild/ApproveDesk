<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();

            foreach ([
                ['key' => 'approvedesk', 'name' => 'ApproveDesk', 'primary_light' => '#2563EB', 'primary_dark' => '#60A5FA', 'foreground_light' => '#FFFFFF', 'foreground_dark' => '#172554'],
                ['key' => 'ocean', 'name' => 'Ocean', 'primary_light' => '#0369A1', 'primary_dark' => '#38BDF8', 'foreground_light' => '#FFFFFF', 'foreground_dark' => '#082F49'],
                ['key' => 'orchid', 'name' => 'Orchid', 'primary_light' => '#7E22CE', 'primary_dark' => '#C084FC', 'foreground_light' => '#FFFFFF', 'foreground_dark' => '#3B0764'],
            ] as $index => $theme) {
                $existing = DB::table('themes')->where('key', $theme['key'])->exists();

                if ($existing) {
                    DB::table('themes')->where('key', $theme['key'])->update($theme + [
                        'is_builtin' => true,
                        'archived_at' => null,
                        'updated_at' => $now,
                    ]);

                    continue;
                }

                DB::table('themes')->insert($theme + [
                    'is_builtin' => true,
                    'is_enabled' => true,
                    'is_default' => $index === 0 && ! DB::table('themes')->where('is_default', true)->exists(),
                    'archived_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (! DB::table('themes')->where('is_default', true)->exists()) {
                DB::table('themes')->where('key', 'approvedesk')->update([
                    'is_enabled' => true,
                    'is_default' => true,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
