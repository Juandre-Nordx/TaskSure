# Company policy and staff acknowledgement

This feature is implemented for **one business per TaskSure deployment**. There is no company selector, second-business provisioning screen or change to manager team permissions. The internal company record links existing accounts and policy history; it is not a shared SaaS tenancy feature.

## Owner workflow

Open **Overview → Company policy**, or the **Company policy** navigation item. Only the existing `admin`/store-owner role can manage or publish policies.

1. Enter a title and full policy content, then **Save draft**. Content is plain text with paragraphs and line breaks, limited to 100,000 characters; the title allows 180 characters. The saved draft does not notify or block staff.
2. Review the saved draft, then **Publish policy**. Unsaved form edits are not published. Publication assigns the next version, records its publisher and server time, and sends an in-app alert to every active manager and employee in this business through `AlertService`. Existing optional email and native push delivery use their existing queue/configuration. Publication does not prove that an external notification reached a phone.
3. The owner can read the current policy and previous immutable versions. Editing starts a new draft prefilled from the current version; publishing creates another version. Previous content and acknowledgements remain intact.
4. The acknowledgement overview lists active staff, role, current version, **Acknowledged / Outstanding**, and acknowledgement time in SAST. Filter by role and acknowledgement status. An acknowledgement records the user's confirmation; it is not proof that the person understood the content. Inactive staff are excluded from this current overview, while their history remains stored.

Draft revisions prevent one stale owner tab from overwriting or publishing a different saved draft. Reload if another tab has changed it.

## Staff access and API behaviour

Managers and employees must acknowledge the current published version, including newly created staff and staff already signed in when publication occurs. Owners remain able to manage policies. When no policy is published, access continues normally.

The web and bundled mobile interface display the full, escaped policy text in a scrollable area, version, publication time, initially unchecked checkbox and disabled **Confirm and continue** button. Reading the policy and signing out remain available. Successful web confirmation returns to the first blocked local GET where practical; mobile resumes its requested screen after checking the latest policy again.

`RequireCompanyPolicy` guards authenticated web and mobile routes. Protected API/JSON requests return **HTTP 423**:

```json
{
  "code": "POLICY_ACKNOWLEDGEMENT_REQUIRED",
  "message": "Read and acknowledge your current company policy to continue.",
  "policy": {
    "id": 1,
    "version": 1,
    "title": "Company policy",
    "content": "Full policy text",
    "published_at": "2026-10-14T12:00:00+00:00"
  }
}
```

Allowed authenticated routes while acknowledgement is outstanding:

| Web | Mobile API, under `/api/mobile/v1` |
|---|---|
| `GET /company-policy` | `GET /company-policy` |
| `POST /company-policy/acknowledge` | `POST /company-policy/acknowledge` |
| `POST /logout` | `POST /logout` |
| Authentication/password-reset routes retain existing rules | `GET /me` returns profile and `policy_required` / `policy`; `POST /login` remains available |

Policy GET returns `{policy, required, acknowledged_at}`. Confirmation accepts only `policy_version_id` and an accepted `acknowledged` value; e.g. `{ "policy_version_id": 1, "acknowledged": true }`. Company/user/time fields are prohibited. The server obtains company/user from the authenticated account and generates the timestamp. Unique user/version constraints and transaction locks make repeated confirmations safe without changing the first timestamp. Policy publication and acknowledgement lock the same company row. An outdated version returns 423 with the latest policy and creates no obsolete acknowledgement; both clients display that version with a fresh unchecked checkbox.

Task operations, private evidence, reports, calendar, alerts and device registration remain blocked until confirmation. API errors open the mobile policy screen even if the user already had a task open. Native back navigation cannot dismiss a mandatory policy; resuming the native app rechecks it. Hash navigation, browser history restoration and new server requests revalidate. Failed/offline policy checks show retry/sign-out instead of normal mobile features. Cached client state does not authorize server operations.

## Database upgrade

`2026_10_14_000002_create_company_policies.php`:

- Creates one primary internal company and links every existing account to it through `users.company_id`, without altering original account fields or timestamps. The database default also assigns this business to staff created by an older web process during the pre-deploy window.
- Adds draft/current-version metadata, immutable published version records, and acknowledgements with company/user/version/time and unique user/version protection.
- Leaves existing users, password hashes, manager scopes, tasks, attempts, evidence references, sign-ins and tokens intact. No policy is published and no staff notifications are sent by the migration.
- Uses a forward-only `down()` to protect policy and acknowledgement history. Do not roll it back by dropping history tables.

