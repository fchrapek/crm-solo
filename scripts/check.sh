#!/usr/bin/env bash
#
# The checks CI runs before anything deploys, runnable locally in one command.
# .github/workflows/ci.yml calls this script per job, so the two cannot drift.
#
# Usage:  scripts/check.sh [php|js|audit|all]     (default: all)
#
# Expects an installed tree: vendor/, node_modules/ and a .env with APP_KEY.
#
# Audit rule: a high or critical advisory (or one with no severity) in a
# production Composer package fails; dev-only and lower-severity ones are
# reported. Every JS advisory of high or critical fails, dev or not: the
# browser bundle and its build chain are both in package.json "dependencies",
# so bun cannot tell them apart. An advisory nobody can fix yet goes into
# BUN_AUDIT_IGNORE below with the reason beside it, never a blanket pass.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

# GHSA ids, each with a one-line reason and the date it was added.
BUN_AUDIT_IGNORE=()

step(){ printf '\n\033[1m== %s\033[0m\n' "$1"; }

check_php() {
  step "Pint"
  vendor/bin/pint --test
  step "PHP tests"
  php artisan test --parallel
}

check_js() {
  # The route helpers TypeScript imports are generated, not committed.
  step "Wayfinder"
  php artisan wayfinder:generate
  step "Types"
  bun run types
  step "ESLint"
  bunx eslint .
  step "Token linter"
  bun run lint:tokens
  step "JS tests"
  bun test
  step "Production build"
  bun run build
}

check_audit() {
  step "Composer audit (report)"
  composer audit --locked --abandoned=report || true
  step "Composer audit (gate: production packages, high and above)"
  composer audit --locked --no-dev --abandoned=report --ignore-severity low --ignore-severity medium

  local ignore=()
  for id in "${BUN_AUDIT_IGNORE[@]+"${BUN_AUDIT_IGNORE[@]}"}"; do
    ignore+=("--ignore=$id")
  done
  step "bun audit (report)"
  bun audit "${ignore[@]+"${ignore[@]}"}" || true
  step "bun audit (gate: high and above)"
  bun audit --audit-level=high "${ignore[@]+"${ignore[@]}"}"
}

case "${1:-all}" in
  php) check_php ;;
  js) check_js ;;
  audit) check_audit ;;
  all) check_php; check_js; check_audit ;;
  *) echo "usage: $0 [php|js|audit|all]" >&2; exit 2 ;;
esac

printf '\n\033[32mChecks passed: %s\033[0m\n' "${1:-all}"
