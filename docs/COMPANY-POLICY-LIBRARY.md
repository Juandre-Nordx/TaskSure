# Multiple company policies

Owners can manage separate policies under **Company policies**. Managers and employees see every current published policy in a list on web and Android, with its version, SAST publication date and their **Acknowledged / Outstanding** status. They can also open the selected policy’s **Previous published versions** list on web and Android (1.8).

## Add a policy or update one

- **Add policy** creates a separate item, such as Workplace safety, Staff conduct or Responsible alcohol sales. Give it a title and PDF (or written content), save a draft, review it, then publish. Each policy starts at version 1.
- **Update this policy** opens the selected item's draft/current content. Uploading a replacement and saving does not block or notify staff. Publishing creates the next version of that item. The other policies and their acknowledgements remain unchanged.
- Select a policy to see its current version, version history and selected-store acknowledgement overview. Role/status filters apply to that policy's current version. Open a previous version to see its original PDF and exact-version acknowledgements, including historical staff.
- Staff use **Read company policies** on web or **Settings → Company policies → Read company policies** on mobile to reopen the list after confirmation. Select a policy, then **Previous published versions** to read its older content or PDFs and download original files. The list is paginated, newest first. Staff see only their own acknowledgement dates, including “Not acknowledged for this version” where no confirmation was recorded. They cannot access other companies or owner draft/management/tracking tools.
- Historical versions are labelled **Previous version — read only**, with a **Read current version** link. They have no confirmation checkbox; reading/downloading them does not acknowledge or replace the current version. History stays available while access is blocked, and normal features remain blocked until every current requirement is confirmed. Earlier versions that a person never acknowledged do not add new requirements.

Every outstanding published policy requires its own initially unchecked confirmation. Reading/downloading does not acknowledge anything. Staff can choose which policy to read, including previously acknowledged policies, but normal features remain blocked until all current versions are acknowledged. New staff must confirm every current policy. Sign-out and authorised store switching remain available; switching rechecks the destination company's requirements.

A changed version is checked against its own policy, not another policy's version number. If a replacement is published while a member of staff is reading, an old-version confirmation is rejected and the latest policy is returned. If a separate policy is published meanwhile, acknowledging the open policy succeeds but access remains locked for the additional policy. Repeated submissions preserve the first server timestamp. The server guard applies to direct links and protected API requests, regardless of client cache/offline state.

## Existing uploads and data migration

**Existing PDFs remain the version history of one policy per company**, as requested. They are not split into separate policies or republished. Existing IDs, version numbers, PDF paths/hashes, current version, saved draft/revision and acknowledgement timestamps are preserved. Staff who already acknowledged that current version do not have to confirm it again merely because of the upgrade.

`2026_10_17_000001_create_company_policy_library` creates `company_policies` and links existing versions through `company_policy_versions.company_policy_id`. It copies each company's previous draft/current pointer into one policy record. The original company fields remain as a legacy copy; the new service writes the policy records. The version uniqueness constraint changes from company/version to policy/version so separate policies can each have version 1. Existing unrelated companies remain isolated. No policy is published, no alert is sent, and users, credentials, tasks, submissions, evidence, sessions and tokens are not rewritten.

**Coordinate maintenance and stop old writers for this upgrade.** Old processes still use the company's legacy draft/pointer and cannot create versions with the new required policy link. Do not run mixed old/new policy-writing source after the migration. Its rollback refuses to discard history; use a reviewed forward repair rather than reverting to incompatible old application code.

PDFs reuse the private `evidence` disk and the existing Railway web volume: `UPLOAD_STORAGE_PATH=/data/uploads`, mount `/data`, one web replica. Existing validation/10 MB default, company-scoped authenticated viewing/downloads and persistent UUID filenames are retained. Exported filenames include the policy ID as well as its version to distinguish separate policies with the same version number. No extra Firebase registration, plugin, queue or scheduler job is required.

## API compatibility

`GET /api/mobile/v1/company-policy` now returns:

- `policies`: all current published versions, each with `id` (exact version), `policy_id` (parent policy), title, version, publication time, content/document, `required` and `acknowledged_at`.
- `required` and `outstanding_count`: requirements across the entire company list.
- `policy`, `selected_required` and `acknowledged_at`: the selected version, defaulting to the first outstanding current policy, otherwise the first published one. Use `?policy_id=<parent-policy-id>` to select an item. To read an older version add `&version_id=<exact-version-id>`. The version must belong to that company and parent policy. Unknown, unpublished or foreign selections return 404.
- `current_policy` and `selected_is_current`: identify the current version when an older version is selected. Historical selections always have `selected_required=false`; the global `required`/`outstanding_count` still enforce all current policies.
- `history`: `{data, meta: {page, last_page, total}}` for previous published versions of the selected policy (20 per page, descending version). Each item contains ID, parent policy ID, title, version, publication time and the caller’s acknowledgement time or null. Add `&history_page=2` for the next page; the selected version can remain open while paging. No staff list, another person’s acknowledgement or private file path is returned. Version selection requires `policy_id`; invalid query values return validation errors.

