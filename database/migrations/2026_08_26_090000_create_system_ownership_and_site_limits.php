<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_owners', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('site_limits', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('max_active_users')->nullable();
            $table->timestamps();
        });

        DB::table('site_limits')->insert(['id' => 1, 'max_active_users' => null, 'created_at' => now(), 'updated_at' => now()]);

        $superAdminRoleId = DB::table('roles')->where('name', 'super-admin')->value('id');
        if ($superAdminRoleId === null) {
            Log::critical('No system owner was backfilled because the super-admin role does not exist. Run owner:assign {email}.');

            return;
        }

        $legacyOwnerIds = DB::table('model_has_roles')
            ->where('role_id', $superAdminRoleId)
            ->where('model_type', 'App\\Models\\User')
            ->orderBy('model_id')
            ->pluck('model_id');

        $ownerId = $legacyOwnerIds->shift();
        if ($ownerId === null) {
            Log::critical('No system owner was backfilled because no super-admin exists. Run owner:assign {email}.');

            return;
        }

        DB::table('system_owners')->insert(['id' => 1, 'user_id' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        foreach ($legacyOwnerIds as $legacyId) {
            if ($adminRoleId !== null) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $adminRoleId,
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $legacyId,
                ]);
            }

            DB::table('model_has_roles')
                ->where('role_id', $superAdminRoleId)
                ->where('model_type', 'App\\Models\\User')
                ->where('model_id', $legacyId)
                ->delete();
        }

        if ($legacyOwnerIds->isNotEmpty()) {
            Log::warning('Converted additional legacy super-admins to delegated administrators.', ['user_ids' => $legacyOwnerIds->values()->all()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_limits');
        Schema::dropIfExists('system_owners');
    }
};
