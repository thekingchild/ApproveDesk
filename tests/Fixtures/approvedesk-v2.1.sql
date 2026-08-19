-- ApproveDesk v2.1 fixture: one resolved legacy request with immutable workflow history.
INSERT INTO users (id, name, email, password, is_active, created_at, updated_at)
VALUES
    (9101, 'V2.1 Requester', 'v21-requester@example.test', 'fixture-password', 1, '2026-07-01 08:00:00', '2026-07-01 08:00:00'),
    (9102, 'V2.1 Approver', 'v21-approver@example.test', 'fixture-password', 1, '2026-07-01 08:00:00', '2026-07-01 08:00:00');

INSERT INTO workflow_templates (id, name, description, submission_types, is_default, is_active, created_by, created_at, updated_at)
VALUES (9201, 'V2.1 Approval', 'Fixture workflow', '["request"]', 1, 1, 9102, '2026-07-01 08:00:00', '2026-07-01 08:00:00');

INSERT INTO workflow_template_steps (id, template_id, step_order, name, step_type, quorum_count, assignee_type, assignee_ids, conditions, timeout_hours, timeout_action, allow_delegation, created_at, updated_at)
VALUES (9301, 9201, 1, 'Legacy approval', 'sequential', NULL, 'user', '[9102]', NULL, 24, 'escalate', 1, '2026-07-01 08:00:00', '2026-07-01 08:00:00');

INSERT INTO submissions (id, reference_number, type, title, body, submitter_id, department_id, priority, due_date, status, metadata, created_at, updated_at, deleted_at)
VALUES (9401, 'REQ-V21-0001', 'request', 'V2.1 fixture purchase', '<p>Preserve the fixture body.</p>', 9101, NULL, 'high', '2026-07-10', 'approved', '{"fixture":"v2.1"}', '2026-07-01 09:00:00', '2026-07-01 10:00:00', NULL);

INSERT INTO workflow_instances (id, submission_id, template_id, current_step_id, status, started_at, step_started_at, completed_at, created_at, updated_at)
VALUES (9501, 9401, 9201, NULL, 'completed', '2026-07-01 09:00:00', '2026-07-01 09:00:00', '2026-07-01 10:00:00', '2026-07-01 09:00:00', '2026-07-01 10:00:00');

INSERT INTO workflow_actions (id, instance_id, step_id, actor_id, action, comment, delegated_to, ip_address, user_agent, created_at)
VALUES (9601, 9501, 9301, 9102, 'approve', 'Preserved v2.1 approval.', NULL, '127.0.0.1', 'v2.1 fixture', '2026-07-01 10:00:00');
