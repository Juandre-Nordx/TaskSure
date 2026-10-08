# Railway deployment

## Documentation checked

Official Laravel 13 release/configuration documentation and Railway Laravel, volumes and infrastructure-as-code references were checked on 8 October 2026 via the official `laravel/docs` and `railwayapp/docs` GitHub repositories (the documentation websites were blocked by the cloud network policy).

- https://laravel.com/docs/13.x/releases
- https://laravel.com/docs/13.x/configuration
- https://docs.railway.com/guides/laravel
- https://docs.railway.com/volumes
- https://docs.railway.com/infrastructure-as-code
- https://docs.railway.com/infrastructure-as-code/reference
- https://docs.railway.com/deployments/healthchecks

Current Railway documentation deprecates `railway.json`/`railway.toml` for new services, so this project supplies `.railway/railway.ts` using the official `railway/iac` SDK. Always preview the plan using your current Railway CLI before applying. SDK/CLI versions can change; the lockfile pins the SDK used here.

## Deliberate service arrangement

| Service | Role | Persistent storage |
|---|---|---|
| mysql | MySQL database | Railway-managed database storage |
| web (one replica) | Nginx + PHP-FPM, sessions, authenticated evidence upload/download | Private volume `/data`, files in `/data/uploads` |
| worker (one replica) | Database queue; outbound email only | MySQL; no access to evidence volume |
| scheduler (one replica) | `schedule:work`; recurring generation and reminders every minute | MySQL locks/state; no access to evidence volume |

A volume belongs to one service. Workers and scheduled commands use metadata only; they never read uploads. Do not scale web past one replica with this local-volume architecture. Move evidence to permission-controlled object storage before horizontal scaling. Railway volumes are mounted only at runtime, not during builds or pre-deploy commands. Upload directory creation/ownership is in the web entrypoint. No public storage symlink is created.

## Prepare and apply

1. Push/review this code in the selected repository when authorized. Do not deploy from an empty remote branch.
2. Install the current Railway CLI, log in and link the intended project/environment. Run `npm ci` locally for the SDK.
3. Generate an application key with `php artisan key:generate --show` in a trusted terminal. Store it securely as the Railway **shared variable** `APP_KEY`. Set shared `APP_URL` to the intended HTTPS hostname (use your generated Railway domain, then update and redeploy). Never commit either credential or production `.env`.
4. Run `railway config plan` to inspect `.railway/railway.ts`. Check the GitHub source, branch, volume region and database references. `railway config apply` changes resources and needs the deployment operator's approval.
5. The Dockerfile installs frozen PHP/Node dependencies and compiles assets. All three app services use that image and select `PROCESS_ROLE=web`, `worker` or `scheduler`. The web pre-deploy command runs migrations and seeds **categories only**. No uploads are touched by pre-deploy.
6. Deploy the database and web first; after migrations succeed deploy/start worker and scheduler. If initial parallel deployments started early, redeploy those services after the schema exists. Generate web public networking on its `PORT` (default 8080). Health path is `/health` with a 120-second startup allowance; it checks database connectivity and private upload-directory writability. `/up` only checks framework boot.
7. Create the first administrator inside web with `php artisan tasksure:admin owner@example.com "Store Owner"`, using Railway SSH and the hidden password prompt. Never use the demo seeder or migrate a demo database into production.
8. Confirm HTTPS sign-in, a real employee assignment, upload, submission, review and export. Inspect service logs and queue failures. Run a reminder and recurring task smoke check. Verify upload retrieval after restarting web.

The IaC definition was evaluated and type-checked locally; it was not applied to a Railway account. Railway credentials are not required for developing this app. The Nginx/PHP-FPM image was built and locally smoke-tested against MySQL; a live Railway deployment remains the operator's step.

## Variables

Production defaults are in `.railway/railway.ts`. Keep `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, database sessions/cache/queues, and `LOG_CHANNEL=stderr`. Laravel uses UTC consistently; business timezone is fixed in `config/tasksure.php`. Configure `UPLOAD_STORAGE_PATH=/data/uploads`, `UPLOAD_MAX_KB` (default 10240), and `REMINDER_MINUTES` (default 60). The owner can override reminder minutes in the store settings screen.

SMTP setup requires a real `MAIL_MAILER` (typically smtp), `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`, with `TASK_EMAIL_ENABLED=true` for optional reminder delivery. Set these securely on web and worker; use the same `APP_KEY` and `APP_URL` across all app services. Adjust IaC to preserve/reference these variables before the next apply so it does not restore email-disabled defaults. Test delivery and password reset through the actual provider. Provider acceptance is not proof of recipient receipt.

Do not set `DEMO_PASSWORD` in production. No credentials are supplied in source. MySQL variables reference Railway's database helper, using its private host.

## Operations

The web process uses Nginx and PHP-FPM rather than Laravel's development server. Nginx accepts `PORT`; PHP and Nginx body limits derive from the configured upload limit with multipart headroom. Failures are logged to stderr and Railway service logs; application error details stay hidden in production. Add platform alerts for health failures, queue backlog/failed jobs and volume capacity.

Back up before schema changes. Keep migrations backward compatible across rolling app releases. Do not run `migrate:fresh`, `db:wipe`, demo seeders or development tests against production. Queue workers restart with each deployment; run `queue:restart` after code changes when manually managing them. Monitor the scheduler logs for periodic execution. Generation catches up at most 366 occurrences per template per run; subsequent runs continue catching up, with deduplication.

For local Docker deployment, copy `.env.example`, generate a secure `APP_KEY` and `DB_PASSWORD`, set `APP_ENV=local`, then `docker compose up --build -d db web`. Run `docker compose exec web php artisan migrate` and `docker compose exec web php artisan db:seed`, then `docker compose up -d worker scheduler`. This uses named database and evidence volumes. `docker compose down` retains them; `down -v` destroys them.
