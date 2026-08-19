-- ApproveDesk v2.1 in-flight fixture: a two-step chain still waiting on step 1.
INSERT INTO users (id, name, email, password, is_active, created_at, updated_at)
VALUES
    (9101, 'V2.1 Requester', 'v21-requester@example.test', 'fixture-password', 1, '2026-07-01 08:00:00', '2026-07-01 08:00:00'),
    (9102, 'V2.1 First Approver', 'v21-first@example.test', 'fixture-password', 1, '2026-07-01 08:00:00', '2026-07-01 08:00:00'),
    (9103, 'V2.1 Second Approver', 'v21-second@example.test', 'fixture-password', 1, '2026-07-01 08:00:00', '2026-07-01 08:00:00');

INSERT INTO workflow_templates (id, name, description, submission_types, is_default, is_active, created_by, created_at, updated_at)
VALUES (9201, 'V2.1 Two-Level', 'In-flight fixture workflow', '["request"]', 1, 1, 9102, '2026-07-01 08:00:00', '2026-07-01 08:00:00');

INSERT INTO workflow_template_steps (id, template_id, step_order, name, step_type, quorum_count, assignee_type, assignee_ids, conditions, timeout_hours, timeout_action, allow_delegation, created_at, updated_at)
VALUES
    (9301, 9201, 1, 'Manager review', 'sequential', NULL, 'user', '[9102]', NULL, NULL, NULL, 1, '2026-07-01 08:00:00', '2026-07-01 08:00:00'),
    (9302, 9201, 2, 'Director review', 'sequential', NULL, 'user', '[9103]', NULL, NULL, NULL, 1, '2026-07-01 08:00:00', '2026-07-01 08:00:00');

INSERT INTO submissions (id, reference_number, type, title, body, submitter_id, department_id, priority, due_date, status, metadata, created_at, updated_at, deleted_at)
VALUES (9401, 'REQ-V21-OPEN', 'request', 'V2.1 in-flight purchase', '<p>Still waiting.</p>', 9101, NULL, 'high', '2026-07-10', 'in_review', '{"fixture":"v2.1-inflight"}', '2026-07-01 09:00:00', '2026-07-01 09:00:00', NULL);

INSERT INTO workflow_instances (id, submission_id, template_id, current_step_id, status, started_at, step_started_at, completed_at, created_at, updated_at)
VALUES (9501, 9401, 9201, 9301, 'active', '2026-07-01 09:00:00', '2026-07-01 09:00:00', NULL, '2026-07-01 09:00:00', '2026-07-01 09:00:00');
