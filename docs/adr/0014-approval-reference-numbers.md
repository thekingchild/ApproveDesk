# 0014. Configurable approval reference numbers with scoped counters

Date: 2026-10-17

Status: Accepted (recorded retroactively; decision in force since SH-ID284, v2.8.4; RV-BUG-14 fix Unreleased)

## Context

Every submission has a human-facing approval ID in `submissions.reference_number`. The column is `string(50)` and has a unique index. Until v2.8.3 the format was fixed at `SUB-YYYY-00001`. Organizations asked for their own prefix, date parts, length, and sometimes non-guessable IDs. IDs are printed on reports, quoted in emails and links, and recorded in audit evidence, so a format change must never rewrite an issued ID. Concurrent submitters must never receive the same one.

## Decision

- **The schema lives in `settings`** under `approvals.reference.*` and is read through `App\Support\ApprovalReferenceSchema`, which is cache-backed via `Setting::get`. Fields and defaults:
  - prefix `SUB`: ASCII letters and digits, up to 12 characters, upper-cased on save;
  - separator `-`, `/`, or none;
  - year `yyyy`, `yy`, or `none`; month `none`, `mm`, or `mmm`;
  - digit length 4–12, default 5;
  - mode `sequential` (default) or `random`.

  The defaults reproduce `SUB-YYYY-00001`, so upgrades do not change numbering. Date parts use the application timezone.
- **Only the System owner may change the schema.** The card on `settings/approvals` is rendered and saved only when `isSystemOwner()` is true. The route itself needs `approval_settings.manage`. `UpdateApprovalReferenceSchemaAction` re-checks owner identity, validates, and writes every key in one transaction. Delegated approval-settings managers and the legacy `super-admin` role cannot read or change it.
- **Generation happens once, on create.** `Submission::booted()` calls `ReferenceNumberGenerator::next()` in the `creating` event when no reference is set. `CreateSubmissionAction` wraps the create in a transaction, so a failed create rolls back the allocation. Issued IDs are never re-formatted.
- **Sequential mode uses a locked counter per scope.** The scope is `<digits>:<rendered prefix>`, for example `5:SUB-2026-`, so it includes prefix, separator, year, and month values (`ApprovalReferenceSchema::counterScope()`). The generator `insertOrIgnore`s the counter row in `approval_reference_counters` (unique `scope`), locks it with `lockForUpdate()`, and increments it. The closure is passed to `DB::transaction(..., 3)`.
  - A new scope seeds from the highest matching existing reference, including soft-deleted rows, so an upgraded install continues from its legacy numbers.
  - Including a year or month restarts numbering each period. Returning to an earlier format resumes that format's counter. Changing the digit length starts a separate counter.
  - When the next number would overflow the digit length, the generator throws ("range exhausted"). It never widens the number.
- **Random mode uses bounded reservation.** It makes up to 25 attempts. Each attempt draws `random_int` digits of the full length, with no leading zero (`RandomDigitGenerator`). It skips any value already used by a submission (including soft-deleted) or by an existing reservation. It then reserves the value by inserting a counter row with scope `r:<reference>`, and the unique `scope` index settles races. After 25 collisions it throws.
- **RV-BUG-14 fix.** Random IDs can land ahead of a sequential counter. Previously, when sequential mode was restored, the insert collided and the counter rolled back, so every later request retried the same number and failed. Now the sequential allocator skips any number already taken by a submission or an `r:` reservation before it updates the counter (`ReferenceNumberGenerator.php:67-72`).
- The unique index on `submissions.reference_number` remains the final guard.

## Consequences

- Concurrent sequential creates in one scope are serialized on the counter row until the outer transaction commits. This is a short wait, not a duplicate. Allocation happens inside the create transaction, so a failed create does not use up a number.
- `approval_reference_counters` holds one row per sequential scope plus one row per random ID ever issued. Random rows are never pruned.
- Small random spaces fill quickly: 4 digits gives 9,000 values, and the collision rate rises as the space fills. Owners choosing random mode should pick a larger digit length.
- Switching modes or formats is safe at any time and affects only approvals created after the save (docs/kb/approval-settings.md).
- Seeding a new scope scans every reference with that prefix once, which is a one-time cost per scope.

## Open questions

- AGENTS.md says to generate reference numbers "with a retry loop around the unique constraint". The code instead uses row-lock allocation for sequential mode and a 25-attempt reservation loop for random mode. Nothing catches and retries a unique violation on `submissions.reference_number` itself. The plan (IMPLEMENTATION_PLAN.md, SH-ID284) describes the code as built. Should AGENTS.md be reworded to match?
- Owner authority is checked by `isSystemOwner()` in the Livewire component and in the action, not by a Policy class as AGENTS.md prefers. It is an identity check, not a role-name check, but it is not routed through a Policy.
