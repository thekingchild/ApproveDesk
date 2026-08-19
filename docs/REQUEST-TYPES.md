# Request type data model

Phase 6 introduces versioned request schemas without removing the fixed submission columns used by v2.x clients.

## Version ownership

- `request_types` stores the stable identity and lifecycle flags for a request type.
- `request_type_versions` stores numbered schemas. A submission always references one exact version through the non-null `submissions.request_type_version_id` foreign key.
- `request_field_definitions` belongs to one version and uses `App\Enums\RequestFieldType` for portable string-backed field types.
- Published versions and their field definitions are immutable. Changes must be made in a new draft version and published through `PublishRequestTypeVersionAction`.

Publication records a SHA-256 hash of the ordered canonical field schema. Direct model updates, field additions, field edits, field deletions, and version deletion are rejected after publication.

## Typed values

`submission_field_values` stores one row per submitted field. Exactly one value column must be populated, and the column must match the field type:

| Value column | Field families |
|---|---|
| `string_value` | short text, select, radio, URL, email |
| `text_value` | long text, rich text |
| `decimal_value` | number, currency, calculated |
| `date_value` | date |
| `datetime_value` | date-time |
| `boolean_value` | checkbox |
| `user_value_id` | user |
| `department_value_id` | department |
| `json_value` | multi-select, file, structured JSON |

The scalar columns are indexed with their field-definition ID where the database supports useful scalar filtering. Application validation also rejects a value whose field belongs to a different request-type version than its submission.

## General Request compatibility

The migration creates an immutable built-in `general-request` version containing the legacy category, title, body, priority, department, and due-date fields. It assigns that version to every existing submission and copies non-null legacy values into typed rows. Reference numbers, workflow instances, and append-only workflow actions are not changed.

New and edited fixed-form submissions continue writing the legacy columns for API and UI compatibility while `SyncLegacySubmissionFieldValuesAction` keeps the General Request typed rows synchronized. Future form-builder phases can therefore read typed values without changing historical submissions.

## Form builder and validation

Administrators manage custom forms at `/admin/request-types`. A builder draft supports sections, instructions, defaults, help and placeholder text, scalar limits, choice options, file extensions, accessible ordering controls plus drag sorting, duplication, preview, cloning, publication, and archival. Choice options can be imported from or exported to a one-column CSV file.

`RequestSchemaCompiler` is the authoritative server-side compiler. It validates every submitted value by field type and evaluates only the allow-listed `equals`, `not_equals`, `in`, and `not_empty` condition operators. Conditions can reference only an earlier field, preventing cycles and arbitrary PHP or JavaScript execution. Calculated fields are read-only and currently support a constrained sum of earlier numeric fields.

Editing a published request type saves into a new numbered draft. Publishing validates the complete schema and freezes that version. Existing submissions continue rendering against their original version. Archiving prevents new requests without affecting saved or submitted records.

Requesters choose an active published type from `/submissions/new`. Custom drafts retain their schema version and typed values when reopened. The review step and submission detail page provide accessible definition-list summaries, and the detail view includes a print action.
