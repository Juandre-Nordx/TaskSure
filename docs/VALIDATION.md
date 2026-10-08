# Validation record

Checked in the cloud workspace on 8 October 2026.

- Laravel 13.35.0, PHP 8.4.26 and MySQL 8.4; dependencies installed from frozen Composer/npm lockfiles with TLS/signature verification intact. GitHub archives use official codeload URLs because api.github.com was blocked.
- `php artisan test`: **16 passed, 133 assertions** using in-memory SQLite.
- MySQL test run against separate `tasksure_test`: **16 passed, 133 assertions**.
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

Live Railway deployment, Railway volume restoration, external message delivery, and a complete live production backup/restore rehearsal were not performed. Publishing this cloud environment or restoring it in a new task has not been verified. Source is saved in this checkout and has not been pushed to GitHub.
