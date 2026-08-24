# Operations

Operations is the administrator screen for whether scheduled work is running, which deliveries failed, which requests look stuck, and whether this copy still has its required records.

Open **Administration → Operations**.

## When to use it

Use this screen when notices or integrations stop arriving, a request sits on a step too long, or you need to confirm a backup still contains the records ApproveDesk expects.

## How the live screens work

### Health

The cards show the last scheduler run, how much work is waiting, failed queue jobs, stuck requests, webhook endpoints with a failed delivery, and enabled connectors that last reported an error.

A fresh scheduler run means the minute job that drains waiting work is still firing. A stale or missing run means that job has not recorded a heartbeat recently.

### Failed deliveries

Failed webhooks, integration actions, notices, and queue jobs appear in one list. **Replay** sends that same item again. It does not create a second record and does not change the approval.

A delivered item cannot be replayed from here.

### Stuck requests

A request is listed when it is still active and has no current step, or the current step has waited more than a day. Open the reference to read the request. Operations does not decide or escalate for you.

### Backup check

**Check this copy** confirms the required records are still present. It does not take a host backup and it does not overwrite anything. A failed check means this copy is incomplete — restore from the host backup using your usual restore procedure, then check again.

### Consistency check

**Run check** looks for submitted requests with no workflow, or deliveries marked sent that still have a retry time. The check only reports. It does not change records.

## What not to do

- Do not treat Replay as a way to approve or reject a request.
- Do not ignore a stale scheduler card — waiting work will pile up until the minute job runs again.
- Do not use **Check this copy** as a substitute for the host backup.
- Do not delete failed deliveries to “clean up”; replay or leave them for the record.
