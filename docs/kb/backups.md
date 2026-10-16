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

Each snapshot is named with the date and time it was taken, shown in a **Date and time** column, newest first. Downloads use that same stamp. Each snapshot also shows its size. The list totals **Database** and **Files** so you can see how large the archives are.

### Connect Google Drive

ApproveDesk does not create the Google sign-in for you. You create a Google app once, sign in with offline access, then paste the values on the **Google Drive** card.

1. Open [Google Cloud Console](https://console.cloud.google.com/). Create or pick a project.
2. Open **APIs & Services → Library** and turn on **Google Drive API**.
3. Open **APIs & Services → OAuth consent screen**. Choose **External** (or **Internal** if your organization uses Google Workspace). Fill in the app name and emails. Add the scope `https://www.googleapis.com/auth/drive.file`. If the app is in Testing, add your Google account as a test user.
4. Open **APIs & Services → Credentials → Create credentials → OAuth client ID**. Application type: **Desktop app**. Copy **Client ID** and **Client secret**.
5. Open [Google OAuth 2.0 Playground](https://developers.google.com/oauthplayground). Open the gear, tick **Use your own OAuth credentials**, and paste that Client ID and Client secret.
6. In the left list open **Drive API v3** and select `https://www.googleapis.com/auth/drive.file`. Choose **Authorize APIs**, sign in with the Google account that will own the backup folder, and allow access.
7. Choose **Exchange authorization code for tokens**. Copy **Refresh token** (it starts with `1//`). If you only see an access token, revoke ApproveDesk at [Google Account permissions](https://myaccount.google.com/permissions) and repeat so the consent screen appears again.
8. In Google Drive, open or create a folder such as ApproveDesk backups. The folder id is the last part of the address, after `/folders/`.

On **Administration → Backups → Google Drive**, fill **Name**, **Folder id**, **Client id**, **Client secret**, and **Refresh token**. Choose **Save Drive destination**, then **Test**.

A refresh token lets the daily schedule copy snapshots without opening a browser. If you change the client secret, get a new refresh token and save the card again.

### Remove a local copy

When a snapshot already has a remote replica, **Remove local** deletes the file on this server and keeps the S3 or Drive copy. **Remove local copies (keep remotes)** does that for every eligible snapshot. You cannot remove the only copy.

Verify, Download, and Promote still work: ApproveDesk fetches the remote file when the local file is gone.

### Schedule and retain

Database copies default to 14 days at 02:30. File copies default to 7 days at 02:45. Each kind can be turned off. You can keep 3–90 days. The last successful copy of each kind is never removed.

Choose both daily times with the time pickers. The page states the installation timezone and shows each next run. Retention cleanup remains at 04:15. **Backup now** creates a snapshot immediately but does not prove that the scheduler is running; the page separately shows the last successful scheduled database and file backups.

### Verify and promote

**Verify** checks a snapshot without changing this copy. It confirms the file is still the same one that was taken, not only that it looks like a backup. Extra or altered files in a files snapshot are refused. **Promote** puts a verified snapshot back. Type the snapshot number to confirm. Promoting a database snapshot replaces this copy’s database, including on MySQL. Copies already taken on this server can still be promoted after an update. The promoted snapshot stays in the list with its destination so you can see which copy was used. Before you confirm a database promote, ApproveDesk tells you how many workflow actions were recorded after that snapshot; they are removed from the live history when the snapshot replaces it. On MySQL and MariaDB, ApproveDesk first takes and checks a **safety backup** of the current database, keeps the site in maintenance mode while it restores, and checks the result before reopening. If the restore fails, it puts the safety backup back automatically; if even that fails, the site stays in maintenance mode so nobody works on a half-restored database, and the error names the safety backup to restore. Backups, checks, and restores read and write files in small pieces, so large databases do not run the server out of memory, and they keep running if you close the page. If the server stops a restore before the database is touched, the site comes back up on its own; if it stops part-way through loading a MySQL or MariaDB snapshot, the site stays in maintenance mode and the application log names the safety backup to restore. **Restore history** records who restored which snapshot and when, the safety backup taken, and how many later workflow actions were removed. Promoting files writes into a holding folder, then swaps. A snapshot that fails the check cannot be promoted. Host backups from your hosting panel stay recommended.

**Check this copy** on Operations is not a snapshot. It only confirms the live records are still present.

## What not to do

- Do not treat in-app snapshots as a substitute for the host backup.
- Do not promote a snapshot you have not verified.
- Do not mix database and file archives yourself — the product keeps them separate.
- Do not use this screen to change an approval. Promote does not run the workflow.
- Do not put a refresh token or client secret in a screenshot or a support ticket.
