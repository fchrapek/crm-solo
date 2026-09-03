#!/bin/bash
# Shared gate library for per-site prod maintenance scripts.
#
# A site script defines its facts and calls run_site:
#
#   DOMAIN="example.test"
#   SSH_ALIAS="example-host"
#   REMOTE_PATH="~/domains/example.test/public_html"
#   VAULT="/path/to/backups/example"           # the folder holding {YYYY}/{YYYYMMDD}
#   EXCLUDE=""                                  # e.g. "--exclude=advanced-custom-fields-pro"
#   source "$(dirname "$0")/_lib.sh"
#   run_site "$@"                               # verbs: dump | update | verify | all
#
# Every mutation sits between gates. Any red gate stops the run with a
# machine-readable reason. Verbs are idempotent: dump skips when today's dump
# exists, update no-ops when everything is current, verify never writes.
# Output: one "step|status|detail" line per step on stdout.
set -u

say(){ printf '%s|%s|%s\n' "$1" "$2" "$3"; }
die(){ say "$1" "FAIL" "$2"; exit 1; }

# WP_BIN (optional, per-site): how to invoke wp-cli on the host, e.g.
# "php83 /usr/local/bin/wp" when the shell default php is older than the web PHP.
rssh(){ ssh "$SSH_ALIAS" -o ConnectTimeout=20 -o BatchMode=yes "cd $REMOTE_PATH && wp(){ ${WP_BIN:-command wp} \"\$@\"; } && $*"; }

health(){ # -> "200-clean" or reason; never writes
  local code bad tmp; tmp=$(mktemp)
  code=$(curl -sSL -o "$tmp" -w "%{http_code}" --max-time 30 "https://$DOMAIN/" 2>/dev/null)
  bad=$(grep -ciE "critical error|fatal error|briefly unavailable" "$tmp"); rm -f "$tmp"
  if [ "$code" = "200" ] && [ "$bad" = "0" ]; then echo "200-clean"; else echo "http=$code bad=$bad"; fi
}

verb_dump(){
  [ -d "$VAULT" ] || die dump "vault not reachable: $VAULT"
  local Y YMD DEST F T
  Y=$(date +%Y); YMD=$(date +%Y%m%d); DEST="$VAULT/$Y/$YMD"
  if ls "$DEST"/*-db-*.sql.gz >/dev/null 2>&1; then
    say dump SKIP "today's dump already filed: $(ls "$DEST" | head -1)"; return 0
  fi
  mkdir -p "$DEST"
  F="$DEST/${DOMAIN%%.*}-db-$(date +%Y%m%d%H%M%S).sql.gz"
  # Creds via wp-cli (uniform across hosts however wp-config is split); DB read-only.
  rssh 'MYSQL_PWD="$(wp config get DB_PASSWORD)" mysqldump -h"$(wp config get DB_HOST)" \
        -u"$(wp config get DB_USER)" --single-transaction --quick --no-tablespaces \
        "$(wp config get DB_NAME)" | gzip' > "$F" || { rm -f "$F"; die dump "remote dump failed"; }
  [ -s "$F" ] || { rm -f "$F"; die dump "empty dump"; }
  gunzip -t "$F" 2>/dev/null || { rm -f "$F"; die dump "gzip integrity failed"; }
  T=$(gunzip -c "$F" | grep -c "^CREATE TABLE")
  say dump OK "$(basename "$F") $(du -h "$F" | cut -f1) ${T} tables"
}

verb_update(){
  local H CB CA BEFORE AFTER NB NA
  H=$(health); [ "$H" = "200-clean" ] || die preflight "baseline unhealthy: $H"
  # A dump from today must exist before anything mutates.
  ls "$VAULT/$(date +%Y)/$(date +%Y%m%d)"/*-db-*.sql.gz >/dev/null 2>&1 \
    || die preflight "no dump filed today: run the dump verb first"
  say preflight OK "baseline 200-clean, dump on file"

  # core, gated
  CB=$(rssh "wp core version 2>/dev/null")
  if rssh "wp core check-update 2>/dev/null" | grep -q "Success:"; then
    say core SKIP "already latest ($CB)"
  else
    rssh "wp core update 2>&1 && wp core update-db 2>&1" >/dev/null 2>&1
    CA=$(rssh "wp core version 2>/dev/null"); H=$(health)
    rssh "wp core check-update 2>/dev/null" | grep -q "Success:" && [ "$H" = "200-clean" ] \
      || die core "update did not land clean ($CB -> $CA, $H)"
    say core OK "$CB -> $CA"
  fi

  BEFORE=$(rssh "wp plugin list --update=available --fields=name --format=csv 2>/dev/null | tail -n +2")
  if [ -z "$BEFORE" ]; then
    say plugins SKIP "nothing pending"
  else
    rssh "wp plugin update --all ${EXCLUDE:-} 2>&1" >/dev/null 2>&1
    AFTER=$(rssh "wp plugin list --update=available --fields=name --format=csv 2>/dev/null | tail -n +2")
    NB=$(echo "$BEFORE" | grep -c .); NA=$(echo "$AFTER" | grep -c .)
    H=$(health); [ "$H" = "200-clean" ] || die plugins "site unhealthy after update: $H"
    say plugins OK "$((NB-NA)) of $NB updated; left: $(echo "$AFTER" | tr '\n' ' ')"
  fi
}

verb_verify(){
  local H SUMS PEND
  H=$(health)
  SUMS=$(rssh "wp core verify-checksums 2>&1" | tail -1)
  PEND=$(rssh "wp plugin list --update=available --fields=name --format=csv 2>/dev/null | tail -n +2" | tr '\n' ' ')
  say verify "$([ "$H" = "200-clean" ] && echo OK || echo FAIL)" "$H; checksums: $SUMS; pending: ${PEND:-none}"
  [ "$H" = "200-clean" ]
}

run_site(){
  : "${DOMAIN:?}" "${SSH_ALIAS:?}" "${REMOTE_PATH:?}" "${VAULT:?}"
  case "${1:-all}" in
    dump)   verb_dump ;;
    update) verb_update ;;
    verify) verb_verify ;;
    all)    verb_dump && verb_update && verb_verify ;;
    *) die usage "unknown verb '${1}' (dump|update|verify|all)" ;;
  esac
}
