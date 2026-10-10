# Validation record

Checked in the cloud workspace on 8 October 2026.

- Laravel 13.35.0, PHP 8.4.26, PostgreSQL 18 and MySQL 8.4; dependencies installed from frozen Composer/npm lockfiles with TLS/signature verification intact. GitHub archives use official codeload URLs because api.github.com was blocked.
- `php artisan test`: **16 passed, 133 assertions** using in-memory SQLite.
- MySQL test run against separate `tasksure_test`: **16 passed, 133 assertions**.
- PostgreSQL test run against a disposable PostgreSQL 18 `tasksure_test`: **16 passed, 133 assertions**. SQLite and MySQL checks were repeated after adding PostgreSQL support, with the same results.
- Both PHP Dockerfiles include `pdo_pgsql`, `pdo_mysql` and `pdo_sqlite`. The updated development image and production Dockerfile built successfully. Compose configuration validated with PostgreSQL 18 and its version-specific `/var/lib/postgresql` volume mount.
- The production image migrated a fresh PostgreSQL database and seeded six categories with zero user accounts. `/health` and `/login` returned HTTP 200; compiled assets were referenced. A queue-worker pass and both scheduled task-generation/reminder commands completed against PostgreSQL.
- `npm run build`: production Tailwind/FullCalendar assets built.
- `vendor/bin/pint --test`: passed.
- `npm run check:railway`: TypeScript check and official SDK evaluation passed for five resources. No Railway infrastructure was applied.
- Migrations and category seeding succeeded on MySQL. Demo seeding created an owner, scoped manager, 50 fictional employees and liquor-store duties. The demo seeder refuses non-local environments.
- Real Chromium sign-in and HTTP 200 checks for owner dashboard, task list/create, calendar, reports, account/scope settings and notifications. Calendar events rendered; no JavaScript errors. A 390px viewport had no document overflow.
- Nginx/PHP-FPM production container smoke test: assignment, start work, authenticated PNG upload with progress, submission, manager approval, PDF download, and another employee denied task/file access. Missing CSRF token returned HTTP 419.
- Production upload retrieved after web-container restart with the same SHA-256. A separate automated test checks private storage through a fresh filesystem instance. PDF text was extracted and checked for the selected task and punctuality records; Excel rows and comparison totals were checked against the report service.
- Required evidence, fresh proof after corrections, independent approval/submission punctuality, immutable attempt deadlines, deadline-change trace, blockers, late metrics/date basis, cancellation, account deactivation, reset tokens, scope rules, recurrence/month-end handling and alert deduplication are exercised by the suite.
- Unconfigured email/WhatsApp fail explicitly; tests confirm no false sent status. Real SMTP/WhatsApp credentials and delivery were not tested.
- Cloud setup script executed successfully. Startup was repeated and checked to keep one queue worker and one scheduler in the development PHP container. MySQL data was restored into an ignored workspace-bound directory and checked through migrations, health and the MySQL suite. Development upload files are also under the workspace.

The cloud Docker daemon uses `vfs`, so its full filesystem copies made multiple PHP containers/build layers exhaust disk space. Unused setup build cache and temporary production validation containers were cleaned up; cloud development runs web/worker/scheduler in one PHP container. Railway retains the documented separate-service arrangement. Nginx's absolute include path, image source-file permissions, and validated host/port forwarding were corrected during production smoke testing.

Live Railway deployment, Railway volume restoration, external message delivery, and a complete live production backup/restore rehearsal were not performed. Fresh-task restoration has not been independently verified. Application source was pushed to the GitHub repository; Railway deployment remains an operator step.

## Railway Metal builder correction — 9 October 2026

- Removed both secret mounts from the root Dockerfile after Railway rejected their syntax. Managed-cloud certificate secret mounts remain in the development Dockerfile only.
- `docker build --check -f Dockerfile .` passed with no warnings. The production build steps completed locally with temporary certificate copies for the cloud proxy; TLS verification stayed enabled and those copies were outside the repository.
- SQLite, MySQL and PostgreSQL each passed 16 tests / 133 assertions. Pint and the Railway configuration check passed; production frontend assets compiled during the image build.
- The rebuilt production image migrated and seeded a fresh PostgreSQL database. `/health` and `/login` returned HTTP 200, all three PDO drivers were present, and queue-worker/scheduled task commands completed.
- A successful Railway build/deployment still needs verification in the user's project after deploying this correction from `main`.

## Employee mobile frontend — 10 October 2026

