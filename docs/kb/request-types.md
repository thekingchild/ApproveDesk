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

The **Workflow template** control on a request type is the routing rule for new work.

- Assigned and active: new submissions start that template.
- Blank, or assigned to an inactive template: new submissions start the default template.
- In-flight requests keep the template already stored on their workflow instance.

Requesters never see this control. They only choose a form.

## Field types

Short text, long text, rich text, number, currency, date, date-time, choice, checkbox, user, department, URL, email, file, and calculated (read-only sum of earlier numeric fields).

Each field can have a section, instructions, placeholder, default, required flag, limits, allowed file types, and help text.

Visibility and required-when rules may only reference an earlier field, and only with equals, not equals, in, and not empty.

## Search and reporting flags

Mark a field searchable, filterable, reportable, sensitive, or approver-summary. Those flags freeze with the published version.

Sensitive values are hidden from unauthorized search, reports, list columns, and approver detail. Owners and administrators with the sensitive-fields ability can still see them.

## General Request

Every site has a built-in General Request form with title, body, priority, department, due date, and a Document / Note / Memo / Request field. Treat General Request as the fallback form. Clone it or add a new type when you need a different shape. Assign its workflow from Built-in forms; do not expect that Document/Note/Memo/Request field to select a template.
