# Multi-store and Super Admin release

This release introduces store workspaces beneath the existing companies. Super Admin is **web-only**. The Android app continues to accept managers and employees; owners and Super Admin use the web. No production deployment, production migration or Railway service change was performed during implementation.

## Roles and boundaries

- `super_admin`: `/super-admin`, store creation, owner assignment and store activation. Cannot use task, evidence, report or owner-policy tools, impersonate staff, or sign into the mobile API.
- `admin`: the existing Store Owner role. Manages authorised stores, their team, tasks, settings and the selected company's policy. An owner may own multiple stores.
- `manager` and `employee`: existing scoped task workflows remain available on web and mobile. Manager employee scope is separate in each store. A manager can still receive and complete personal assignments.

Active `store_user` memberships determine access. A user's historical `company_id` is not a workspace selector. Users keep their existing global role; membership does not promote them. Owners can add an existing manager or employee from a store they already manage through **People → Add an existing team member**. This does not reveal users from unrelated stores. Shared identity/password changes require the owner to be authorised for all stores on that account; deactivation normally revokes only the selected store's membership. Password changes revoke all sessions/tokens as before.

Tasks, templates, categories available for assignment, reminder settings, manager scope, reports, task notifications and private evidence are store-scoped. Categories use a shared label dictionary with separate store availability links; there is no global category-editing endpoint. Old labels are linked to each existing default store to preserve the previous global taxonomy. File downloads still authorise through the task and never use public evidence URLs. Platform audits do not appear in owner audit feeds.

Policies remain **company-wide**. Publishing from any store in a company requires that company's affected managers/employees to acknowledge the version, including staff in its other stores. Switching between stores in that company preserves the acknowledgement. Switching to an unrelated company checks its policy independently. The owner's overview lists active staff in the selected store. Previous versions and acknowledgements remain unchanged.

## Switching stores

On web, use **Switch store** in the header. In Android, use **Settings → Store → Switch store**. Only authorised memberships appear. A mandatory policy/unavailable-store screen also allows switching and sign-out. An inactive store's data is retained, but normal reads/mutations, reminders, generation and queued delivery stop until reactivation.

Every authenticated request revalidates membership and store activity. Web sessions and mobile personal access tokens bind the selected store server-side. Web forms and mobile requests carry their originating store; stale requests return `STORE_CONTEXT_CHANGED` (409). Denied memberships and inactive stores return `STORE_ACCESS_REQUIRED` / `STORE_INACTIVE` (403). JSON errors are private and not cached. The mobile app refreshes its profile, clears prior-store screens, notes, evidence cache and camera recovery, and discards late responses from the previous workspace.

Push registration follows the mobile token's selected store. Switching deletes its old device registration and the app registers again after the policy gate succeeds. Queued delivery rechecks the recipient's membership, task permissions and device/token store; policy alerts match the company. A phone receives task pushes for its selected store. An old notification tap cannot open another store's task. Firebase project, app ID and signing identity are unchanged. Provider receipt still requires a real phone test.

## Migration and data preservation

New migration: `2026_10_16_000001_add_store_workspaces`.

It creates one active default store per existing company, preserves existing owners as owners, and links existing users and task/template records to their company's default store. It preserves original account/password fields, tasks, submissions, evidence paths, policies, acknowledgements, sessions and token values. Existing token/device records are bound to the default store. It copies valid manager scopes and settings to store-specific tables, while retaining the original `manager_employee` and `settings` tables unchanged for history. It does not seed data, recreate tables holding business records or promote any owner to Super Admin.

**Use a coordinated maintenance window.** Older web/worker/scheduler processes must stop writing before the migration copies scopes/settings. Those legacy tables are not maintained by the new implementation. Do not run old and new application writers concurrently during this upgrade.

Before an authorised release:

1. Back up PostgreSQL and the private evidence volume and verify restoration, following [BACKUPS.md](BACKUPS.md). Preserve `APP_KEY` and all existing service/database/volume references.
2. Inspect pending migrations on the actual database with `php artisan migrate:status`. Review earlier pending migrations too, especially the completion/review data conversion. A health check does not verify migration completion.
3. In a read-only database console, check `SELECT COUNT(*) FROM users WHERE company_id IS NULL;`. It must be zero before this first store migration. The migration stops before schema changes if an existing account has no company; investigate its correct company rather than assigning it arbitrarily. A newly created Super Admin after this migration may legitimately have no home company.
4. Pause traffic and stop/drain the old worker and scheduler. Take the final backup after writers stop. Keep mobile clients unable to mutate during the transition.
5. Deploy the reviewed revision through a controlled web **pre-deploy** step, with the maintenance window still in place:

   ```sh
   php artisan migrate --force
   ```

   Alternatively, run that command once in the new revision's Railway container shell while all old writers are stopped. Do not run both approaches concurrently. The Docker build does not run migrations. Do not use fresh/refresh, database resets or production seeding.

6. Verify `php artisan migrate:status`, review migration logs, then start web, worker and scheduler on the **same revision**. Keep one active worker and scheduler as described in [DEPLOYMENT.md](DEPLOYMENT.md), with `PROCESS_ROLE=web`, `worker` and `scheduler` respectively. Preserve the database queue, scheduler, CORS, SMTP and Firebase secrets. No new services or databases are needed.
7. Verify names, owner/store memberships, manager scope, private old evidence, task history, reports and an existing mobile session. Test deactivate/reactivate, company policy and store switching, then restore client traffic. Check `queue:failed` and scheduled generation/reminders.

