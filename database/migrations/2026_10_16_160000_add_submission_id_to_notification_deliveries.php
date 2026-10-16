<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RV-PERF-12: evidence packs filtered every notification delivery in PHP by
 * payload.submission_id. Store it in an indexed column and backfill it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->unsignedBigInteger('submission_id')->nullable()->after('user_id');
            $table->index('submission_id', 'notification_deliveries_submission_idx');
        });

        DB::table('notification_deliveries')
            ->select(['id', 'payload'])
            ->whereNotNull('payload')
            ->orderBy('id')
            ->chunkById(1000, function ($rows): void {
                $bySubmission = [];
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);
                    $submissionId = is_array($payload) ? ($payload['submission_id'] ?? null) : null;
                    if (is_numeric($submissionId)) {
                        $bySubmission[(int) $submissionId][] = $row->id;
                    }
                }

                foreach ($bySubmission as $submissionId => $ids) {
                    DB::table('notification_deliveries')->whereIn('id', $ids)->update(['submission_id' => $submissionId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex('notification_deliveries_submission_idx');
            $table->dropColumn('submission_id');
        });
    }
};
