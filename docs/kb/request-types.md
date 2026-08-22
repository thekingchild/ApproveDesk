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
4. Publish. Publishing freezes that field version and records a schema hash.
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

- Blank assignment, or an inactive assigned template: new submissions start the default template unless a rule matches.
- In-flight requests keep the template already stored on their workflow instance.
- Document, Note, Memo, and Request are not routing keys.

Requesters never see this control. They only choose a form.

## Field types

Short text, long text, rich text, number, currency, date, date-time, choice, checkbox, user, department, URL, email, file, and calculated (read-only sum of earlier numeric fields).

Each field can have a section, instructions, placeholder, default, required flag, limits, allowed file types, and help text.

The builder asks for a label, not a machine name. ApproveDesk generates a hidden field key from that label (`Leave reason` becomes `leave_reason`) and keeps it unique in the version. Existing fields keep their key if you later change the label, so visibility rules you already set stay attached. Conditional and calculated-field pickers show labels; the stored rules still use keys.

Visibility and required-when rules may only reference an earlier field, and only with equals, not equals, in, and not empty.

## Dates and defaults

- **Date and date-time limits:** the Minimum and Maximum boxes on a date or date-time field set the earliest and latest accepted dates. The bounds are inclusive — a maximum of 2026-12-31 accepts that day. Submissions outside the window are rejected when saving or submitting.
- **Choice defaults:** a choice field's default value must be one of its options. Publishing rejects a default like *Unpaid* when the options are only *Annual* and *Sick*.
- **Earliest must not come after latest** on date fields; publishing rejects an inverted range.

## Preview and draft checks

Open **Preview form** at the bottom of the builder to try conditional rules before publishing. Enter sample values in the preview: fields appear and disappear exactly as requesters will see them, and a field required by a rule is marked while the rule matches.

The **Draft checks** panel under it lists problems while you build — a choice field without options, an inverted date range, a default outside its options, a condition pointing at a missing earlier field, or a calculated field with nothing to sum. Draft checks are warnings; they do not block drafting. Saving and publishing still run the full server-side validation, which is the authority.

## Browser draft protection

While the builder is open, your edits are autosaved in this browser only (about one second after you stop typing). If the tab closes before you save, reopening the same form shows **Restore draft** with the time it was captured; discard it if you would rather start fresh. Saving or publishing on the server clears the browser copy automatically — the server draft, not the browser copy, is the record other administrators see.

## Search and reporting flags

Mark a field searchable, filterable, reportable, sensitive, or approver-summary. Those flags freeze with the published version.

Sensitive values are hidden from unauthorized search, reports, list columns, and approver detail. Owners and administrators with the sensitive-fields ability can still see them.

## General Request

Every site has a built-in General Request form with title, body, priority, department, due date, and a Document / Note / Memo / Request field. Treat General Request as the fallback form. Clone it or add a new type when you need a different shape. Assign its workflow from Built-in forms; do not expect that Document/Note/Memo/Request field to select a template.
