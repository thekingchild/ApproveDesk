# 0004. Portable NOT NULL timestamps and a one-time released-migration exception

Date: 2026-10-16

Status: Accepted

## Context

ApproveDesk supports MariaDB 10.6+. MariaDB before 10.10 defaults to `explicit_defaults_for_timestamp=OFF`, and before 10.10 that variable is global-only, so the application cannot set it per session. With it off:

- the first `NOT NULL TIMESTAMP` column in a table without an explicit default silently becomes `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`;
- any later one gets `DEFAULT '0000-00-00 00:00:00'`, which Laravel's strict mode rejects.

Ten tables declared such columns with `$table->timestamp('x')`. Three of them (`delegations`, `user_out_of_office`, `auditor_accesses`) have two, so a fresh install on MariaDB 10.6–10.9 fails at `2026_08_17_120000_create_delegations_table`. The other seven would quietly overwrite values such as `workflow_instances.started_at` and `legal_holds.placed_at` on every row update — unacceptable for audit evidence. The new MariaDB 10.6 CI job exposed this (RV-DATA-02).

AGENTS.md forbids editing released migrations. A new migration cannot fix this, because installation fails before any later migration runs.

## Decision

- Add `App\Support\SchemaTimestamps::required($table, $column)`. On MariaDB with `explicit_defaults_for_timestamp=OFF` it adds `useCurrent()` (an explicit `DEFAULT CURRENT_TIMESTAMP`, no `ON UPDATE`), which disables both implicit behaviours. On every other server it returns the plain column, so MySQL 8, MariaDB 10.10+, and SQLite schemas are byte-for-byte unchanged.
- As a one-time, recorded exception to the released-migration rule, edit the seven create migrations that declared the 13 affected columns to use the helper.
- Every future `NOT NULL` timestamp column uses the helper. `Tests\Feature\Schema\PortableTimestampColumnsTest` fails on any bare `->timestamp('x')` without `nullable()`, `useCurrent()`, or `default()`, and on MySQL-family CI jobs asserts the affected columns are `NOT NULL`, have no `ON UPDATE`, and no zero-date default.
- `SchemaTimestamps` is referenced by released migrations and must keep its name and signature.

## Consequences

- No drift for real installations: MySQL/SQLite schemas are unchanged, and no MariaDB < 10.10 installation could have completed the edited migrations.
- MariaDB 10.6–10.9 installations get `DEFAULT CURRENT_TIMESTAMP` on these columns. The application always sets them explicitly, so the default is never relied on.
- The released-migration rule still stands. This exception is limited to migrations that cannot complete on a supported server.
