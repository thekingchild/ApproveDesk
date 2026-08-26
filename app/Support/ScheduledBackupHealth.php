<?php

namespace App\Support;

use App\Enums\BackupSnapshotKind;
use App\Models\BackupSnapshot;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ScheduledBackupHealth
{
    public function lastScheduledAt(BackupSnapshotKind $kind): ?CarbonInterface
    {
        $value = Setting::get($this->key($kind, 'at'));

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function lastScheduledSnapshot(BackupSnapshotKind $kind): ?BackupSnapshot
    {
        $id = Setting::get($this->key($kind, 'snapshot_id'));

        return is_numeric($id) ? BackupSnapshot::query()->find((int) $id) : null;
    }

    public function record(BackupSnapshotKind $kind, BackupSnapshot $snapshot): void
    {
        Setting::put($this->key($kind, 'at'), now()->toIso8601String());
        Setting::put($this->key($kind, 'snapshot_id'), (string) $snapshot->id);
    }

    public function nextRun(string $clock, ?CarbonInterface $from = null): CarbonImmutable
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $from = CarbonImmutable::instance($from ?? now())->setTimezone($timezone);
        [$hour, $minute] = array_map('intval', explode(':', $clock));
        $candidate = $from->setTime($hour, $minute);

        return $candidate->lessThanOrEqualTo($from) ? $candidate->addDay() : $candidate;
    }

    private function key(BackupSnapshotKind $kind, string $suffix): string
    {
        return 'backup.last_scheduled_'.$kind->value.'_'.$suffix;
    }
}
