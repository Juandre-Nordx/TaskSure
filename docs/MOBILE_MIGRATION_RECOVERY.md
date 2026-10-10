# Recover mobile login without changing existing client records

`POST /api/mobile/v1/login` with PostgreSQL error `42P01`, relation
`personal_access_tokens` does not exist, fails when Sanctum creates a token.
Employees created before the mobile app use their existing accounts and passwords.
No account recreation, password reset or production seeding is required.

## What the repository contains

- `composer.lock` and the installed package use Sanctum **4.3.3**.
- There is exactly one application migration creating `personal_access_tokens`:
  `database/migrations/2026_10_10_000001_create_mobile_access_tables.php`.
  Its token columns, indexes and types match Sanctum 4.3.3's bundled migration.
- This file is committed on GitHub `main`, starting with commit `c418e4e`.
  The root Dockerfile copies it; `.dockerignore` does not exclude migrations.
  The deployed image's commit and migration status still need checking in Railway.
- The migration's `up()` creates `personal_access_tokens`, `push_devices` and
  `push_deliveries`, with indexes and foreign keys to existing users and alerts.
  It performs no inserts, updates or deletes on existing client tables. Laravel
  also records the completed migration in `migrations`.
- The four earlier migrations create the original schema. The task-management
  migration additionally adds `role` and `active` columns to `users`, with
  defaults. They should already be recorded on the working website. Review any
  unexpected pending older migration before running it.
- Neither the Dockerfile nor the container entrypoint runs migrations or seeders.
  The Railway template and deployment documentation now use a pre-deploy
  migration followed by the read-only `tasksure:mobile-schema` check, without seeding.

## Inspect the existing deployed service

Back up the **existing** PostgreSQL database using your normal Railway backup
process. Keep the current database, `APP_KEY`, users, tasks and evidence volume.
Do not apply the new-project Railway IaC template to this existing project.

On your computer, with the Railway CLI installed, run from your TaskSure checkout:

```powershell
railway login
railway link
railway ssh
```

Choose the existing production project/environment and the **TaskSure web
service**, not the PostgreSQL, worker or scheduler service. `railway ssh` opens
the deployed container; `railway run` would execute locally instead.

Inside the deployed container, run these read-only checks:

```sh
cd /app
ls database/migrations/2026_10_10_000001_create_mobile_access_tables.php
php artisan migrate:status
php artisan tinker --execute='dump(["driver" => DB::connection()->getDriverName(), "database_schema" => DB::selectOne("select current_database() as database_name, current_schema() as schema_name"), "database_url_override" => (bool) DB::connection()->getConfig("url"), "token_table" => Schema::hasTable("personal_access_tokens"), "devices_table" => Schema::hasTable("push_devices"), "deliveries_table" => Schema::hasTable("push_deliveries")]);'
```

The connection check prints no passwords, keys or connection URLs. Confirm
`pgsql`, the existing production database and its expected schema (`public` in
this repository). Check the web service's database variables reference the same
existing PostgreSQL service as the worker and scheduler. Laravel's `DB_URL`, if
set, overrides separate `DB_*` fields. Check the effective connection before
changing variables; do not point the app at a new empty database.

If the file is missing, first deploy the release containing that migration. If
the mobile migration is already `Ran` but the table is missing, stop and
investigate the database/schema, cached configuration and deployed commit. Do
not delete migration history, add a duplicate migration or reset the database.

## Apply only the existing pending mobile migration

When the older migrations are `Ran`, the mobile migration is `Pending` and the
three mobile tables are absent, review its generated SQL:

```sh
php artisan migrate --pretend --force --path=database/migrations/2026_10_10_000001_create_mobile_access_tables.php
```

Then apply that same migration to the current connection:

```sh
php artisan migrate --force --path=database/migrations/2026_10_10_000001_create_mobile_access_tables.php
php artisan migrate:status
```

This is the immediate repair for the currently deployed code; no APK rebuild
is needed. Expect the mobile migration to be `Ran`. If only some mobile tables
exist, stop and inspect that partial state before executing table creation.
Never use database resets, migration refreshes, rollback or production seeders
for this repair.

## Keep migrations in Railway's controlled deployment step

In **TaskSure web → Settings → Deploy → Pre-Deploy Command**, remove any seeder
command. On the current release, the command can be:

```sh
php artisan migrate --force
```

After deploying the repository changes that add `tasksure:mobile-schema`, use:

```sh
php artisan migrate --force && php artisan tasksure:mobile-schema
```

Review pending migrations for each release. The pre-deploy step runs after image
build and before rollout, using the service's production database variables.
It runs only on web; worker and scheduler have no migration commands. Updating
the repository template alone does not change an existing Railway service's
dashboard settings. Do not add a build command, Dockerfile `RUN` or container
startup migration. Volumes are not needed for this database-only check.

The new read-only command can also confirm the effective connection, migration
record and required columns after deployment:

```sh
php artisan tasksure:mobile-schema --json
```

It exits zero only when all three mobile tables have the required columns and
the migration is recorded. It prints database/schema identity and whether a URL
override exists, without credentials. A nonzero result blocks deployment and
calls for investigation; it never repairs or modifies data itself.

## Verification and remaining production checks

The forward-only regression check runs with:

```sh
php artisan test --filter=MobileMigrationDeploymentTest --compact
```

It uses in-memory SQLite by default. For PostgreSQL/MySQL, use a disposable test
database ending in `_test` with no URL override. It creates unused prefixed test
tables, never resets a database, and never calls seeders. Do not run it in the
production container. SQL test tables are intentionally retained in the test
database instead of being dropped.

Verified locally on SQLite, PostgreSQL 18 and MySQL 8.4: accounts and task
history created **before** the mobile migration initially reproduce login HTTP
500; the forward migration preserves all rows across 19 original tables and
the evidence file. The same employee password then gives login HTTP 200,
authenticated profile/task/photo access HTTP 200, device registration HTTP 200
and logout HTTP 200. After logout, the token is revoked and profile access gives
HTTP 401. Manager login remains HTTP 403. Re-running the forward migration is a
no-op. The web build, PHP formatting and Railway template checks pass.

Production migration status, the deployed image and database identity could not
be inspected here: no Railway credentials or connection are available. The
live endpoint was also blocked by the workspace's outbound proxy. The actual
phone and production login therefore still need verification in your account.
Sign in on the installed app using an existing active **employee** account,
open assigned tasks and an old evidence photo, then sign out and back in. Check
Railway logs for successful migration and the absence of `42P01` login errors.
Managers continue using the existing web dashboard.
