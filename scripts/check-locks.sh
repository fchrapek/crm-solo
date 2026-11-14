#!/usr/bin/env bash
#
# The financial row-lock tests. SQLite has no row locks, so the main suite skips them;
# this runs them on a throwaway database in the local MariaDB container.
#
# Usage:  scripts/check-locks.sh          (composer run check:locks)
#
# The database is migrated fresh for every test. Its name comes from
# SETTLEMENT_LOCK_DB_DATABASE (default crm_solo_locktest); the user and
# password are the ones in .env.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

env_value() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

db="${SETTLEMENT_LOCK_DB_DATABASE:-crm_solo_locktest}"
user="$(env_value DB_USERNAME)"

if [[ ! "$db" =~ ^[a-z0-9_]+$ || ! "$user" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Unexpected database or user name." >&2
  exit 2
fi

# PROCESS lets the tests see a writer waiting on a lock.
docker compose exec -T mariadb sh -c 'mariadb -uroot -p"${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}"' <<SQL
CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON \`$db\`.* TO '$user'@'%';
GRANT PROCESS ON *.* TO '$user'@'%';
SQL

SETTLEMENT_LOCK_DB_DATABASE="$db" \
SETTLEMENT_LOCK_DB_USERNAME="$user" \
SETTLEMENT_LOCK_DB_PASSWORD="$(env_value DB_PASSWORD)" \
SETTLEMENT_LOCK_DB_PORT="${SETTLEMENT_LOCK_DB_PORT:-$(env_value DB_PORT)}" \
  php artisan test --filter=MariaDbLockingTest
