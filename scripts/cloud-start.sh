#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
export DOCKER_CONFIG="${DOCKER_CONFIG:-/tmp/tasksure-docker}"
docker image inspect tasksure-php >/dev/null 2>&1 || bash scripts/cloud-setup.sh
if docker container inspect tasksure-mysql >/dev/null 2>&1; then docker start tasksure-mysql >/dev/null; else
 docker run -d --name tasksure-mysql --env-file storage/cloud/mysql.env -p 127.0.0.1:3306:3306 -v "$PWD/storage/cloud/mysql:/var/lib/mysql" mysql:8.4 >/dev/null
fi
if docker container inspect tasksure-dev >/dev/null 2>&1; then docker start tasksure-dev >/dev/null; else
 docker run -d --name tasksure-dev --network host --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app tasksure-php php artisan serve --host=0.0.0.0 --port=8080 >/dev/null
fi
run=(docker exec tasksure-dev)
ready=0
for attempt in {1..30}; do
 if "${run[@]}" php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); Illuminate\Support\Facades\DB::select("SELECT 1");' >/dev/null 2>&1; then ready=1;break;fi
 sleep 2
done
[[ "$ready" == 1 ]] || { echo 'MySQL is not available; inspect tasksure-mysql logs.' >&2;exit 1; }
"${run[@]}" php artisan migrate --force
"${run[@]}" php artisan db:seed --force
if ! docker top tasksure-dev -eo pid,args | grep -q 'php artisan queue:work'; then
 docker exec -d tasksure-dev sh -c 'exec php artisan queue:work database --sleep=3 --tries=3 --timeout=90 >> storage/logs/worker.log 2>&1'
fi
if ! docker top tasksure-dev -eo pid,args | grep -q 'php artisan schedule:work'; then
 docker exec -d tasksure-dev sh -c 'exec php artisan schedule:work >> storage/logs/scheduler.log 2>&1'
fi
for attempt in {1..20}; do
 if curl -fsS http://127.0.0.1:8080/health >/dev/null; then echo 'TaskSure database and private upload storage are healthy; web, worker and scheduler started.';exit 0;fi
 sleep 1
done
echo 'Health check failed. Inspect docker logs tasksure-dev.' >&2
exit 1
