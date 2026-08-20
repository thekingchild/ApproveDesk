<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->timestamp('edited_at')->nullable()->after('body');
        });

        Schema::create('comment_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained('comments')->cascadeOnDelete();
            $table->foreignId('editor_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['comment_id', 'created_at']);
        });

        Schema::create('comment_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained('comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['comment_id', 'user_id']);
        });

        Schema::create('submission_watchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained('submissions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('notify_comments')->default(true);
            $table->boolean('notify_status')->default(true);
            $table->timestamps();

            $table->unique(['submission_id', 'user_id']);
        });

        Schema::create('submission_nudges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained('submissions')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('step_id')->nullable()->constrained('workflow_template_steps')->nullOnDelete();
            $table->text('message')->nullable();
            $table->timestamp('scheduled_for');
            $table->boolean('delayed_for_quiet_hours')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['submission_id', 'recipient_id', 'created_at'], 'nudges_recipient_rate_index');
            $table->index(['actor_id', 'submission_id', 'created_at'], 'nudges_actor_rate_index');
        });

        Schema::create('user_out_of_office', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->foreignId('fallback_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'starts_at', 'ends_at'], 'ooo_user_window_index');
            $table->index(['fallback_user_id', 'starts_at', 'ends_at'], 'ooo_fallback_window_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_out_of_office');
        Schema::dropIfExists('submission_nudges');
        Schema::dropIfExists('submission_watchers');
        Schema::dropIfExists('comment_mentions');
        Schema::dropIfExists('comment_revisions');

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropColumn('edited_at');
        });
    }
};
