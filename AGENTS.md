# TaskSure development

TaskSure uses Laravel 13, PHP 8.4, MySQL 8.4, Blade, Tailwind 4 and FullCalendar.
Read README.md and docs/DEPLOYMENT.md before operational changes.
Use the existing checkout; cloud tasks are isolated and do not require Git worktrees.

- Use frozen dependencies: composer install and npm ci.
- Keep UTC in the database. Interpret business input and display in Africa/Johannesburg.
- Authorize record access on the server. Managers can act only on scoped employees.
- Store evidence outside public/ and serve it only through authenticated policy checks.
- Keep submission attempts and their original deadlines immutable. Approval time is separate.
- Use transactions and row locks for task transitions and recurring generation.
- Never seed demo accounts in production or commit environment secrets.
- Run php artisan test, the MySQL test run described in README.md, npm run build,
  vendor/bin/pint --test, and npm run check:railway after relevant changes.
- Set up and start locally with scripts/cloud-setup.sh and scripts/cloud-start.sh when PHP is absent.
- Deploying or applying Railway infrastructure requires a user-authorized release step.
