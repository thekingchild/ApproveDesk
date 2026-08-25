<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('workflow_template_steps', 'wts_template_version_id_index')) {
            Schema::table('workflow_template_steps', function (Blueprint $table): void {
                $table->index('template_version_id', 'wts_template_version_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('workflow_template_steps', 'wts_template_version_id_index')) {
            Schema::table('workflow_template_steps', function (Blueprint $table): void {
                $table->dropIndex('wts_template_version_id_index');
            });
        }
    }
};
