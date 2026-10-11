# Physical Android installation audit

Audited on 10 October 2026 against `c418e4e` plus the fixes in `fix/android-readiness`. This branch is separate from deployed `main`; no Railway infrastructure, production variables, database or evidence files were changed by this audit.

## What the repository verifies

| Requested check | Finding | Evidence / remaining verification |
| --- | --- | --- |
| Mobile frontend and commands | `mobile/`, TypeScript, Vite 8, Tailwind 4; `npm ci`, `npm test`, `npm run build`, `npm run sync:android` | Frozen installation, tests, type check, bundle and Android asset/plugin sync passed. `npm run sync` builds and syncs both Android/iOS. |
| Capacitor configuration | `mobile/capacitor.config.ts`: `appId=za.co.tasksure.employee`, `appName=TaskSure`, `webDir=dist` | Android loads local assets at `https://localhost`. No `server.url`. The synced JSON was inspected. |
| Android project | Present at `mobile/android/`, including Gradle wrapper, manifest and `MainActivity` | **Debug APK compiled successfully**, all eight plugins included; its signature verified. `applicationId` and namespace match Capacitor; minimum SDK 24 (Android 7), compile/target SDK 36. Build toolchain: full JDK 21, Gradle 8.14.3, Android SDK 36. A Java runtime without `javac` is insufficient. |
| Embedded API URL | `https://tasksure-production.up.railway.app/api/mobile/v1` | Found in both the production `dist` JavaScript and copied native JavaScript. `VITE_API_URL` overrides it at build time; rebuild and sync after changing it. |
| Camera | Locked `@capacitor/camera` 8.2.5, registered in Android | `takePhoto`, JPEG conversion/upload, quality 80, 1920px target, `saveToGallery=false`, and `appRestoredResult` handling. This version launches the external camera activity and needs no broad camera/storage permissions for this mode. Camera availability, cancel and recovery require your phone. |
| Push plugin | Locked `@capacitor/push-notifications` 8.1.3, registered in Android | Plugin provides Firebase messaging service, permission request, token registration, foreground and tap handlers. Manifest includes a notification icon. Final APK manifest verified: INTERNET, POST_NOTIFICATIONS and FCM RECEIVE permissions are present. No broad camera/storage permission is requested. |
| Firebase | `mobile/android/app/google-services.json` is **absent in this checkout**, and intentionally ignored | Gradle conditionally applies Google Services 4.4.4 when the file exists. No client project, server credential, permission or live FCM delivery can be claimed. Basic task/camera installation does not need Firebase; push does. The audit adds a build-time guard against calling native FCM without matching client configuration. |
| Laravel authentication | Sanctum bearer tokens, active employees only, `employee` ability, 30-day expiry | Routes under `/api/mobile/v1`; task/evidence policies reused; no session-cookie authentication for mobile. Tokens are hashed server-side and encrypted on Android. Password reset/deactivation/admin password change revoke tokens; logout revokes this sign-in and its push device. API and boundary tests passed. Managers/admins use the existing Blade dashboard. |
| Queue and scheduler | Database queue, `PROCESS_ROLE=worker` and `PROCESS_ROLE=scheduler` | Entrypoint runs `queue:work database --sleep=3 --tries=3 --timeout=90` and `schedule:work`. Tasks generate/remind every minute, expired tokens prune daily. IaC models both services; this does **not** prove those services exist in your Railway project. Local process/log/schedule checks passed. The audit raises reservation expiry from 90 to 180 seconds to exceed the worker timeout. |
| Evidence persistence | Private `evidence` disk; production path `/data/uploads`, uploaded files underneath `evidence/`; PostgreSQL stores metadata | MIME/signature/size validation, policy-checked downloads, no public storage link. Railway web requires the existing `/data` persistent volume, one replica, `UPLOAD_STORAGE_PATH=/data/uploads`. Worker/scheduler need no evidence volume. Local persistence/authentication tests passed; your Railway mount, backups and post-restart retrieval still need verification. |

## Verification limits

The generated development APK is `mobile/android/app/build/outputs/apk/debug/app-debug.apk` (10,091,317 bytes). It contains the same assets as the current production `dist/`, the API URL above and all eight native plugins; the development RSA signature verified. ZIP and all 64-bit native library ELF alignments also passed 16KB page-size checks. SHA-256: `6cf6079de7ef607597f848699870c7627b2af4193124f54da5fe2376a242009a`. The APK is an ignored build output, not committed to GitHub. This verifies compilation/packaging, not installation or native behavior on your phone.

