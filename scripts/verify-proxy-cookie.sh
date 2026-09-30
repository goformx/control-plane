#!/bin/sh
set -eu

suffix="$$"
php_image="${GOFORMX_PHP_IMAGE:-goformx-control-plane-production:context-probe}"
web_image="${GOFORMX_WEB_IMAGE:-goformx-control-plane-web:context-probe}"
network="goformx-proxy-smoke-$suffix"
volume="goformx-proxy-smoke-$suffix"
php="goformx-php-smoke-$suffix"
web="goformx-web-smoke-$suffix"
headers="$(mktemp)"
cleanup() {
  docker rm -f "$web" "$php" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
  docker volume rm "$volume" >/dev/null 2>&1 || true
  rm -f "$headers"
}
trap cleanup EXIT

docker network create "$network" >/dev/null
docker volume create "$volume" >/dev/null
common_env='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
if timeout 10s docker run --rm --platform linux/amd64 -v "$volume:/app/storage" \
  -e APP_ENV=production -e WAASEYAA_APP_SECRET="$common_env" \
  "$php_image" >"$headers" 2>&1; then
  echo 'PHP-FPM started before database initialization' >&2
  exit 1
fi
grep -q 'Field-access preflight failed; PHP-FPM was not started.' "$headers" || {
  echo 'Uninitialized database did not fail through the preflight gate' >&2
  exit 1
}
docker run --rm --platform linux/amd64 -v "$volume:/app/storage" \
  -e APP_ENV=local -e WAASEYAA_APP_SECRET="$common_env" \
  "$php_image" \
  php vendor/bin/waaseyaa install:init >/dev/null
docker run -d --name "$php" --network "$network" --network-alias control-plane-php \
  --platform linux/amd64 -v "$volume:/app/storage" \
  -e APP_ENV=production -e APP_URL=https://www.goformx.test \
  -e GOFORMX_PUBLIC_API_URL=https://api.goformx.test \
  -e WAASEYAA_APP_SECRET="$common_env" \
  "$php_image" >/dev/null
docker run -d --name "$web" --network "$network" \
  --platform linux/amd64 -p 127.0.0.1::8080 \
  "$web_image" >/dev/null
port="$(docker port "$web" 8080/tcp | sed 's/.*://')"

ready=0
for attempt in 1 2 3 4 5 6 7 8 9 10; do
  if curl -ksS -D "$headers" -o /dev/null -H 'X-Forwarded-Proto: https' \
    "http://127.0.0.1:$port/login"; then
    if grep -q '^HTTP/1.1 200' "$headers"; then ready=1; break; fi
  fi
  sleep 1
done
if [ "$ready" -ne 1 ]; then
  echo 'Routed login did not become ready' >&2
  grep '^HTTP/' "$headers" >&2 || true
  docker logs --tail 12 "$php" >&2 || true
  exit 1
fi

docker exec --user root "$php" test -r /app/.waaseyaa/field-access-preflight.json
docker exec --user root "$php" sh -c \
  "ps -o user,args | grep -q 'www-data.*php-fpm: pool www'" || {
  echo 'PHP-FPM request workers are not running as www-data' >&2
  exit 1
}
cookies="$(grep -i '^set-cookie:' "$headers" || true)"
test -n "$cookies" || { echo 'No session/CSRF cookie was issued' >&2; exit 1; }
printf '%s\n' "$cookies" | while IFS= read -r cookie; do
  printf '%s\n' "$cookie" | grep -qi '; secure' || {
    echo 'A routed production cookie lacks Secure' >&2; exit 1;
  }
done
echo 'Routed production login and Secure cookies: ok'
