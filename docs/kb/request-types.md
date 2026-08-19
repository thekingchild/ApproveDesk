# Request types

Open **Administration → Request types** to build the forms people choose at **New submission**.

## Lifecycle

1. Create a request type and add fields in a draft.
2. Preview, reorder, and duplicate fields. Import choice options from a one-column CSV if needed.
3. Publish. Publishing freezes that version and records a schema hash.
4. Later edits create a new draft version. Publishing that draft does not change old requests.

Archive a type to stop new submissions. Existing drafts and submitted requests keep working on the version they already have.

## Field types

Short text, long text, rich text, number, currency, date, date-time, choice, checkbox, user, department, URL, email, file, and calculated (read-only sum of earlier numeric fields).

Each field can have a section, instructions, placeholder, default, required flag, limits, allowed file types, and help text.

Visibility and required-when rules may only reference an earlier field, and only with equals, not equals, in, and not empty.

## Search and reporting flags

Mark a field searchable, filterable, reportable, sensitive, or approver-summary. Those flags freeze with the published version.

Sensitive values are hidden from unauthorized search, reports, list columns, and approver detail. Owners and administrators with the sensitive-fields ability can still see them.

## General Request

Every site has a built-in General Request form. Treat it as the fallback, not as a form you rewrite in place. Clone it or add a new type when you need a different shape.
