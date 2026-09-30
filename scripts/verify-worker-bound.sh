#!/bin/sh
set -eu

suffix="$$"
php_image="${GOFORMX_PHP_IMAGE:-goformx-control-plane-production:context-probe}"
web_image="${GOFORMX_WEB_IMAGE:-goformx-control-plane-web:context-probe}"
network="goformx-worker-smoke-$suffix"
volume="goformx-worker-smoke-$suffix"
php="goformx-worker-php-$suffix"
web="goformx-worker-web-$suffix"
upstream="goformx-worker-upstream-$suffix"
scratch="$(mktemp -d)"
cleanup() {
  docker rm -f "$web" "$php" "$upstream" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
  docker volume rm "$volume" >/dev/null 2>&1 || true
  rm -rf "$scratch"
}
trap cleanup EXIT

# This fixture replaces the front controller only in this disposable container.
# Neither image contains a worker-test endpoint.
cat >"$scratch/index.php" <<'PHP'
<?php
if (($_GET['mode'] ?? '') === 'large') {
    header('Content-Type: application/octet-stream');
    header('Content-Length: 8388608');
    for ($i = 0; $i < 8; $i++) {
        echo str_repeat('X', 1024 * 1024);
    }
    return;
}
if (($_GET['mode'] ?? '') === 'drip') {
    header('Content-Type: text/plain');
    header('X-Accel-Buffering: no');
    $stream = fopen('http://upstream-php:8000/upstream.php', 'r');
    if ($stream === false) {
        http_response_code(502);
        return;
    }
    stream_set_timeout($stream, 5);
    while (($line = fgets($stream)) !== false) {
        echo "upstream:$line";
        flush();
    }
    fclose($stream);
    echo "finished\n";
    return;
}
header('Content-Type: text/plain');
echo "ready\n";
PHP
cat >"$scratch/upstream.php" <<'PHP'
<?php
header('Content-Type: text/plain');
for ($i = 0; $i < 40; $i++) {
    echo "tick\n";
    flush();
    sleep(1);
}
PHP

docker network create "$network" >/dev/null
docker volume create "$volume" >/dev/null
common_env='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
docker run --rm --platform linux/amd64 -v "$volume:/app/storage" \
  -e APP_ENV=local -e WAASEYAA_APP_SECRET="$common_env" \
  "$php_image" php vendor/bin/waaseyaa install:init >/dev/null
docker run -d --name "$upstream" --network "$network" --network-alias upstream-php \
  --platform linux/amd64 -v "$scratch/upstream.php:/tmp/upstream.php:ro" \
  "$php_image" php -d output_buffering=Off -d zlib.output_compression=Off \
  -S 0.0.0.0:8000 -t /tmp >/dev/null
docker run -d --name "$php" --network "$network" --network-alias control-plane-php \
  --platform linux/amd64 -v "$volume:/app/storage" \
  -v "$scratch/index.php:/app/public/index.php:ro" \
  -e APP_ENV=production -e APP_URL=https://www.goformx.test \
  -e WAASEYAA_APP_SECRET="$common_env" "$php_image" >/dev/null
docker run -d --name "$web" --network "$network" \
  --platform linux/amd64 -p 127.0.0.1::8080 "$web_image" >/dev/null
port="$(docker port "$web" 8080/tcp | sed 's/.*://')"
url="http://127.0.0.1:$port/worker-bound"

ready=0
attempt=0
while [ "$attempt" -lt 10 ]; do
  attempt=$((attempt + 1))
  if curl -fsS --max-time 3 "$url" -o "$scratch/ready" \
    && grep -qx ready "$scratch/ready"; then ready=1; break; fi
  sleep 1
done
if [ "$ready" -ne 1 ]; then
  echo 'Routed PHP worker did not become ready' >&2
  docker logs --tail 15 "$php" >&2 || true
  exit 1
fi
docker exec "$php" php-fpm -tt 2>"$scratch/fpm-config"
grep -q 'request_terminate_timeout = 25s' "$scratch/fpm-config"
grep -q 'request_terminate_timeout_track_finished = yes' "$scratch/fpm-config"

# Every drip arrives well inside Nginx's 30-second read-idle limit.
start="$(date +%s)"
curl -sS --max-time 45 -o "$scratch/drip" "$url?mode=drip" \
  >"$scratch/drip-stdout" 2>"$scratch/drip-stderr" &
drip_pid=$!
sleep 2
curl -fsS --max-time 20 -o "$scratch/large" "$url?mode=large"
test "$(wc -c <"$scratch/large" | tr -d ' ')" -eq 8388608 || {
  echo 'Concurrent 8 MiB response was incomplete' >&2
  exit 1
}
wait "$drip_pid" || true
elapsed="$(($(date +%s) - start))"
if [ "$elapsed" -lt 22 ] || [ "$elapsed" -gt 37 ]; then
  echo "Drip ended after ${elapsed}s; expected FPM's 25-second backstop" >&2
  exit 1
fi
if grep -q '^finished$' "$scratch/drip"; then
  echo 'Drip finished instead of being terminated' >&2
  exit 1
fi
ticks="$(grep -c '^upstream:tick$' "$scratch/drip" || true)"
if [ "$ticks" -lt 10 ]; then
  echo "FPM received only $ticks upstream ticks before ending" >&2
  exit 1
fi
timeout_log="$(docker logs "$php" 2>&1 | grep 'execution timed out' || true)"
if [ -z "$timeout_log" ]; then
  echo 'FPM did not log worker termination' >&2
  docker logs --tail 20 "$php" >&2 || true
  exit 1
fi
curl -fsS --max-time 10 "$url" -o "$scratch/recovered"
grep -qx ready "$scratch/recovered" || {
  echo 'PHP pool did not recover after worker termination' >&2
  exit 1
}
echo "Routed upstream drip ($ticks ticks), worker bound (${elapsed}s), concurrent 8 MiB response, and pool recovery: ok"
printf '%s\n' "$timeout_log"
