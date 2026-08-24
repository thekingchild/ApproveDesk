# Updates

Updates is the administrator screen for this copy’s version and for applying waiting database changes after a new copy of ApproveDesk is deployed.

Open **Administration → Updates**.

## When to use it

Use this screen after files have already been replaced by your usual deploy. It does not download a new copy and it is not the first-time setup wizard.

## How the live screens work

The card shows the running version and when database updates were last applied from this screen.

**Check updates** lists waiting database changes and says whether they look safe. Unsafe changes (dropping or renaming records) are refused.

**Apply database updates** asks you to type **APPLY**. It applies waiting database changes only. It cannot undo a change. Take a host backup first.

After first-time setup, the setup wizard is gone. Opening that address shows a not-found page. A one-time “ApproveDesk is ready” message appears on **Log in** immediately after setup.

## What not to do

- Do not use this screen instead of deploying application files.
- Do not apply updates without a host backup you can restore.
- Do not expect Apply to roll a change back. Forward-only corrections are a new update.