The acknowledgement endpoint still accepts only `policy_version_id` and `acknowledged`. It derives user/company/time on the server and adds `remaining_count` to successful responses. Protected requests still return HTTP 423 with `POLICY_ACKNOWLEDGEMENT_REQUIRED` and an outstanding current version.

Existing PDF-capable 1.6 clients retain the single-policy response fields and encounter outstanding policies one at a time; they cannot bypass the server's all-policies requirement. **Rebuild/install 1.8 for the list, history and older-version selection.** Version 1.7 has the current-policy list but lacks staff history. Earlier clients lacking PDF controls need the update before PDF publication.

## Controlled Railway release

No production deployment or migration is performed by implementation/testing.

1. Review the source and arrange a maintenance window. Back up PostgreSQL and the entire `/data/uploads` directory together. Preserve APP_KEY, database references, upload path, existing credentials, Firebase file and Android signing key.
2. In the current **TaskSure web service shell**, run the read-only `php artisan migrate:status`. Review **all** pending migrations in the approved release, including `2026_10_16_000002_add_company_policy_pdfs` if it has not run. Follow the earlier completion/multi-store guides if those migrations remain pending. A source upload failure's cause still requires the web application's actual `production.ERROR` line; PostgreSQL startup logs cannot diagnose an upload error.
3. Put the old web app in maintenance (`php artisan down`) and stop/quiesce old worker/scheduler writers. Keep the web service's **Pre-Deploy Command** set to:

   ```sh
   php artisan migrate --force
   ```

   Run migrations only through the authorised deployment step against the existing database. Do not run them during Docker image build. Do not seed or reset production. Worker/scheduler have no migration commands.
4. Manually release the approved source to web, then the same source to worker/scheduler. Check pre-deploy success, `php artisan migrate:status` (library and PDF migrations **Ran**), `/health`, original accounts/tasks/evidence and existing policies/acknowledgements before reopening. Run `php artisan up` on the deployed web service if maintenance remains active; verify the domain is serving the approved source. Keep one web replica and the existing persistent volume/settings. Retain worker/scheduler process roles and push credentials.
5. Install the signed Android 1.8 update. Publish two separate test policies with the client, confirm both must be acknowledged, update one and confirm only that policy requires confirmation again. Verify owner tracking and previous PDFs, read/sign-out while blocked, real notification delivery and a PDF download after restarting web. Physical-phone and production persistence checks remain operator actions.

## Updating an installation that already has the policy library

The staff-history follow-up adds **no new migration**, rewrites no policy/file/acknowledgement data and sends no new notification. Check `php artisan migrate:status` in the Railway web shell: if the earlier PDF/library migrations are already **Ran**, release the reviewed web source through the existing controlled deployment process and update worker/scheduler to the same source. Keep the existing web-only pre-deploy migration command; review any unrelated pending migrations before release. If the library migration is still pending, use the coordinated maintenance procedure above.

Build/install the signed Android **1.8 (code 9)** update to expose history. Keep the same API URL, application ID, Firebase file and signing key. No new plugins, storage mounts, services or credentials are needed. Production deployment/migrations are not performed by implementation/testing.

Test both roles after release: read current and old policies, download an original PDF, see only personal acknowledgement times, verify old-version reading/confirmation cannot bypass a pending current version, and confirm current versions to resume normal work. On a physical phone check reader/Save As, hardware/gesture back, resume and offline behaviour. Existing export/backup rules are unchanged.

## Windows Android update

After this reviewed source is delivered to your computer, open PowerShell in the repository root:

```powershell
cd mobile
npm ci
npm test
npm run build
npx cap sync android
npm run android
```

Preserve `mobile/.env` with `VITE_API_URL=https://tasksure-production.up.railway.app/api/mobile/v1` and the existing `mobile/android/app/google-services.json`. Android Studio: **Build → Generate Signed App Bundle or APK → APK → release**, using the same keystore as the installed app. Application ID remains `za.co.tasksure.employee`. This release is **versionCode 9 / versionName 1.8**; increase the code if your installed release is higher. Install as an update.

With a configured local JDK/Android SDK, the debug compilation command from `mobile/` is:

```powershell
.\android\gradlew.bat --project-dir android :app:assembleDebug
```

A debug APK is not a signed client release. On a physical phone verify multiple-policy selection, PDF viewer/Save As fallback, unchecked confirmations, hardware/gesture back, resume after publication, offline failure, sign-out, all acknowledgements then existing task/camera workflows. Browser and compilation checks do not establish those native behaviours or actual push receipt.
