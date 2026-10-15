# Manager mobile access and optional task review

This release changes the existing application; it does not deploy Railway resources or run production migrations automatically. Keep the working database, evidence volume, APP_KEY, existing accounts, Firebase registration, application ID and Android signing key.

## Behaviour and permissions

- Employee and manager accounts can use mobile. Owners/admins continue using the website. No duplicate worker role is added.
- **My tasks** and the mobile calendar contain only the signed-in person's assignments. **Team tasks** contains explicitly permitted people, excluding the manager's own assignments. Review tasks is a separate optional management view.
- An owner can assign to any active manager/employee in this deployment. A manager can assign to themselves and active people explicitly permitted by the owner in **People → Permitted team members**. That existing scope can now include managers. Scope is direct, not inherited through another manager's team.
- The repository is one business per deployment. The subsequent [company-policy feature](COMPANY-POLICY.md) adds an internal company reference for accounts and policy history, with no business switching or tenancy administration. Do not connect unrelated clients to this same database.
- Employees cannot assign, reschedule, reassign, cancel, review or reopen tasks. Managers cannot perform work as another assignee or review/reopen their own completed work; an owner or another explicitly authorised manager reviews that work. Backend policies enforce these rules.
- Assigned → In progress → Completed. Existing direct completion from Assigned remains supported when all required evidence is supplied. Completion records the server time and actor, an immutable attempt/deadline and fresh evidence, immediately. Review never blocks completion.
- Mark as reviewed records the reviewer/time and keeps Completed unchanged. Repeating this action does not overwrite the first review. Reopen task requires a correction note, returns work to Assigned and clears the current completion/review fields. Previous attempts, files, review events and original deadlines remain available. A new completion needs fresh required evidence.
- Existing Cancelled tasks stay Cancelled. Completed and Cancelled tasks do not generate overdue reminders. Reports separate current Completed, Reviewed and Not reviewed counts; review time does not affect punctuality.

## Forward-only migration

`2026_10_14_000001_separate_completion_from_review.php` adds current completion/review metadata, per-attempt start time and an append-only review event table. It does not delete users, tasks, evidence, attempts, old approval fields, alerts or activity records. It sends no historical push notifications.

| Existing status | Result |
|---|---|
| Assigned / In progress | Preserved |
| Submitted | Completed, Not reviewed; known latest attempt supplies completion time and actor |
| Approved | Completed; Reviewed when a historical reviewer or review/approval timestamp exists |
| Changes requested | Assigned; old corrections, notes, evidence and review metadata retained |
| Cancelled | Preserved with its reason |
| Unknown custom status | Stop before schema changes; inspect and agree an explicit mapping |

Missing completion times, completion actors, reviewers and review timestamps remain NULL and display as unknown/not recorded. A known historical approval time may indicate Reviewed with an unknown reviewer. An Approved record with no historical review details becomes Completed / Not reviewed. Original `approved_at`, submission metadata and task `updated_at` are preserved. Migration activities identify the previous status and any missing completion details. Older approval events remain labelled as historical, not as active actions.

The migration is intentionally forward-only: running `migrate:rollback` cannot reconstruct previous live states after new completions. A coordinated rollback requires a verified pre-release backup and matching application release. Never use resets, `migrate:fresh`, `migrate:refresh` or production seeders.

## Controlled Railway upgrade — operator action, not executed here

1. Review the diff and all pending migrations. Disable automatic production deployment until the coordinated release is approved. Back up **PostgreSQL and private evidence**, and verify the backup can be restored separately; see [BACKUPS.md](BACKUPS.md). Preserve the original APP_KEY securely.
2. Schedule a maintenance window. Stop the existing worker and scheduler through Railway and put the running web service into maintenance mode using its actual service name:

   ```sh
   railway ssh --service web
   php artisan down --retry=60
   php artisan migrate:status
   exit
   ```

   Freeze writes before the final consistent backup. Do not allow an old application replica to write legacy task statuses during or after conversion. Confirm web remains at one replica and avoid any automatic restart bringing the old release back during the window.
3. Set **web → Settings → Pre-Deploy Command** to exactly:

   ```sh
   php artisan migrate --force
   ```

   Remove any production `db:seed` command. Keep migrations out of Docker image builds and off worker/scheduler startup. The repository IaC now uses this same migration-only pre-deploy command; no IaC apply is required for an existing Railway project.
4. Deploy the approved backend release on web during the window. The pre-deploy command applies pending forward migrations against the existing PostgreSQL service. Check its exit status and deployment logs; a failed migration is a failed release, not a reason to reset data. Do not deploy this release without its migration or restart old code against the converted database.
5. Verify the new running web container before restarting background services:

   ```sh
   railway ssh --service web
   php artisan migrate:status
   php artisan up
   exit
   ```

   Check `/health`, existing account login, old evidence, manager scopes, task assignment, completion, optional review, reopening and report exports. If maintenance remains active in a shared cache/file configuration, `up` clears it on the new release.
6. Deploy the **same release** to worker and scheduler, preserving their PostgreSQL, APP_KEY and Firebase/push configuration. Start one replica of each. Verify queue logs, `php artisan queue:failed`, reminders and a real push to both an employee and a manager. Do not retry all old failed jobs blindly; inspect obsolete jobs first.
7. Distribute the rebuilt Android app after the backend is healthy. Existing employee tokens remain accepted. Old APK `submit` actions are temporarily accepted as **immediate completion**; old approval actions are rejected. Old APK wording/status filters are obsolete and require the rebuild. Manager mobile screens require the new APK.

The live Railway deployment, its logs, production backup/restore and physical-phone push delivery require access to the operator's accounts/devices. Local and mocked tests do not establish those results.

## Android rebuild on Windows

From the current source checkout, after the code has been delivered to your computer:

```powershell
cd mobile
npm ci
npm test
npm run build
npx cap sync android
npm run android
```

Preserve your existing `mobile/.env` and ensure its `VITE_API_URL` targets the intended Railway `/api/mobile/v1` endpoint. Do not replace it with an example or put backend secrets in VITE variables. Keep the matching `mobile/android/app/google-services.json`; it is intentionally ignored and must stay local.

The application ID remains **za.co.tasksure.employee**. This completion/review release used **versionCode 2 / versionName 1.1**; the subsequent company-policy update uses **versionCode 3 / versionName 1.2**. If a higher version is already installed/published, choose a versionCode greater than that. In Android Studio use **Build → Generate Signed App Bundle or APK → APK → release** with the **same signing keystore** used for the installed client app. Install the update without uninstalling the existing app. Use an AAB instead if using an approved Google Play distribution workflow.

Test manager and employee login, their personal lists/calendar, permitted manager/employee assignment, camera upload, immediate completion, optional review, correction/recompletion, logout and foreground/background push on a real phone. A debug build compilation does not validate a signed release, store publication or native Firebase delivery.
