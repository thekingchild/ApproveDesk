<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacy = collect(Schema::getIndexes('push_subscriptions'))->first(
            fn (array $index): bool => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['user_id', 'endpoint'],
        );

        if ($legacy !== null) {
            Schema::table('push_subscriptions', function (Blueprint $table) use ($legacy): void {
                $table->dropUnique($legacy['name']);
            });
        }

        if (! Schema::hasColumn('push_subscriptions', 'endpoint_hash')) {
            Schema::table('push_subscriptions', function (Blueprint $table): void {
                $table->string('endpoint_hash', 64)->default('');
            });

            DB::table('push_subscriptions')->orderBy('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('push_subscriptions')->where('id', $row->id)->update([
                        'endpoint_hash' => hash('sha256', (string) $row->endpoint),
                    ]);
                }
            });
        }

        $hashUnique = collect(Schema::getIndexes('push_subscriptions'))->first(
            fn (array $index): bool => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['user_id', 'endpoint_hash'],
        );

        if ($hashUnique === null) {
            Schema::table('push_subscriptions', function (Blueprint $table): void {
                $table->unique(['user_id', 'endpoint_hash'], 'push_sub_user_ep_hash_unique');
            });
        }
    }

    public function down(): void
    {
        $hashUnique = collect(Schema::getIndexes('push_subscriptions'))->first(
            fn (array $index): bool => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['user_id', 'endpoint_hash'],
        );

        if ($hashUnique !== null) {
            Schema::table('push_subscriptions', function (Blueprint $table) use ($hashUnique): void {
                $table->dropUnique($hashUnique['name']);
            });
        }

        if (Schema::hasColumn('push_subscriptions', 'endpoint_hash')) {
            Schema::table('push_subscriptions', function (Blueprint $table): void {
                $table->dropColumn('endpoint_hash');
            });
        }
    }
};
