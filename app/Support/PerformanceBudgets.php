<?php

namespace App\Support;

final class PerformanceBudgets
{
    public const SUBMISSIONS = 100_000;

    public const WORKFLOW_ACTIONS = 1_000_000;

    public const LARGE_ATTACHMENT_BYTES = 52_428_800;

    public const APPROVER_FAN_OUT = 50;

    public const DASHBOARD_QUERIES = 40;

    public const INBOX_QUERIES = 30;

    public const SUBMISSION_LIST_QUERIES = 24;

    public const OPERATIONS_QUERIES = 40;

    public const LIST_GROWTH_SLACK = 4;

    public const STUCK_AFTER_HOURS = 24;

    /**
     * @return array<string, int>
     */
    public static function documented(): array
    {
        return [
            'submissions' => self::SUBMISSIONS,
            'workflow_actions' => self::WORKFLOW_ACTIONS,
            'large_attachment_bytes' => self::LARGE_ATTACHMENT_BYTES,
            'approver_fan_out' => self::APPROVER_FAN_OUT,
            'dashboard_queries' => self::DASHBOARD_QUERIES,
            'inbox_queries' => self::INBOX_QUERIES,
            'submission_list_queries' => self::SUBMISSION_LIST_QUERIES,
            'operations_queries' => self::OPERATIONS_QUERIES,
        ];
    }
}
