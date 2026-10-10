# TaskSure employee mobile app

`mobile/` is a separate TypeScript/Vite/Tailwind CSS 4 app with Capacitor 8 Android and iOS projects. It bundles its HTML, JavaScript, CSS and calendar code into the native app. Capacitor has **no `server.url`**: it never loads the Railway website as its production interface. Laravel remains the backend, with the existing Blade manager/owner dashboard, PostgreSQL, queue and scheduler.

## Build and run

Use Node 24, and run commands from `mobile/`:

```sh
npm ci
cp .env.example .env
# VITE_API_URL must be your HTTPS Railway URL followed by /api/mobile/v1.
npm test
npm run sync
```

`npm run sync` type-checks, builds `dist/` and copies the local assets and plugin configuration into both native projects. The default production API is `https://tasksure-production.up.railway.app/api/mobile/v1`; change `VITE_API_URL` and rebuild when changing the backend hostname. `VITE_*` values are public compiled configuration, never credentials. Commit the lockfile; build subsequent installs with `npm ci`.

For a browser preview, start Laravel on port 8080, leave `VITE_API_URL` **unset** in the mobile environment, and run `npm run dev`. Vite proxies `/api` to Laravel, so production CORS does not need a development wildcard. To use a different local backend: `MOBILE_DEV_API_TARGET=http://127.0.0.1:8090 npm run dev`. Browser preview sign-ins stay in memory and end on a full reload. Native sign-ins persist in encrypted storage.

Android requires Android Studio, JDK 21 and Android SDK 36. Run `npm run android`, select an emulator or connected phone, and build/run. Android minimum SDK is 24. For production, use Android Studio's signed bundle workflow and your own protected upload keystore. Increase `versionCode` for subsequent releases. Keystores and `local.properties` are ignored.

iOS requires macOS/Xcode compatible with Capacitor 8, an Apple developer team and a provisioned app identifier. Run `npm run ios`, choose your signing team and device, then build/archive. The app identifier in both projects is `za.co.tasksure.employee`. If changing it, update `capacitor.config.ts`, Android namespace/applicationId, Xcode bundle identifier, Firebase registration and `APNS_BUNDLE_ID`. The included native projects already contain camera usage descriptions, APNs callbacks/entitlements and the privacy manifest for preferences/private evidence cache.

## Deploy the Laravel API to Railway

1. Deploy the updated Laravel source using the existing root Dockerfile and PostgreSQL service. The Docker context excludes `mobile/`; the native frontend is built separately. Preserve the existing `APP_KEY`, database, evidence volume and dashboard configuration.
2. Keep the existing pre-deploy command `php artisan migrate --force && php artisan db:seed --force`. The new migration adds Sanctum access tokens, push devices and delivery records; it does not replace task tables or data.
3. Set `MOBILE_ALLOWED_ORIGINS=capacitor://localhost,https://localhost` on web. Android uses `https://localhost` and iOS uses `capacitor://localhost`. If publishing a separate browser build, append its exact HTTPS origin. CORS uses bearer authorization and does not allow credentialed cookies or wildcard origins.
4. Keep the existing database queue worker and scheduler running. Initially use `MOBILE_PUSH_ENABLED=false` on all services. Native push needs the provider configuration below; in-app alerts work independently.
5. Confirm `/health` works and unauthenticated `GET /api/mobile/v1/me` returns JSON HTTP 401. Sign in with an existing active **employee** account from the app. Managers and owners continue using Blade.
6. Build/sync the mobile app against that Railway API URL. Install it on real devices and complete the checklist below before distributing a signed release.

## Android push: Firebase HTTP v1

1. Register Android application `za.co.tasksure.employee` in your Firebase project. Download its client `google-services.json` into `mobile/android/app/google-services.json` (ignored). Enable the Firebase Cloud Messaging API.
2. Create a server service account with permission to send FCM messages. Supply its complete JSON as Railway secret `FCM_SERVICE_ACCOUNT_JSON` on the **worker**. The frontend never receives this private key. No legacy FCM server key is used.
3. Set `MOBILE_PUSH_ENABLED=true` on web, worker and scheduler after configuring the providers you support. Deploy/restart those services so configuration caches and long-running workers read the new variables. Rebuild/sync the Android project with the client Firebase file.
4. In the app's Settings, enable push notifications. Android 13+ asks for notification permission. A new assignment/reminder/review uses `AlertService`, queues `SendAlertPush` after the database commit, and sends to the device registered for this sign-in.

## iOS push: Apple APNs

The official Capacitor push plugin returns an **APNs device token on iOS**, rather than an Android Firebase token. Laravel therefore sends iOS notifications directly to APNs over HTTP/2; no iOS Firebase bridge or Firebase plist is required.

