<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Browser push auth secrets were stored in plain text (RV-LAR-05). Widen the
 * column for the encrypted form and encrypt existing rows in place; the
 * model now casts the attribute as `encrypted`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->text('auth_token')->change();
        });

        DB::table('push_subscriptions')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('push_subscriptions')->where('id', $row->id)->update([
                    'auth_token' => Crypt::encryptString((string) $row->auth_token),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('push_subscriptions')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                try {
                    $plain = Crypt::decryptString((string) $row->auth_token);
                } catch (Throwable) {
                    // Unreadable with this key: the browser subscribes again.
                    DB::table('push_subscriptions')->where('id', $row->id)->delete();

                    continue;
                }

                DB::table('push_subscriptions')->where('id', $row->id)->update(['auth_token' => $plain]);
            }
        });

        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->string('auth_token', 255)->change();
        });
    }
};
