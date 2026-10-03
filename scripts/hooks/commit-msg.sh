#!/usr/bin/env bash
#
# Checks a commit message against the house style: "Area: what changed", the
# area from .github/commit-areas.txt, a subject of at most 72 characters, and
# no em or en dashes anywhere in the message.
#
# Usage:  scripts/hooks/commit-msg.sh <message-file>      (git commit-msg hook)
#         scripts/hooks/commit-msg.sh --subject "<text>"  (CI, for PR titles)

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

if [ "${1:-}" = "--subject" ]; then
  message="${2:-}"
else
  # Comment lines are git's template, never part of the commit.
  message="$(grep -v '^#' "${1:?message file}" || true)"
fi
subject="$(printf '%s\n' "$message" | head -n1)"

case "$subject" in
  "Merge "* | "Revert "* | "fixup! "* | "squash! "* | "amend! "*) exit 0 ;;
esac

errors=()

area="${subject%%: *}"
if [ "$area" = "$subject" ] || [ -z "${subject#*: }" ]; then
  errors+=("the subject must read \"Area: what changed\"")
elif ! grep -v '^#' .github/commit-areas.txt | grep -qxF "$area"; then
  errors+=("unknown area \"$area\"; pick one from .github/commit-areas.txt or add it there")
fi

# Characters, not bytes, whatever the locale: drop UTF-8 continuation bytes.
if [ "$(printf '%s' "$subject" | LC_ALL=C tr -d '\200-\277' | wc -c | tr -d ' ')" -gt 72 ]; then
  errors+=("the subject is longer than 72 characters")
fi

if printf '%s' "$message" | grep -q $'\xe2\x80\x94\|\xe2\x80\x93'; then
  errors+=("the message contains an em or en dash; use a comma, colon or plain hyphen")
fi

if [ ${#errors[@]} -gt 0 ]; then
  printf 'Commit message rejected: %s\n' "$subject" >&2
  printf '  - %s\n' "${errors[@]}" >&2
  exit 1
fi