1. Enable Push Notifications for the Apple app identifier and select a signing team in Xcode. The project already declares the capability, APNs entitlement and registration callbacks.
2. Create an APNs authentication key in the Apple developer account. Set Railway worker secrets `APNS_PRIVATE_KEY` (the `.p8` PEM, actual newlines or escaped `\n`), `APNS_KEY_ID`, `APNS_TEAM_ID`, and `APNS_BUNDLE_ID=za.co.tasksure.employee`.
3. Use `APNS_SANDBOX=true` for development provisioning/device tests, and `false` for TestFlight/App Store production provisioning. Xcode distribution signing replaces the development `aps-environment` entitlement with the production entitlement. Verify the archived app's entitlement matches the selected server environment. A sandbox token cannot receive from the production APNs endpoint.
4. Enable push in Settings on a signed device build and test a background notification, foreground update and notification tap. Native permission denial and registration errors remain visible; there is no simulated successful registration.

All lock-screen push messages use generic text. Task details load through the authenticated API after tapping. Logout revokes that phone's API token and cascades deletion of its push registration. Password resets/admin password changes/deactivation revoke all mobile tokens. Tokens expire after 30 days; the existing scheduler prunes expired tokens daily. Invalid provider device tokens are removed; successful device deliveries are recorded and skipped on job retries. Network delivery cannot be guaranteed exactly once across a provider response/crash. Check `php artisan queue:failed` and `push_deliveries` for failures, correct credentials/network configuration, then retry with the existing queue tools.

## API contract

All paths below are relative to `/api/mobile/v1`. All authenticated requests require `Authorization: Bearer <token>` and `Accept: application/json`. Only active employee accounts with the `employee` token ability are accepted. API errors use Laravel's JSON 401/403/422/429 responses.

| Method / path | Purpose |
|---|---|
| `POST /login` | `email`, `password`, `device_name`; returns token, expiry, user and app config; existing login throttling applies |
| `GET /me` | Employee profile, SAST time zone, upload limit and push availability |
| `POST /logout` | Revoke current phone token and push device |
| `GET /tasks` | Scoped/paginated task summaries; existing filters plus `open=1`, `has_submissions=1`, `page` |
| `GET /tasks/{id}` | Assignment, fresh proof, immutable attempts, feedback, activity and deadline history |
| `POST /tasks/{id}/actions` | Existing `start`, `submit` + optional `note`, `comment`/`blocker` + `body` actions; supervisor actions are denied by existing policies |
| `POST /tasks/{id}/uploads` | Multipart `file`; existing MIME/signature/size/phase checks and private storage |
| `GET /attachments/{id}` | Authenticated private file; `?preview=1` for inline photos |
| `GET /calendar?from=YYYY-MM-DD&to=YYYY-MM-DD` | Employee's due-date events; inclusive SAST dates, maximum 93-day difference; dragging disabled |
| `GET /notifications?page=1` | Scoped alerts with unread count; inaccessible reassigned task links omitted |
| `POST /notifications/{id}/read` | Mark only this employee's notification read |
| `POST /devices` | `platform` (`android`/`ios`) and native push `token`; tied to current sign-in and encrypted at rest |
| `DELETE /devices` | Disable push for current sign-in |

Task lists and notifications return `{data, meta}`; details return `{data}`; calendar returns an event array. There is no public mobile registration or manager access through the employee API. Business transitions remain in `TaskWorkflow`, authorization in `TaskPolicy`, task filtering in `TaskQuery`, and uploads/downloads reuse `TaskController`. Shared palette/calendar configuration/error formatting live in `resources/shared/` and are used by both clients.

## Device verification

- Sign in/out and restart the native app. Confirm secure sign-in restoration and server revocation after password reset or deactivation. Sign in on two phones and ensure logging out one leaves the other active.
- Check task filters, day/week/month calendar, SAST deadlines, comments and blockers. An unrelated employee must receive 403 for another employee's task or evidence, even when guessing an ID.
- Take a photo, pick a photo/PDF, preview private evidence, submit a completion note, request corrections through Blade, then upload fresh proof and resubmit. Check that the original attempt retains its original evidence and deadline. The mobile app keeps no offline task/evidence database; uploads and submissions require connectivity.
- On Android, interrupt the camera activity by reclaiming the app process and verify `appRestoredResult` recovers the photo for the same employee/task. Recovery requires explicit upload and never silently submits work.
- Test native camera denial/cancel, oversize/invalid evidence, loss of connection, expired tokens and a task reassigned while its screen is open. Server rules remain authoritative.
- With real provider credentials, verify foreground/background push, permission denial, notification tap, invalid device-token cleanup and queued-job retry. Verify logout removes push delivery for that sign-in. Do not treat HTTP-faked provider tests as live device delivery.

See [validation](VALIDATION.md) for checks actually performed. Native signing, Firebase/APNs credentials and store distribution are operator-specific; no secrets or signed release files are committed.