Live HTTPS requests to Railway `/health`, `/api/mobile/v1/me` and the Android CORS preflight were blocked by the cloud workspace's egress proxy (`CONNECT` HTTP 403). These were **not responses from TaskSure** and do not establish a production failure. No Railway CLI/account binding was available here. Locally, `/health` returned 200, unauthenticated `/me` returned JSON 401, and the Android preflight returned 204 with `Access-Control-Allow-Origin: https://localhost` and allowed Authorization/Content-Type headers.

Push provider tests use HTTP fakes; they verify signing, payloads and retry handling, not actual Firebase delivery. Physical encrypted storage, app restart, camera capture/recovery, Android PDF viewing, notification permission, background delivery and notification taps require a phone. This is an online app; it has no durable offline task/evidence database.

## Fixes made during this audit

- Added `sync:android`, `check:android` and `check:android:push` commands. The checks inspect the copied native bundle, application ID, HTTPS API URL, local shell, plugin registration and Firebase client package. The basic check warns when Firebase is absent; the push check fails explicitly.
- Disabled native Android push registration in builds without a matching Firebase client file. This prevents a missing default Firebase application from throwing in native Java. In-app alerts, tasks and evidence remain usable. Rebuild **after adding** the client file to enable Android push.
- Pinned the Gradle distribution's official SHA-256.
- Set database queue reservation expiry to 180 seconds while retaining the 90-second worker timeout. Set `DB_QUEUE_RETRY_AFTER=180` on all Railway application services when releasing this server fix, then restart the worker/config caches. Existing explicit Railway overrides still take precedence.

## Windows PowerShell: build and install

Install Git, Node **24**, Android Studio, a **full JDK 21**, Android SDK Platform 36, Build-Tools 35.0.0/36.0.0, SDK Platform-Tools and Command-line Tools through Android Studio's SDK Manager. Android Studio commonly supplies JDK 21 at the path below; check both `java` and `javac`. If your Studio installation has another JDK version, point `JAVA_HOME` to your separately installed JDK 21. The SDK path below is Studio's Windows default; use your actual SDK location if customized. Install your phone manufacturer's USB driver when Windows does not detect the device.

For a new checkout:

```powershell
git clone --branch fix/android-readiness https://github.com/Juandre-Nordx/TaskSure.git
Set-Location TaskSure\mobile
```

For an existing clean checkout, from its repository root:

```powershell
git fetch origin
git switch fix/android-readiness
Set-Location mobile
```

Prepare tools and build the **locally bundled** app:

```powershell
$env:JAVA_HOME = "$env:ProgramFiles\Android\Android Studio\jbr"
$env:ANDROID_HOME = "$env:LOCALAPPDATA\Android\Sdk"
$env:Path = "$env:JAVA_HOME\bin;$env:ANDROID_HOME\platform-tools;$env:Path"
node --version
java -version
javac -version

npm ci
# Explicitly sets the public API compiled into this build. No login secrets go here.
$env:VITE_API_URL = 'https://tasksure-production.up.railway.app/api/mobile/v1'
npm test
npm run sync:android
npm run check:android

Set-Location android
.\gradlew.bat :app:assembleDebug
```

Do not continue if a command fails. Firebase warnings are acceptable for a task/camera test build with push disabled; `check:android:push` is required for a push-enabled build. Gradle requires network access to Google Maven, Maven Central and the Gradle distribution. If SDK license/package setup is needed, use SDK Manager or, from `mobile/`, run:

```powershell
& "$env:ANDROID_HOME\cmdline-tools\latest\bin\sdkmanager.bat" --licenses
& "$env:ANDROID_HOME\cmdline-tools\latest\bin\sdkmanager.bat" 'platform-tools' 'platforms;android-36' 'build-tools;35.0.0' 'build-tools;36.0.0'
```

On the phone, enable Developer Options and USB debugging, connect by USB, unlock it and approve this computer's debugging prompt. From `mobile/android/`:

```powershell
adb devices
# The phone must show "device", not "unauthorized"; choose a device with -s if several are connected.
adb install -r .\app\build\outputs\apk\debug\app-debug.apk
adb shell am start -n za.co.tasksure.employee/.MainActivity
```

