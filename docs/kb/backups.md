# Backups

Backups is the administrator screen for taking **separate** database and file snapshots, sending them to a destination, checking them, and promoting a checked snapshot back onto this copy.

Open **Administration → Backups**. Host backups from your hosting panel stay recommended. These snapshots complement them.

## When to use it

Use this screen after a deploy, before a risky change, or when you need a copy of the database or of uploaded files you can download or put back.

## How the live screens work

### Two kinds

**Backup database** copies the records. **Backup files** copies uploaded request files and branding artwork. They never share one archive. You can restore one kind without the other.

### Destinations

The local disk is always used. **S3-compatible** and **Google Drive** are separate cards. A remote destination that is missing its secret is skipped and does not fail the local copy. Secrets are not shown again.

Each snapshot shows its size. The list also totals **Database** and **Files** so you can see how large the archives are.

### Remove a local copy

When a snapshot already has a remote replica, **Remove local** deletes the file on this server and keeps the S3 or Drive copy. **Remove local copies (keep remotes)** does that for every eligible snapshot. You cannot remove the only copy.

Verify, Download, and Promote still work: ApproveDesk fetches the remote file when the local file is gone.

### Schedule and retain

Database copies default to 14 days at 02:30. File copies default to 7 days at 02:45. Each kind can be turned off. You can keep 3–90 days. The last successful copy of each kind is never removed.

### Verify and promote

**Verify** checks a snapshot without changing this copy. **Promote** puts a verified snapshot back. Type the snapshot number to confirm. Promoting a database snapshot replaces this copy’s database. Promoting files writes into a holding folder, then swaps. A snapshot that fails the check cannot be promoted.

**Check this copy** on Operations is not a snapshot. It only confirms the live records are still present.

## What not to do

- Do not treat in-app snapshots as a substitute for the host backup.
- Do not promote a snapshot you have not verified.
- Do not mix database and file archives yourself — the product keeps them separate.
- Do not use this screen to change an approval. Promote does not run the workflow.
