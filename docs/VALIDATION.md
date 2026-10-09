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
