#!/usr/bin/env bash
# Matomo - free/libre analytics platform
# https://matomo.org - GPL v3 or later
set -euo pipefail

plugin_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export TZ="${TZ:-Pacific/Auckland}"
export MYSQL_TIMEZONE="${MYSQL_TIMEZONE:-Pacific/Auckland}"
database_image="${MIGRATION_TIMEZONE_DB_IMAGE:-mysql:8.0}"
matomo_ref="${MIGRATION_TIMEZONE_MATOMO_REF:-6.x-dev}"

# Resolve the branch before building so Docker never reuses a stale Matomo checkout.
if [[ ! "$matomo_ref" =~ ^[0-9a-f]{40}$ ]]; then
    matomo_ref="$(git ls-remote https://github.com/matomo-org/matomo.git "refs/heads/$matomo_ref" "refs/tags/$matomo_ref" | head -n 1 | cut -f 1)"
fi
[[ "$matomo_ref" =~ ^[0-9a-f]{40}$ ]] || { echo "Cannot resolve the Matomo test revision." >&2; exit 1; }
echo "Testing Matomo $matomo_ref with $database_image; database timezone $MYSQL_TIMEZONE."

# All resources belong to this invocation. No host ports or existing database volumes are used.
name="migration-timezone-$$"
cleanup() {
    docker rm --force --volumes "$name-php" "$name-db" >/dev/null 2>&1 || true
    docker network rm "$name" >/dev/null 2>&1 || true
    docker image rm "$name-php" >/dev/null 2>&1 || true
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

docker build --build-arg "MATOMO_REF=$matomo_ref" --tag "$name-php" "$plugin_dir/tests/ci"
docker network create "$name" >/dev/null
docker run --detach --name "$name-db" --network "$name" --network-alias database \
    --env "TZ=$MYSQL_TIMEZONE" --env MYSQL_ALLOW_EMPTY_PASSWORD=yes \
    --env MYSQL_DATABASE=matomo_tests "$database_image" \
    --sql-mode=NO_ENGINE_SUBSTITUTION,STRICT_TRANS_TABLES --max-allowed-packet=134217728 >/dev/null

ready=false
for ((attempt=0; attempt<120; attempt++)); do
    if docker exec "$name-db" mysqladmin ping --host=127.0.0.1 --user=root --silent >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 1
done
if [[ "$ready" != true ]]; then
    docker logs "$name-db"
    echo "The timezone test database did not become ready." >&2
    exit 1
fi

# Docker Desktop needs a Windows host path when this script is run from Git Bash.
if command -v cygpath >/dev/null 2>&1; then
    plugin_dir="$(cygpath -m "$plugin_dir")"
fi
MSYS_NO_PATHCONV=1 docker run --name "$name-php" --network "$name" \
    --env "TZ=$TZ" --env "MYSQL_TIMEZONE=$MYSQL_TIMEZONE" \
    --mount "type=bind,source=$plugin_dir,target=/var/www/html/plugins/Migration,readonly" "$name-php"