The migration intentionally refuses `down()` to protect store memberships/history. An incident requires a reviewed forward correction or a coordinated application/database/evidence backup restoration. Returning to old code alone would use stale scopes/settings.

## Create the first Super Admin

After applying the migration, use the **running web service's shell** (Railway dashboard shell or `railway ssh --service <your-web-service-name>`). Create a separate operator account so the existing store owner retains store access:

```sh
php artisan tasksure:super-admin platform@example.com "Platform administrator"
```

Replace the example identity. The command asks for a password and confirmation without echoing them; use at least 12 characters, mixed case and a number. No default password is shipped. Sign into the existing HTTPS `/login`; this role goes to `/super-admin`.

An existing **owner** may be promoted only explicitly:

```sh
php artisan tasksure:super-admin existing-owner@example.com --promote
```

The command requires confirmation, preserves that account's password, revokes sessions/tokens and clears its assigned-owner references. It loses normal store-task access. Assign replacement owners in the platform dashboard before resuming service. Existing managers/employees cannot be promoted with this option.

## Create a store and assign its owner

1. Sign into the web as Super Admin and choose **Create store**.
2. Enter the store name. Select an existing company to share its policy, or enter a new company name for an independent company.
3. Select an eligible active existing owner, or choose **Create a new owner** and enter their name/email. For a new owner, enter **Initial password** and **Confirm password** to enable immediate web sign-in without SMTP. Use at least 12 characters with uppercase, lowercase and a number. Passwords are hashed; they are never displayed, emailed, copied into audit data or restored after validation errors. Set the store status and save.
4. Alternatively, leave both password fields blank to send a secure password-reset/setup link when outgoing email is configured. If mail is disabled/fails, the store is saved with an honest setup notice; configure mail on the web service and use **Edit store → Send setup link**. Selecting an existing owner preserves their current password. Actual inbox delivery remains an account/provider check.
5. The owner signs into web, selects their store, then uses **Company settings**, **People** and **Store settings**. Company-name changes affect every store in that company; store-name changes affect the selected store only. Names appear in headings and PDF/Excel reports. TaskSure branding remains unchanged.

Owners cannot create additional owner or platform roles through People. Existing installations with more than one owner in their original company preserve their existing authorised default-store access; the assigned primary owner is shown separately. Super Admin can change the primary assignment; the previous primary owner's membership to that store is removed, while task/history actor references remain intact.

### Set a password for an owner already created

As Super Admin, open **Edit store → Set owner password**, check the displayed owner name/email, enter and confirm the new password, then press **Set owner password**. This is a separate action from saving a store assignment. If you change the assigned owner, save the store first and reload before setting their password; a stale form is rejected if the assignment changed meanwhile.

The password applies to that owner's account across all authorised stores. It revokes their existing database-backed web sessions, remembered sign-ins, API tokens and pending password-setup/reset links. It preserves their role, identity, memberships, store assignments, client users, tasks and history. Inactive owners must be handled through the existing account/assignment process; this action does not reactivate or promote anyone. Only Super Admin can use it. The audit records the actor, target owner, store/company and action, without passwords or hashes.

The manual-password update is web-only. It introduces **no database migration and no Android rebuild**. Deploy the reviewed web source/assets through the usual authorised Railway release; if the earlier multi-store migration is still pending, follow its coordinated release procedure above. No production accounts are changed by deploying the form: passwords change only when Super Admin explicitly submits one of these actions.

Recurring generation retains the existing creator-permission check. Transferring a store away from an owner stops future generation for series created by that now-unauthorised owner; existing occurrences and history remain. Review and replace those schedules under the new owner's account before relying on future dates. Manager-created series continue when their creator and assignee remain authorised in that store.

## Android update on Windows

Deploy and verify the backend first. From the updated repository on your computer:

```powershell
cd mobile
npm ci
npm test
npm run build
npx cap sync android
npm run android
```

Use Node 24, JDK 21, Android SDK 36 and Android Studio. Retain `mobile/android/app/google-services.json`, your HTTPS `VITE_API_URL` and the client's signing keystore. Build a **signed** APK/AAB using the same application ID `za.co.tasksure.employee` and signing key as the installed application. This update sets `versionCode 6` / `versionName 1.5`; increase the code if the phone already has a higher version. Install the signed update without uninstalling the working app.

The frontend remains bundled in `dist`; no Capacitor `server.url` is introduced. Super Admin has no mobile screens and its API sign-in/token use is rejected. Earlier manager/employee APKs retain their default token-bound store but have no store selector. Rebuild for switching and clear inactive-store handling; test rollout with an existing account before distributing it.

On physical phones, test two-store switching, correct company policy, camera capture/resume after switching, Android back/resume/offline handling, logout, and foreground/background push receipt for the selected store. Browser preview tests verify the bundled interface and API, not native Android permission/device behaviour. Railway runtime/migration status, real SMTP inbox delivery, client signing and Firebase receipt require external access and were not changed automatically.
