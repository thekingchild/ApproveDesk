# 0006. Livewire component convention

Date: 2026-10-17

Status: Proposed

## Context

The app has 39 single-file Livewire components (SFCs), all routed pages under `resources/views/pages/` (`Route::livewire(..., 'pages::…')`). Eight of them carry the starter kit's `⚡` file prefix and 31 do not. Next to them sit 14 plain Blade views (auth, help, partials). There are three class components in `app/Livewire/`: the announcement banner, global search and the notification indicator. Nothing documents when to use which. The Wave 3 review found the consequences:

- **Oversized pages hold business rules** (lead L2, RV-ARC-01…11): `submissions/show` is 1,383 lines, `admin/request-types` 993, and the workflow builder and templates pages about 750 each. Their copies of engine and compiler rules have drifted from the originals, and that drift already caused user-visible bugs (RV-ARC-01, RV-ARC-05).
- **Tamperable state.**
  - Record keys were unlocked on 11 pages until RV-LAR-15.
  - A `#[Locked]` attribute without its `use` import resolved to `\Locked` and Livewire ignored it (RV-ARC-04).
  - The announcement banner trusted client-sent IDs (RV-SEC-23, S2).
  - Public helper methods such as `fileUrl()` can be called from the browser (RV-LAR-16).
- **Mixed styles** for validation, authorization, `.live` bindings and exception handling (RV-LAR-11, RV-LAR-19, RV-ARC-19).

Decision 3 of the review plan already commits to splitting the four largest pages. This ADR sets the rules those splits and all new components follow.

## Decision

**Where components live**

- **Routed pages:** SFCs in `resources/views/pages/<area>/<name>.blade.php`, referenced as `pages::<area>.<name>`.
- **Embedded components** (a page's children and reusable widgets): SFCs in `resources/views/livewire/<area>/<name>.blade.php`, used as `<livewire:<area>.<name>>`. A class in `app/Livewire/` is used only when the component is mounted in a layout or needs a constructor-injected collaborator that an SFC cannot express.
- **Plain Blade views** without state stay as Blade components or partials, not Livewire.
- **New files have no `⚡` prefix.** Existing ones keep their names until the file is next changed for another reason, so a rename never lands in a behaviour PR.

**What a component may contain**

- Binding, validation, authorization and calls to Actions or Services. No domain rules: eligibility, state transitions, schema evaluation, totals and URL signing belong in `app/Actions`, `app/Services` or `app/Support` (ADR 0007) and get unit tests.
- **Split** when a component passes about 500 lines, or holds more than one independent workflow (for example deciding, commenting and managing followers). Move the rules out first, then split the template.

**State and endpoints**

- Every public property that identifies a record, or that the server sets and the client must not change, is `#[Locked]`. A test scans `resources/views/**/*.blade.php` and fails when a Livewire attribute is used without its `use` import.
- **Every public method is an endpoint.** It authorizes before it reads or changes anything (ADR 0008). Helpers the template needs are `protected`, or `#[Computed]` without parameters.
- **Large collections** are `#[Computed]`, not public properties. `persist`/`cache` on `#[Computed]` needs an ADR.
- **`wire:model`:** `.live` only on selects, checkboxes, radios and dates. Text inputs and textareas that drive something live use `.live.debounce.300ms` or `.blur`.

**Validation and errors**

- Inline `$this->validate([...])` is the house style. Rules shared with the API or another screen live in one class: a Form Request's rule method or a `Rule` object. Actions that take raw input validate it themselves.
- Domain exceptions reach the user through one shared trait or helper (planned under RV-ARC-19), not a `try`/`catch` per call site.

**Heavy sections:** report cards, analytics and long threads may use `#[Lazy]` with a skeleton placeholder. Lazy sections must not hide the primary action on a screen.

## Consequences

- The splits planned in Wave 3 follow this layout, in the order set by the Wave 3 review: users, backups, branding, workflow templates and builder, request types, then `submissions/show`.
- The attribute-import test and the `#[Locked]` rule catch the class of bug behind RV-ARC-04 and RV-SEC-23 at review time.
- Moving rules out of components adds Actions and Services, which need unit tests, and keeps the existing page tests as regression cover. Coverage on `app/` should rise rather than fall.
- Mixed `⚡` naming persists for a while. It is cosmetic, and the rule above stops it from growing.
