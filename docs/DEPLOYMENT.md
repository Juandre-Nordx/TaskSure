# Deploy TaskSure on Railway with PostgreSQL

Use the Railway dashboard checklist below. The repository includes a Dockerfile that builds the frontend and runs Nginx/PHP-FPM; no custom build or start command is needed.

The production Dockerfile uses ordinary `RUN` instructions because Railway's Metal builder accepts cache mounts only. Trusted certificate secret mounts are confined to `docker/Dockerfile.dev` for the managed cloud workspace. If a deployment reports `--mount=type=secret` as unsupported, deploy the latest `main` commit and confirm the service uses the root `Dockerfile`.

## Deployment checklist

1. **Open your Railway project and check PostgreSQL.** Use your existing PostgreSQL service in the same project/environment. Create one with **New → Database → PostgreSQL** only if you do not already have one. Back up an existing database before migrations. The examples use the service name `Postgres`; replace that name in variable references if yours differs.

2. **Connect the web service to GitHub.** Choose **New → GitHub Repo → Juandre-Nordx/TaskSure**, branch `main`, and name the service `web`. Use the repository root and the included `Dockerfile`. Configure the remaining settings before deploying. Leave custom build/start commands empty: `PROCESS_ROLE` selects the process through the Docker entrypoint.

3. **Set production variables.** Generate a key in a trusted local checkout with dependencies installed:

   ```sh
   php artisan key:generate --show
   ```

   If local PHP is unavailable, this Docker command generates the same 32-byte key format without needing the app:

   ```sh
   docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
   ```

   In `web` **Variables → Raw Editor**, paste [.env.railway.example](../.env.railway.example). Replace `APP_KEY` with that key and `APP_URL` with your HTTPS web hostname. Database values must reference your existing PostgreSQL service:

   ```dotenv
   DB_CONNECTION=pgsql
   DB_HOST=${{Postgres.PGHOST}}
   DB_PORT=${{Postgres.PGPORT}}
   DB_DATABASE=${{Postgres.PGDATABASE}}
   DB_USERNAME=${{Postgres.PGUSER}}
   DB_PASSWORD=${{Postgres.PGPASSWORD}}
   ```

   Use Railway's private database host. Keep `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, database sessions/cache/queues, and `LOG_CHANNEL=stderr`. Remove a conflicting `DB_URL` if one was previously configured; Laravel gives it precedence over separate database fields. Never commit a production `.env` or set `DEMO_PASSWORD` in production.

4. **Attach private file storage to web.** Add a persistent volume mounted at `/data`, with `UPLOAD_STORAGE_PATH=/data/uploads`. Keep web at **one replica** and in the volume's region. Uploaded evidence is served through authenticated policy checks. The entrypoint creates the directory at runtime because Railway volumes are unavailable during builds and pre-deploy commands.

5. **Configure migrations and health.** On `web`, set **Pre-Deploy Command** to:

   ```sh
   php artisan migrate --force && php artisan db:seed --force
   ```

   The default seeder creates task categories only. Set **Healthcheck Path** to `/health` and **Healthcheck Timeout** to `120` seconds. This endpoint checks database connectivity and private storage writability; `/up` checks framework boot only. Enable an on-failure restart policy.

6. **Set the public URL and deploy web.** In **Settings → Networking → Generate Domain**, use target port `8080` (the template sets `PORT=8080`). Put the resulting `https://…` URL in `APP_URL` and deploy/redeploy. Confirm the migration command succeeds and `/health` returns HTTP 200. A custom domain can be added later; update `APP_URL` and redeploy all app services when changing it.

7. **Create the worker and scheduler services.** Add two services from the same GitHub repository/branch and Dockerfile. Give both the same `APP_KEY`, `APP_URL`, PostgreSQL references, session/cache/queue and mail variables as web. Set `PROCESS_ROLE=worker` on one and `PROCESS_ROLE=scheduler` on the other. Keep one replica each and an on-failure restart policy. Set no pre-deploy command or HTTP healthcheck on these two services. They need no public domain or evidence volume. Deploy them after web's migrations succeed. Do not enable service sleeping/serverless mode on these long-running processes.