The debug APK is automatically signed with a development keystore and is suitable for testing on your own phone. A signed release/Play Store build needs your protected release keystore and versioning. A signature mismatch with an already-installed app requires the original signing key or removal of that app (removal clears its local sign-in and cached files); do not blindly uninstall.

For Android Studio instead of a shell install, run `npm run android` from `mobile/`, set Gradle JDK to 21, select the connected phone and click Run.

## Firebase setup for a push-enabled Android build

1. In your Firebase account register the exact Android package `za.co.tasksure.employee`. Download the Android **client** `google-services.json` into `mobile/android/app/google-services.json`. It is intentionally ignored. Do not put a Firebase service-account private key in the app.
2. Enable the Cloud Messaging HTTP v1 API. Set the complete **server** service-account JSON as `FCM_SERVICE_ACCOUNT_JSON` on the Railway **worker**, with permission to send messages for the same Firebase project. Keep the existing `APP_KEY` identical across web/worker/scheduler so encrypted push tokens remain readable.
3. Set `MOBILE_PUSH_ENABLED=true` on web/worker/scheduler only after configuring the supported providers; restart those services. Keep `MOBILE_ALLOWED_ORIGINS=capacitor://localhost,https://localhost` on web. Do not enable native push before adding/rebuilding the Android client file.
4. From `mobile/`, rebuild, verify and install:

   ```powershell
   npm run sync:android
   npm run check:android:push
   Set-Location android
   .\gradlew.bat :app:assembleDebug
   adb install -r .\app\build\outputs\apk\debug\app-debug.apk
   ```

5. Use an Android device with working Google Play services. Sign in with an **active employee account**, open Settings, enable push and grant Android 13+ notification permission. Assign/review a task from the unchanged Blade dashboard. Verify foreground, background and tap behavior; verify logout stops delivery. FCM credentials alone do not prove the worker is processing jobs.

## Railway checks from your computer/account

Read-only public checks in PowerShell:

```powershell
curl.exe -i https://tasksure-production.up.railway.app/health
curl.exe -i -H 'Accept: application/json' https://tasksure-production.up.railway.app/api/mobile/v1/me
curl.exe -i -X OPTIONS -H 'Origin: https://localhost' -H 'Access-Control-Request-Method: POST' -H 'Access-Control-Request-Headers: authorization,content-type' https://tasksure-production.up.railway.app/api/mobile/v1/login
```

Expect health 200, `/me` JSON 401 without a token, and preflight 204 with the exact Android origin and Authorization/Content-Type allowed. A 404 on the API indicates wrong URL or an older backend. Verify an actual employee login on the phone; public responses do not prove the token/device migrations ran.

In Railway, check the **actual** existing services and variables, without applying the new-project IaC template:

- Web: current backend commit, additive mobile migration marked run, native CORS origins, PostgreSQL settings, existing `APP_KEY`, persistent `/data` mount and `UPLOAD_STORAGE_PATH=/data/uploads`. Preserve the existing database and volume; do not generate a replacement key or seed demo users.
- Worker: same source/database/key/cache, `PROCESS_ROLE=worker`, `QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER=180` after releasing this fix, appropriate push flag/provider secret; no public domain or sleeping. Logs must show jobs being processed without repeated failures.
- Scheduler: same source/database/key/cache, `PROCESS_ROLE=scheduler`, one always-running replica. Logs must show minute-by-minute task generation/reminders. No HTTP healthcheck on worker/scheduler.

With Railway CLI installed, from the repository root:

```powershell
railway login
railway link
railway ssh
```

Select the existing web service/environment. `railway ssh` executes inside the deployed container; `railway run` would execute locally. Read-only commands inside web:

```sh
php artisan migrate:status
php artisan route:list --path=api/mobile
php artisan schedule:list
php artisan queue:failed
```

The migration `2026_10_10_000001_create_mobile_access_tables` must be marked Run. Check worker/scheduler logs through each service's Deployments view. Never paste bearer tokens, APP_KEY, database URLs or service-account JSON into support chat/logs. Finally upload a real camera photo, submit, approve it in Blade, restart web through your normal deployment process and confirm the same evidence remains retrievable; this verifies your volume configuration.

See [mobile deployment](MOBILE.md) for the full API/provider contract and [validation](VALIDATION.md) for recorded results. Physical-device and live-account checks above remain required before calling the app ready for distribution.