- Added a separate TypeScript/Vite/Tailwind CSS 4/Capacitor 8 frontend and Android/iOS projects. Both projects successfully synchronize locally compiled assets and eight native plugins; no production `server.url` is set.
- SQLite, disposable PostgreSQL 18 and separate MySQL 8.4 test databases each passed **27 tests / 216 assertions**. The working MySQL cloud fixture was preserved and received only the additive mobile migration.
- Employee API tests cover bearer-only login, role/ability/active-account checks, expiry and revocation, employee boundaries for tasks/calendar/notifications/files, private uploads, required fresh evidence after corrections, immutable attempt deadlines, current-device logout, password reset, throttling and native CORS origins.
- Push tests exercise queued alert deduplication, delivery-state retries, permanently invalid token removal, unconfigured provider failure, RSA-signed Firebase HTTP v1 authorization and EC-signed APNs requests using HTTP fakes. No real provider keys or delivery were used.
- Mobile unit tests passed **7 tests** covering SAST display, escaped text, proof requirements, multipart uploads, bearer transport, expiry handling and rejection of external API paths.
- Real Chromium at 390px against an isolated local SQLite backend passed employee login, scoped assigned tasks, start work, JPEG upload, authenticated photo preview, preservation of a typed completion note during upload, submission history, notifications/read state, calendar, settings and logout. The same submission was approved through the existing Blade dashboard and the result appeared in the mobile history. The Blade calendar also rendered. No JavaScript errors or document overflow were observed.
- The browser run exposed duplicate FullCalendar copies because shared imports resolved from the root and mobile dependency directories. Vite now deduplicates FullCalendar/Luxon/Preact; the repeat browser run passed and the production mobile bundle contains one calendar implementation.
- Root and mobile production builds, TypeScript checks, Capacitor sync, Pint, Railway IaC evaluation, shell syntax and Git whitespace checks passed. Frozen mobile installation with `npm ci` was checked. iOS usage descriptions, APNs callbacks/capability/entitlement and privacy manifests are included; XML plists parsed successfully.

Android/iOS binaries were not compiled or signed in this Linux workspace (Android SDK/Xcode and signing/provisioning are required). Native encrypted storage, camera permission/recovery, PDF viewing and foreground/background push still require physical-device verification. Live Railway API deployment, Firebase/APNs delivery and app-store distribution were not performed. Follow [mobile setup and deployment](MOBILE.md) for those release steps.

## Physical Android readiness audit — 10 October 2026

- Installed Android SDK 36, build-tools 35/36, platform-tools and a full JDK 21 with verified official download checksums. The original host Java installation was a runtime without `javac`; it could not compile an APK. Cloud proxy/trust and writable Android settings were configured in temporary tool directories without changing production.
- `:app:assembleDebug` succeeded with Gradle 8.14.3 and all eight plugins. The 10,091,317-byte APK's RSA development signature verified with `apksigner`. The APK manifest has application ID `za.co.tasksure.employee`, minimum SDK 24, target/compile SDK 36, INTERNET/POST_NOTIFICATIONS/FCM RECEIVE permissions and no broad camera/storage permission. Its bundled assets match the production Vite output byte-for-byte and contain `https://tasksure-production.up.railway.app/api/mobile/v1`.
- APK ZIP alignment and every 64-bit native library's ELF LOAD alignment passed 16KB page-size checks; arm64-v8a and x86_64 LOAD segments use `0x4000` alignment. This checks packaging for modern Android devices, not runtime behavior.
- Added a guard against invoking native Android Firebase registration without matching client configuration, with two meaningful regression tests. Frozen mobile installation, **9 mobile tests**, TypeScript/Vite production build, Android sync and the basic native configuration check passed. The push configuration check **fails as expected** because `android/app/google-services.json` is absent; this is a push prerequisite, not a successful delivery check.
- Fixed queue reservation expiry to exceed the existing 90-second worker timeout (180 seconds). SQLite, isolated PostgreSQL 18 and separate MySQL 8.4 suites each passed **27 tests / 216 assertions**. Root build, Pint and Railway configuration validation passed. No production or working database was reset.
- Local `/health` returned 200, unauthenticated employee `/me` returned JSON 401, and the Android-origin API preflight returned 204 with the correct CORS headers. Local worker/scheduler processes were present; scheduler logs showed successful minute-by-minute generation/reminder runs. These observations concern the development fixture, not Railway.
- Live Railway requests were blocked by the cloud egress proxy with CONNECT 403; no application response, live Railway service logs, authenticated production API request or Firebase/account configuration was available. No native app was installed on a physical device. Camera/recovery, secure session restoration, PDF viewing, actual FCM background/foreground/tap delivery and Railway evidence persistence remain unverified.

See [Android readiness and Windows commands](ANDROID_READINESS.md). Audit fixes remain on the separate `fix/android-readiness` branch; the deployed website was not changed.
