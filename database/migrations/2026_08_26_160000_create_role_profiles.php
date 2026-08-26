<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->unique()->constrained('roles')->cascadeOnDelete();
            $table->string('label');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_builtin')->default(false);
            $table->boolean('is_protected')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $builtIns = ['super-admin', 'admin', 'department-head', 'approver', 'submitter', 'viewer', 'auditor'];
        foreach (DB::table('roles')->orderBy('id')->get(['id', 'name']) as $role) {
            $slug = Str::slug((string) $role->name) ?: 'role-'.$role->id;
            DB::table('role_profiles')->insert([
                'role_id' => $role->id,
                'label' => Str::of((string) $role->name)->replace('-', ' ')->title()->toString(),
                'slug' => $slug,
                'is_builtin' => in_array($role->name, $builtIns, true),
                'is_protected' => in_array($role->name, $builtIns, true),
                'is_archived' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_profiles');
    }
};
