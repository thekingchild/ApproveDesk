# 0001. Organization working hours live on the existing business calendar

Date: 2026-08-20

Status: Accepted

## Context

Workflow steps choose Elapsed vs Business (`TimeoutCalendar`). `BusinessCalendar`
skips non-working weekdays and holidays only; `addBusinessHours()` ticks
`addHour()` then counts working calendar days. `WorkflowInstance::deadlineAt()`
is computed live from `step_started_at` and current settings. Operators want
office hours (e.g. 09:00–17:00) without a third enum, without snapshotting
`deadline_at`, and without silently shrinking in-flight Business SLAs.

## Decision

- One organization, one calendar. Store optional JSON `calendar.working_hours`
  (`start`, `end`, optional `by_day`) in `settings`. No new table.
- Unset, empty, or invalid top-level window ⇒ today’s 24-hour-on-working-day
  behaviour. Do not backfill 09:00–17:00.
- **Hybrid algorithm:** keep the existing hour-tick loop when the window is
  unset. When a window is set, skip to the next half-open `[start, end)` and
  consume remaining **integer seconds** (`hours * 3600`). `take <= 0` jumps
  strictly forward via `nextCountableStart` (`setTime`, never `addHours(24)`).
- No third `TimeoutCalendar` case; published-graph schema unchanged.
- No feature flag (7A shipped none; unset hours already preserve production).
- No `deadline_at` column; live calendar stays.
- `deadlineAt()` fails open to `null` if the windowed path throws.
- Hours are `config('app.timezone')` (UTC). Phase 10 `P10-TEN-01` must revisit
  DST-missing local midnights.

## Consequences

- Saving office hours immediately moves in-flight Business overdue/escalation.
  Elapsed hours and email-link expiry stay wall-clock.
- A 1-minute window is legal. Outer-loop cap and `nextCountableStart`'s
  4000-day per-call walk are independent counters (do not share).
- `by_day` is honored if present; 7B UI does not edit it.
- Analytics must eager-load `timeout_calendar` or Business overdue stays wrong.