8. **Create the first administrator.** Install/login to the Railway CLI and link this project/environment. Open the running web container:

   ```sh
   railway ssh --service web
   php artisan tasksure:admin owner@example.com "Store Owner"
   ```

   The command asks for the password without displaying it. `railway run` runs commands on your local machine, so use SSH for commands inside the deployed app. No public registration or default production password is provided.

9. **Test the deployed workflow.** Sign in over HTTPS, create a manager and employee, assign/start a task, upload an image/PDF, submit it, approve it, and export a report. Restart web and confirm the uploaded file remains available. Check worker/scheduler logs, `php artisan queue:failed`, recurring tasks and reminder generation.

10. **Enable email and backups.** Configure SMTP on all app services with `TASK_EMAIL_ENABLED=true`, `MAIL_MAILER=smtp`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME`. Use the provider's TLS settings and verified sender. Test password reset and actual reminder receipt. Without SMTP, in-app notifications work but external email does not. WhatsApp needs a real provider adapter; it is currently unconfigured. Enable backups for both PostgreSQL and the evidence volume, then rehearse [backup/restore](BACKUPS.md).

## Service arrangement

| Service | Process | Persistent storage |
|---|---|---|
| Your existing PostgreSQL service | Database | Railway database volume |
| web, one replica | Nginx + PHP-FPM | Private volume `/data` |
| worker, one replica | `php artisan queue:work database --sleep=3 --tries=3 --timeout=90` | PostgreSQL queue; no evidence volume |
| scheduler, one replica | `php artisan schedule:work` | PostgreSQL locks/state; no evidence volume |

A volume belongs to one service. Workers and scheduled commands use metadata and do not read uploads. Move evidence to permission-controlled object storage before scaling web horizontally. Application timestamps are UTC; business input/display uses Africa/Johannesburg.

## Optional infrastructure as code

`.railway/railway.ts` uses the official `railway/iac` SDK and creates PostgreSQL, an evidence volume, web, worker and scheduler. It is intended for a **new project**. For your existing PostgreSQL project, use the dashboard checklist above or import/adapt resources before using IaC; applying it unchanged can create a second database.

For a new project, install/link the Railway CLI, run `npm ci`, set shared variables `APP_KEY` and `APP_URL`, and inspect `railway config plan` before `railway config apply`. Review database references, GitHub source and volume region. Preserve/reference any SMTP overrides before future applies. Current Railway documentation deprecates `railway.json`/`railway.toml` for new services, so those files are not supplied.

## Local Docker Compose

Copy `.env.example` to `.env` if you do not have local configuration, generate a secure `APP_KEY` and `DB_PASSWORD`, and keep `APP_ENV=local`. Run `docker compose up --build -d db web`, then `docker compose exec web php artisan migrate` and `docker compose exec web php artisan db:seed`. Create an administrator and start `docker compose up -d worker scheduler`. Compose uses PostgreSQL 18 with separate named database/evidence volumes. Existing cloud-workspace scripts retain their MySQL development fixture; Compose does not migrate that data. `docker compose down` retains volumes; `down -v` deletes them.

## Documentation and validation

Official Laravel 13 configuration and Railway Laravel, PostgreSQL, volumes and infrastructure-as-code references were checked on 8 October 2026 through the official `laravel/docs` and `railwayapp/docs` GitHub repositories:

- https://laravel.com/docs/13.x/configuration
- https://docs.railway.com/guides/laravel
- https://docs.railway.com/databases/postgresql
- https://docs.railway.com/volumes
- https://docs.railway.com/infrastructure-as-code
- https://docs.railway.com/infrastructure-as-code/reference
- https://docs.railway.com/deployments/healthchecks

See [validation](VALIDATION.md) for checks actually performed. A live Railway deployment and production backup/restore remain to be performed in your account.