Review **all** pending migrations. If `2026_10_14_000001_separate_completion_from_review` is still pending, it also changes legacy task statuses while preserving history; use its [coordinated release procedure](COMPLETION-REVIEW-RELEASE.md). Do not skip it or run old task-writing processes during that upgrade.

## Controlled Railway release — not performed automatically

1. Review this feature branch before authorizing a release. Do not push/merge it to a production auto-deploy branch until ready. Back up the existing PostgreSQL database and evidence volume; preserve APP_KEY, database references, existing credentials, Firebase configuration and signing keys.
2. Check migration status in the deployed web container using **Railway → web service → shell**, or `railway ssh --service <your-web-service-name>` followed by `php artisan migrate:status`. That command lists migrations present in that running release; also review the new migration in the approved source before deployment.
3. Set the **web service only → Settings → Deploy → Pre-Deploy Command** to:

   ```sh
   php artisan migrate --force
   ```

   This is the production migration command for the approved deployment. It runs pending forward migrations against the existing PostgreSQL database. Migrations must not run during the Docker image build. Neither the root Dockerfile nor its entrypoint runs them. Do not seed production or reset the database.
4. Manually deploy the approved source to web. Check pre-deploy success, `php artisan migrate:status`, `/health`, existing owner/staff logins, task workflows and original evidence. No published policy initially means staff access continues normally. Coordinate stopped writers/maintenance if the older task-status migration is pending.
5. Deploy the **same source** to the existing worker and scheduler, one replica each. Keep `PROCESS_ROLE=worker` / `PROCESS_ROLE=scheduler`, the shared PostgreSQL/APP_KEY configuration, and existing push/email credentials. Leave migration commands off those services. Check worker logs and `php artisan queue:failed`; do not blindly retry unrelated historical failures. The policy feature needs no extra service, scheduler job or Firebase app.
6. Install the new signed Android update on staff phones **before the owner publishes the first policy**. Older APKs cannot show this new screen and will receive blocked API responses after publication. Web staff can acknowledge through the website, but still need the APK update for this mobile interface.
7. Create a draft, confirm staff access continues, publish it, verify real in-app/native notifications and manager/employee confirmations, check the owner's overview, then publish a second version and verify confirmation is required again. Check that policy reading and sign-out work while blocked.

## Android rebuild on Windows

After this reviewed source has been delivered to your computer, open PowerShell in the repository root:

```powershell
cd mobile
npm ci
npm test
npm run build
npx cap sync android
npm run android
```

Keep your existing `mobile/.env`; confirm `VITE_API_URL=https://tasksure-production.up.railway.app/api/mobile/v1` targets the intended deployment. Keep `mobile/android/app/google-services.json` from the existing Firebase app. No service-account secret belongs in mobile files or `VITE_*` variables.

In Android Studio use **Build → Generate Signed App Bundle or APK → APK → release**, with the **same keystore** used for the installed app. The application ID remains **`za.co.tasksure.employee`**, with **versionCode 3 / versionName 1.2** in this source. If you already installed/published a higher code, raise it further. Install as an update. Do not uninstall to work around a signing mismatch; use the original signing key. Use an AAB if distributing through your approved Google Play process.

For a local debug build with the Android SDK/JDK configured:

```powershell
cd android
.\gradlew.bat :app:assembleDebug
```

Physical-device checks still required: manager and employee login, mandatory screen, unchecked checkbox, Android hardware/gesture back, app resume after publication, old-version confirmation, loss of connection, acknowledgement then task work/camera, policy reading, sign-out and actual foreground/background push. Debug compilation and browser checks do not establish native permission behaviour or signed release delivery.

## Verification

Run the Laravel suite against disposable SQLite, PostgreSQL and MySQL test databases using [the README commands](../README.md#checks), then `vendor/bin/pint --test`, `npm run build`, `npm run check:railway`; in `mobile/`, run `npm test`, `npm run build`, `npx cap sync android`, and the native debug build. Never point the test suite at client data.

See [validation results](VALIDATION.md#company-policy-and-mandatory-acknowledgement--14-october-2026) for the actual checks and their limits. Production deployment/migration and physical-phone verification remain operator actions.
