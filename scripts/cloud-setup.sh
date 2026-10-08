#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
export DOCKER_CONFIG="${DOCKER_CONFIG:-/tmp/tasksure-docker}"
args=(--network host --secret id=trusted_ca,src=/etc/ssl/certs/ca-certificates.crt --build-arg HTTP_PROXY --build-arg HTTPS_PROXY --build-arg http_proxy --build-arg https_proxy)
# Docker's daemon may have different DNS. Resolve only the configured proxy host, never credentials.
if [[ -n "${HTTPS_PROXY:-}" ]]; then
 proxy_host=$(python3 -c 'import os,urllib.parse; print(urllib.parse.urlparse(os.environ["HTTPS_PROXY"]).hostname)')
 proxy_ip=$(getent ahostsv4 "$proxy_host" | awk 'NR==1 {print $1;exit}')
 [[ -z "$proxy_ip" ]] || args+=(--add-host "$proxy_host:$proxy_ip")
fi
docker build "${args[@]}" -t tasksure-php -f docker/Dockerfile.dev .
run=(docker run --rm --network host --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app -e HTTP_PROXY -e HTTPS_PROXY -e http_proxy -e https_proxy -e COMPOSER_HOME=/tmp/composer tasksure-php)
"${run[@]}" composer install --no-interaction --prefer-dist
npm ci --cache /tmp/tasksure-npm
npm run build
if [[ ! -f .env ]]; then
 cp .env.example .env
 python3 - <<'LOCAL'
from pathlib import Path
import secrets
p=Path('.env');s=p.read_text().replace('DB_PASSWORD=\n','DB_PASSWORD='+secrets.token_urlsafe(32)+'\n').replace('UPLOAD_STORAGE_PATH=/data/uploads','UPLOAD_STORAGE_PATH=/app/storage/app/evidence');p.write_text(s);p.chmod(0o600)
LOCAL
fi
if ! grep -q '^APP_KEY=base64:' .env; then "${run[@]}" php artisan key:generate; fi
mkdir -p storage/cloud storage/app/evidence
chmod 700 storage/cloud
if [[ ! -f storage/cloud/mysql.env ]]; then
 python3 - <<'LOCAL'
from pathlib import Path
import secrets
values=dict(line.split('=',1) for line in Path('.env').read_text().splitlines() if '=' in line and not line.startswith('#'))
p=Path('storage/cloud/mysql.env');p.write_text('MYSQL_DATABASE='+values.get('DB_DATABASE','tasksure')+'\nMYSQL_USER='+values.get('DB_USERNAME','tasksure')+'\nMYSQL_PASSWORD='+values['DB_PASSWORD']+'\nMYSQL_ROOT_PASSWORD='+secrets.token_urlsafe(32)+'\n');p.chmod(0o600)
LOCAL
fi
