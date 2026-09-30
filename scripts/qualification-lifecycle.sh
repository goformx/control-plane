#!/bin/sh
set -eu
export DOCKER_HOST=unix:///var/run/docker.sock
image=${GOFORMX_PHP_IMAGE:-goformx-control-plane-production:context-probe}
web_image=${GOFORMX_WEB_IMAGE:-goformx-control-plane-web:context-probe}
suffix="$(date +%s)-$$"
volume="goformx-lifecycle-$suffix"
container="goformx-lifecycle-$suffix"
web="goformx-lifecycle-web-$suffix"
network="goformx-lifecycle-net-$suffix"
cleanup() { docker rm -f "$web" "$container" >/dev/null 2>&1 || true; docker volume rm "$volume" >/dev/null 2>&1 || true; docker network rm "$network" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker volume create "$volume" >/dev/null
docker network create "$network" >/dev/null
run() { docker run --rm -v "$volume:/app/storage" -e APP_ENV=local "$image" "$@"; }
run php vendor/bin/waaseyaa install:init
docker run -d --name "$container" --network "$network" --network-alias control-plane-php -v "$volume:/app/storage" -e APP_URL=https://www.goformx.test -e GOFORMX_PUBLIC_API_URL=https://api.goformx.test -e WAASEYAA_APP_SECRET=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= "$image" >/dev/null
docker run -d --name "$web" --network "$network" -p 127.0.0.1::8080 "$web_image" >/dev/null
port=$(docker port "$web" 8080/tcp | sed 's/.*://')
sleep 2
docker exec "$container" php -r 'echo "PHP ",PHP_VERSION," PDO SQLite ",PDO::getAvailableDrivers()[0],"\n";'
docker exec "$container" php -r '$d=new SQLite3("/app/storage/waaseyaa.sqlite"); $d->exec("INSERT INTO state VALUES ('\''qualification-marker'\'', '\''preserved'\'')"); $d->enableExceptions(true); $b=new SQLite3("/app/storage/consistent-backup.sqlite"); if (!$d->backup($b)) exit(1); echo "SQLite online backup complete\n";'
docker exec "$container" php vendor/bin/waaseyaa migrate --verify
docker exec "$container" php vendor/bin/waaseyaa migrate
docker exec "$container" php -r '$d=new SQLite3("/app/storage/waaseyaa.sqlite"); if ($d->querySingle("SELECT value FROM state WHERE name='\''qualification-marker'\''") !== "preserved") exit(1); echo "populated migration data preserved\n";'
docker restart "$container" >/dev/null
sleep 2
docker exec "$container" php -r '$d=new SQLite3("/app/storage/waaseyaa.sqlite"); if ($d->querySingle("SELECT value FROM state WHERE name='\''qualification-marker'\''") !== "preserved") exit(1); echo "restart data preserved\n";'
docker stop "$container" >/dev/null
run php -r '$d=new SQLite3("/app/storage/waaseyaa.sqlite"); $d->exec("DELETE FROM state WHERE name='\''qualification-marker'\''"); $b=new SQLite3("/app/storage/consistent-backup.sqlite"); if (!$b->backup($d)) exit(1); if ($d->querySingle("SELECT value FROM state WHERE name='\''qualification-marker'\''") !== "preserved") exit(1); if ($d->querySingle("PRAGMA integrity_check") !== "ok") exit(1); echo "consistent restore verified\n";'
docker start "$container" >/dev/null
sleep 2
docker exec -e APP_URL=https://changed-config.goformx.test "$container" php vendor/bin/waaseyaa about >/dev/null
echo 'APP_URL config change accepted with existing artifact: separate release/config binding required'
docker exec "$container" php -r '$p="/app/.waaseyaa/field-access-preflight.json"; $a=json_decode(file_get_contents($p),true); $a["framework_version"]="rollback-mismatch"; file_put_contents($p,json_encode($a));'
if docker exec "$container" php vendor/bin/waaseyaa about > /tmp/goformx-lifecycle-stale.log 2>&1; then echo 'ERROR stale artifact accepted'; exit 1; fi
cat /tmp/goformx-lifecycle-stale.log
grep -q 'preflight is stale' /tmp/goformx-lifecycle-stale.log
status=$(curl -sS -o /tmp/goformx-lifecycle-response -w '%{http_code}' "http://127.0.0.1:$port/login")
test "$status" = 500
echo 'Routed login rejected stale artifact with HTTP 500'
docker restart "$container" >/dev/null
sleep 2
status=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/login")
if [ "$status" != 200 ]; then echo "Restart routed status: $status"; docker logs --tail 12 "$container"; exit 1; fi
echo 'Restart regenerated artifact and routed login recovered HTTP 200'
echo 'Lifecycle rehearsal finished; inspect command support and artifact rejection evidence.'
