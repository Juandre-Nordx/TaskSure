# Super Admin activity logs

Open **Super Admin → Activity logs** on the web. The existing Stores page has a second tab, and the platform sidebar links to it. The table is read-only and paginated at 25 events per page. **View details** shows safe before/after values, actor attribution and event metadata.

The existing platform Super Admin role can inspect all store workspaces, including inactive stores. Owners, managers and employees cannot access this page or its detail routes. No mobile Super Admin screen or activity-log API is exposed. Existing store/task permissions are unchanged.

## Recorded actions

| Area | Events |
| --- | --- |
| Accounts | Web/mobile login success and failure (including validation, role rejection and throttling), logout, supported account creation, account/store-access edits and deactivation/reactivation, manager scope changes, manual password-change marker without values, Super Admin setup/promotion. |
| Stores/settings | Store creation/edits, owner assignment/removal, company/store names, categories and reminder settings. |
| Tasks | Creation with initial assignment, reassignment, relevant metadata edits, deadline changes, start, completion, optional review, reopening, cancellation, comments/blockers as content-free markers and recurring schedule changes. Scheduler-created occurrences are identified as automated actions. |
| Evidence | Photo/document uploads with file metadata and evidence-record deletion through the model hook. Existing evidence/history protections stay in place; this feature adds no destructive delete interface or permission. Cleanup of a failed, uncommitted upload is not labelled as a successful user deletion. |
| Policies | Draft saves/PDF uploads, immutable version publication, authenticated PDF view/download responses and exact-version acknowledgement. Repeated acknowledgement/review requests do not create duplicate successful state-change events. |
| Reports | Filtered web report generation, PDF exports and completed Excel generation, with permitted filters and selected totals. |

Web and Android/iOS use the same Laravel actions/services, so existing installed apps produce server-side events without a rebuild. Ordinary navigation, task/calendar polling, asset requests, notification reads and viewing the activity list do not generate activity entries.

Each event stores a server UTC timestamp, actor identifier/name/email/role snapshot, company/store identifiers and names, action/category, affected record, source and success/failure. Views and timestamp fields in details use Africa/Johannesburg (SAST). A failed operation can have no before/after values because nothing changed. A completed state change and a later request failure may be separate facts; the log does not claim a response reached a phone.

**Identity matters:** an authenticated action retains its actor snapshot after renaming, deactivation or a later role change. Failed logins identify a **claimed account**, with an explicit unverified label; they are not proof that the named employee made the attempt. Unauthenticated protected requests have an unknown identity, distinct from console/scheduler actions. Client-supplied actor, role, company or timestamp fields are not used as logging authority.

## Filters

- Start/end date and time in SAST. Start is inclusive; end includes all seconds within the selected minute. Either limit may be left blank.
- Company and an authorised store from the existing platform workspace list. Clear a store selection before choosing a different company; incompatible selections are rejected.
- Acting user's name/email and actor role: Super Admin, Owner, Manager or Employee.
- Accounts, Tasks, Policies, Reports or Settings category. Evidence is under Tasks and store changes are under Settings.
- Specific action and Success / Failure.
- Search across safe event details, record labels/IDs and preserved names. Search can find the affected account as well as its actor. Pagination retains the filters.

Snapshots keep historical names even if current dropdown labels change. Imported rows with unknown roles are included in All roles, not attributed to a guessed historical role.

## Safety and history

The new `activity_logs` table is append-only through the model and has no update/delete HTTP route. Snapshot identifiers have no cascading foreign keys, so removing/deactivating an account cannot erase these events. Application auditing does not turn direct database maintenance into authenticated application activity.

Structured allowlists exclude passwords/hashes, access/refresh/push tokens, cookies, request bodies/headers, private file paths, policy/file contents, task instructions and free-form completion/comment/review notes. Changed-field markers can indicate that omitted content changed. Filenames, record titles and identity metadata remain searchable. Failures store a fixed reason/status, not exception text or SQL. If the database itself cannot record a failure, a static application error is emitted without copying the request or exception payload.

Task/account/policy records feed activity logging in their existing transactions. Rolled-back task changes do not leave successful activity entries. Source identifiers prevent duplicate import/replay entries. Successful logout, publication and confirmation redirects are not misclassified as failures.

Mobile login creates its token and successful audit event in one transaction, so an audit-storage failure cannot leave an unused login token. New platform-account setup likewise rolls back the account if auditing fails. Activity details are looked up only after Super Admin authorization, preventing other roles from probing whether a particular event exists.

Full tracking starts after the updated server processes are installed. Optional historical import reads existing **account audits, task activity rows and policy acknowledgements** only:

```sh
php artisan tasksure:import-activity-history
```

This adds supported events without updating source records or file storage. It runs in chunks and can be safely repeated; current events with the same source key are not duplicated. Original recorded timestamps and available before/after values are preserved. Missing timestamps and unsupported records are skipped. Immutable acknowledgement rows supply their known user/company/version/time. Historical actor roles remain unknown, and labels can use currently known names; every imported event is explicitly marked Historical. Past logins, downloads and other missing events are not invented. Historical import is optional and is not a migration or an automatic deployment step.

## Controlled Railway release

No production deployment or production migration was performed during development.

1. Review the branch, back up PostgreSQL and private evidence using the existing backup procedure, and inspect all pending migrations:

   ```sh
   php artisan migrate:status
   ```

2. This feature adds only `2026_10_18_000001_create_activity_logs`. It creates a new table and indexes; it does not update/delete existing users, tasks, policies, evidence or legacy audits. Review SQL before the approved migration step:

   ```sh
   php artisan migrate --pretend --path=database/migrations/2026_10_18_000001_create_activity_logs.php
   ```

3. During the operator-authorised release, apply the additive migration against the existing database:

   ```sh
   php artisan migrate --force --path=database/migrations/2026_10_18_000001_create_activity_logs.php
   php artisan migrate:status
   ```

   Railway's normal web pre-deploy `php artisan migrate --force` can perform this step after all pending migrations have been reviewed. Do not run migrations during Docker image build. Do not seed or reset production.

4. Deploy the same reviewed release to **web, worker and scheduler**. Restart long-running processes so their event hooks are loaded; normal Railway service redeployment does this. Preserve the existing PostgreSQL, APP_KEY, private `/data` volume and provider configuration. No new service, dependency or environment variable is needed. The existing web build (`npm ci`, `npm run build`) includes the tab styling.
5. **No Android rebuild, version change or reinstall is required.** The API response contract and mobile frontend/plugins are unchanged. Verify real phone actions reach the updated Railway API; local HTTP/API tests do not prove a live deployment or physical-phone behaviour.
6. Optionally run the historical import after all server processes use the release. Check a designated test account's web/native login, task/upload/completion, policy confirmation and report/PDF events. Verify Super Admin filtering/details and denied access for an owner/manager. An existing installed app should continue its normal task workflow.

The migration deliberately refuses rollback that would drop audit history. If application code must be rolled back, retain the new table and its rows. Do not run migrate:fresh, migrate:refresh, database wipes, production test suites or production seeding.
