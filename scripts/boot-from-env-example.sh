#!/usr/bin/env bash
# boot-from-env-example.sh - proves that the application boots from .env.example alone (FND-01).
#
# Steps: copy .env.example to .env, generate the application key, migrate, create the Admin with
# kokpit:install, then check that the panel login route is registered. CI runs it before Pest; the same
# script runs in a fresh clone inside DDEV.
#
# Input (environment):
#   KOKPIT_ADMIN_PASSWORD  required. The Admin password for kokpit:install --no-interaction. The caller
#                          generates it; this script never prints it and never writes it to .env.
#   DB_*, REDIS_*          optional. Real environment variables win over the values copied from .env.example.
#
# Refuses to run when .env already exists (it would overwrite the developer's configuration) or when the
# password is empty. Exit 0 = booted; any other exit = the named step failed.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

fail() {
  printf 'boot-from-env-example: %s\n' "$*" >&2
  exit 1
}

[ ! -e .env ] || fail ".env already exists; refusing to overwrite it (run this in a fresh checkout)"
[ -n "${KOKPIT_ADMIN_PASSWORD:-}" ] || fail "KOKPIT_ADMIN_PASSWORD is empty"
[ -f .env.example ] || fail ".env.example is missing"

cp .env.example .env || fail "could not copy .env.example to .env"

php artisan key:generate --force --no-interaction || fail "key:generate failed"
php artisan migrate --force --no-interaction || fail "migrate failed"

# KOKPIT_ADMIN_PASSWORD reaches the command through the inherited environment, never through an argument.
export KOKPIT_ADMIN_PASSWORD
php artisan kokpit:install --no-interaction --name="Boot Check Admin" --email="boot-check@example.com" \
  || fail "kokpit:install failed"

# Capture first: grep -q closing the pipe early would trip pipefail on a long route list.
routes=$(php artisan route:list --path=admin --no-interaction) || fail "route:list failed"
case "$routes" in
  *admin/login*) ;;
  *) fail "route:list --path=admin does not list admin/login" ;;
esac

printf 'boot-from-env-example: ok\n'
