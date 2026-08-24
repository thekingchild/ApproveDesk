# Performance budgets

Documented capacities for the single-organization product before tenant isolation (Phase 10). CI does not insert 100k rows. It checks that list queries stay bounded as row counts grow, that hot-path indexes exist, and that the Operations page stays inside its query budget.

| Target | Budget | How it is checked |
|---|---|---|
| Submissions | 100,000 | `PerformanceBudgets::SUBMISSIONS`; `ops:budget-report` |
| Workflow actions | 1,000,000 | Same; `workflow_actions.instance_id` index |
| Large attachment | 50 MiB | Documented upload ceiling for signed download/preview |
| Approver fan-out | 50 assignees on one step | Engine initialize query bound on a 10-approver chain in CI |
| Dashboard queries | 40 | `QueryBudgetTest` |
| Approval inbox queries | 30 | `QueryBudgetTest` |
| Submission list queries | 24 | `QueryBudgetTest`; list query count must not grow linearly |
| Operations page queries | 40 | `PerformanceBudgetTest` |
| List growth slack | +4 queries when rows increase 5× | `PerformanceBudgetTest` |

## Load profile (staging / dedicated host)

Run only against a disposable database. Not part of default CI.

1. Seed ~100k submissions and ~1m `workflow_actions` with a factory script outside the default suite (`RUN_LOAD_TESTS=1` when added).
2. Measure p95 for dashboard, inbox, submission list, and a single approve.
3. Upload one ~50 MiB PDF and confirm signed preview/download.
4. Publish a parallel step with 50 assignees and confirm initialize + inbox stay inside the query budgets above.
5. Record results next to the release tag.

Shared hosting remains the runtime constraint: database queue drained once per minute, no Redis required.
