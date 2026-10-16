# Request types

## Definition

A request type is a named form that people choose at **New submission**. It defines the fields to fill in and which workflow template will run when the form is submitted.

Leave Request, Purchase Request, and the built-in General Request are request types. Document, Note, Memo, and Request are not. Those four names are only fields on General Request.

## When to use it

Publish a request type when a process needs its own questions. Assign a workflow template when that process also needs its own approval path. Two forms may share one template. One form has one assignment.

## Lifecycle

1. Create a request type and add fields in a draft.
2. Choose the workflow template this form should use, or leave it on the default.
3. Preview, reorder, and duplicate fields. Import choice options from a one-column CSV if needed.
4. Publish. Publishing freezes that field version so later edits do not change requests already submitted.
5. Later field edits create a new draft version. Publishing that draft does not change old requests.

Archive a type to stop new submissions. Existing drafts and submitted requests keep working on the version they already have.

The built-in General Request form is not edited here. You can still assign its workflow from **Built-in forms** on the same screen.

## Workflow assignment

The **Workflow template** control on a request type is the fallback routing rule for new work. **Selection rules** sit above it.

Precedence, first match wins:

1. Active selection rules on this request type, lowest priority number first.
2. The assigned template, if it is active.
3. The default template.

Use **Explain routing** with sample metadata to see which rule would match. The chosen reason is stored on the workflow instance.

A rule's field is a request field such as `priority`, a form field written as `fields.amount`, or `metadata.amount`, which reads the form field `amount` on real requests just as the sample does in Explain routing. A rule on any other field is refused when you save.

- Blank assignment, or an inactive assigned template: new submissions start the default template unless a rule matches.
- In-flight requests keep the template already stored on their workflow instance.
- Document, Note, Memo, and Request are not routing keys.

Requesters never see this control. They only choose a form.

## Field types

Short text, long text, rich text, number, currency, date, date-time, choice, checkbox, user, department, URL, email, file, and calculated (read-only sum of earlier numeric fields).

Each field can have a section, instructions, placeholder, default, required flag, limits, allowed file types, and help text.

Type a label for each field. If you later rename the field, visibility rules and reports that already use it stay attached. Conditional and calculated-field pickers show those labels.

Visibility and required-when rules may only reference an earlier field, and only with equals, not equals, in, and not empty.

## Dates and defaults

- **Date and date-time limits:** date fields show two calendar pickers — **Earliest date requesters can choose** and **Latest date requesters can choose**. Click a date instead of typing it; the bounds are inclusive, so a latest date of 2026-12-31 accepts that day. While you set limits, the builder echoes the window back in plain words: *Requesters can pick dates from 1 Sep 2026 up to 31 Dec 2026*. Submissions outside the window are rejected when saving or submitting. Number and currency fields keep plain Minimum value / Maximum value boxes.
- **Limits must be real dates.** Typing a bare number (like 1 or 2) into a date limit is rejected with a clear message when saving or publishing, and the Draft checks panel warns while you build — numbers are not dates.
- **Choice defaults:** a choice field's default value must be one of its options. Publishing rejects a default like *Unpaid* when the options are only *Annual* and *Sick*.
- **Earliest must not come after latest** on date fields; saving and publishing reject an inverted range.

## Preview and draft checks

Open **Preview form** at the bottom of the builder to try conditional rules before publishing. Enter sample values in the preview: fields appear and disappear exactly as requesters will see them, and a field required by a rule is marked while the rule matches.

The **Draft checks** panel under it lists problems while you build — a choice field without options, an inverted date range, a default outside its options, a condition pointing at a missing earlier field, or a calculated field with nothing to sum. Draft checks are warnings; they do not block drafting. Saving and publishing still run the full server-side validation, which is the authority.

## Browser draft protection

While the builder is open, your edits are autosaved in this browser only (about one second after you stop typing). If the tab closes before you save, reopening the same form shows **Restore draft** with the time it was captured; discard it if you would rather start fresh. Saving or publishing on the server clears the browser copy automatically — the server draft, not the browser copy, is the record other administrators see.

## Search and reporting flags

Mark a field searchable, filterable, reportable, sensitive, or approver-summary. Those flags freeze with the published version.

Sensitive values are hidden from unauthorized search, reports, list columns, and approver detail. Owners and administrators with the sensitive-fields ability can still see them. Opening a sensitive value is recorded on **Settings → Security & privacy**. Exports and evidence packs replace a value the downloader is not allowed to see.

## General Request

Every site has a built-in General Request form with title, body, priority, department, due date, and a Document / Note / Memo / Request field. Treat General Request as the fallback form. Clone it or add a new type when you need a different shape. Assign its workflow from Built-in forms; do not expect that Document/Note/Memo/Request field to select a template.
