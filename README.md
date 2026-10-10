# TaskSure

A working employee task management app for liquor-store operations. Laravel 13 / PHP 8.4, PostgreSQL, Blade, Tailwind 4, FullCalendar, Dompdf and PhpSpreadsheet. Designed for approximately 50 employees using phones and supervisors using laptops. MySQL remains supported for the existing cloud development fixture.

## What works

- Dedicated [employee mobile frontend](mobile/README.md) in TypeScript/Vite/Tailwind/Capacitor, with locally bundled Android/iOS screens, camera evidence, authenticated Laravel API and queued native push. See [mobile deployment](docs/MOBILE.md) for provider credentials and signing requirements. The Blade manager dashboard remains in place.

- Owner-controlled accounts, inactive accounts with preserved history, scoped managers, employee-only task and file access, password reset, CSRF protection and login throttling. No public registration.
- Assignment, reassignment, scheduled start, priority, deadlines, start work, comments, blockers, evidence upload, submission, approval, corrections, cancellation and activity history.
- Every attempt has its own note, evidence, deadline, reviewer, reason and timestamps. Deadline changes retain old/new values and the actor. “Overdue” is calculated separately from status.
- Calendar day/week/month, task search, filters, semantic priority sorting, pagination, mobile dashboards and authenticated image previews/PDF downloads.
- Daily/weekly/monthly templates produce separate occurrences under transaction locks and unique constraints. Retried jobs do not duplicate instances or reminder alerts. Monthly day 31 returns to day 31 after shorter months.
- In-app assignment/reassignment, upcoming, overdue, submission, approval, correction, deadline and blocker notifications. Configurable reminder interval, optional queued SMTP delivery with recorded failures.
- Employee comparison and individual task reports, date-basis filters, per-attempt punctuality, corrections, blockers, cancellation reasons, evidence references and deadline changes. Functional PDF and Excel exports share the report service.

## Local setup with PHP installed

Prerequisites: PHP 8.4 (pdo_pgsql, pdo_sqlite, mbstring, gd, zip, XML, cURL), Composer 2, Node 24, PostgreSQL. PHP 8.4 is the tested runtime for the locked dependencies.

```sh
composer install
npm ci
npm run build
cp .env.example .env
# Set DB_* to your local PostgreSQL database and set UPLOAD_STORAGE_PATH to an absolute private directory.
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan tasksure:admin owner@example.com "Store Owner"
php artisan serve --port=8080
```

The administrator command asks for a password without echoing it. In separate terminals run `php artisan queue:work database --tries=3 --timeout=90` and `php artisan schedule:work`. All commands run from the repository root. Application timestamps are UTC; inputs and displays use Africa/Johannesburg (SAST).

## Cloud workspace without host PHP

```sh
bash scripts/cloud-setup.sh
bash scripts/cloud-start.sh
```

These preserve the existing Docker PHP/MySQL development fixture and use the host Node 24 runtime. Railway and Docker Compose use PostgreSQL; the PHP images include both database drivers. Local configuration is ignored, generated passwords are not printed, and existing `.env` files are preserved. The web process listens on port 8080. Web logs are available through `docker logs tasksure-dev`; queue/scheduler logs are in `storage/logs/worker.log` and `scheduler.log`. All cloud development processes share one PHP container to accommodate the cloud Docker driver; production uses separate Railway services. Use the existing checkout; cloud tasks are already isolated.

Run Artisan through the development PHP container:

```sh
docker exec tasksure-dev php artisan migrate:status
```

The cloud MySQL data directory is bound to ignored `storage/cloud/mysql`, and development evidence to `storage/app/evidence`, so their files live under the workspace. Dependencies and ignored configuration persist as files; running services need to restart in a new environment. The setup/start scripts are idempotent for this local configuration. They do not seed demo accounts or deploy Railway resources.

## Demo data (local only)

Set `APP_ENV=local` and choose your own local `DEMO_PASSWORD` (12+ characters) in the ignored `.env`, then run:

```sh
php artisan db:seed --class=DemoSeeder
```

Fictional accounts: `owner@tasksure.test`, `manager@tasksure.test`, and `employee1@tasksure.test` through `employee50@tasksure.test`. They use your chosen `DEMO_PASSWORD`. The manager covers the first 25 employees. The seeder refuses production. Never copy demo accounts, passwords, or the local database to production. Default `DatabaseSeeder` creates categories only. Demo records are explicitly illustrative, including historical submissions and clearly labelled synthetic evidence images.

## Checks

```sh
php artisan test
npm run build
vendor/bin/pint --test
npm run check:railway
```

The default suite uses isolated in-memory SQLite. Also run on a **disposable** PostgreSQL database; the test suite migrates and resets it:

```sh
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=tasksure_test DB_USERNAME=tasksure DB_PASSWORD="your-local-test-password" php artisan test
```

For MySQL compatibility checks use the same command with `DB_CONNECTION=mysql` and `DB_PORT=3306`, against a separate test database. Never point tests at the working or production database. CI runs SQLite, PostgreSQL and MySQL. See [validation](docs/VALIDATION.md) for checks actually performed.

## Reporting definitions

Every screen/export uses the same selected cohort: tasks **assigned/created**, **currently due**, or **approved** in the chosen inclusive SAST date range. Assigned counts mean assignment records in that cohort, not necessarily assignments created during the period unless that basis is selected. Pending = assigned/in progress/changes requested; awaiting review is separate. Cancelled tasks appear in selected counts and details, and are excluded from submission punctuality denominators. On-time first-submission rate = on-time first submissions / non-cancelled tasks with a first submission. First and accepted attempts use their own retained deadline; approval time does not determine punctuality. Report ownership is the currently responsible employee; prior reassignments remain in activity history. Counts are not a productivity score or hours worked.

## External reminders

In-app delivery is complete. SMTP is configurable via `MAIL_*` and `TASK_EMAIL_ENABLED=true`; use real SMTP credentials and a verified sender. Password reset also requires a real mail transport. Log/array mailers are diagnostic only and are rejected for external delivery. Workers record `queued`, `sent`, `failed` or `disabled`; failed jobs can be inspected with `php artisan queue:failed` and retried with `php artisan queue:retry` after correcting the provider.

WhatsApp is intentionally unconfigured. `App\Contracts\WhatsAppGateway` is a replaceable interface; the default implementation throws a clear configuration error. A real adapter, verified recipient numbers, provider templates/consent requirements and secure credentials must be added once the client chooses the provider and channel. There is no simulated successful delivery. No external provider was tested in this environment.

## Deployment and backups

See the numbered [Railway/PostgreSQL deployment checklist](docs/DEPLOYMENT.md) and [backup/restore](docs/BACKUPS.md). [.env.railway.example](.env.railway.example) supplies Railway variable references without credentials. Source, migrations, tests and configuration are delivered in this checkout. Applying Railway infrastructure is a separate step.
