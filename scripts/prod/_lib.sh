#!/bin/bash
# Shared gate library for per-site prod maintenance scripts.
#
# A site script defines its facts and calls run_site:
#
#   DOMAIN="example.test"
#   SSH_ALIAS="example-host"
#   REMOTE_PATH="~/domains/example.test/public_html"
#   VAULT="/path/to/backups/example"           # the folder holding {YYYY}/{YYYYMMDD}
#   EXCLUDE=""                                  # "" or "--exclude=slug[,slug...]"
#   source "$(dirname "$0")/../../_lib.sh"
#   run_site "$@"                               # verbs: dump | plan | update | verify | dbcheck | all
#
# Every mutation sits between gates; any red gate stops the run with a
# machine-readable reason, and no remote read is trusted without its exit
# status. Output: one "step|status|detail" line per step on stdout.
#
# Backup model: one pinned pair per site and day, the DB dump (local, in
# VAULT) and a file snapshot (on the server, ~/maint-snapshots/<domain>-<day>/).
# Reruns on the same day reuse the pair, so a retry after a half-done update
# never replaces the rollback with half-updated files.
set -u

say(){ printf '%s|%s|%s\n' "$1" "$2" "$3"; }
die(){ say "$1" "FAIL" "$2"; exit 1; }

SSH_BIN="${SSH_BIN:-ssh}"      # tests point these at fakes
CURL_BIN="${CURL_BIN:-curl}"
DAY="$(date +%Y%m%d)"

# WP_BIN (optional, per site): how to invoke wp-cli on the host, e.g.
# "php83 /usr/local/bin/wp" when the shell default php is older than the web PHP.
#
# MariaDB client path. wp-cli 2.12 reads the local client's --version, sees
# MariaDB and then runs `mariadb` / `mariadb-dump` by bare name. On SeoHost
# (h67, h89) those live only in a versioned /usr/local/mariadb-*/bin that is
# not on the non-login SSH PATH; /usr/local/bin has only the old names as
# symlinks into it. Resolve the directory on the server at every call (it
# follows SeoHost's upgrades), add it only when it really holds both
# binaries, never add "." when the lookup fails. No-op where they are on PATH.
# Tested 02.10.2026 on h67: `wp db export -` 20 MB exit 0, `wp db query` OK.
MARIADB_PATH_FIX='d=$(dirname "$(readlink -f "$(command -v mysqldump || command -v mysql)" 2>/dev/null)" 2>/dev/null); if [ -n "$d" ] && [ "$d" != "." ] && [ -x "$d/mariadb" ] && [ -x "$d/mariadb-dump" ]; then PATH="$d:$PATH"; fi; export PATH;'

# Run a command in the site's directory on the server under bash with
# pipefail. A failed cd ends the call (exit 97) before anything runs. The
# command travels inside single quotes (embedded ones escaped), so
# "~/domains/..." still expands on the server.
rssh(){
  local cmd="cd $REMOTE_PATH || exit 97; $MARIADB_PATH_FIX wp(){ ${WP_BIN:-command wp} \"\$@\"; }; $*"
  local sq="'\\''"
  "$SSH_BIN" "$SSH_ALIAS" -o ConnectTimeout=20 -o BatchMode=yes "bash -o pipefail -c '${cmd//\'/$sq}'" </dev/null
}

# rcap VAR CMD: run CMD on the server, store stdout in VAR; non-zero when the
# remote command failed.
rcap(){ local __v="$1"; shift; local __o; __o=$(rssh "$*") || return 1; printf -v "$__v" '%s' "$__o"; }

# Homepage health -> "200-clean" or a reason. Never writes. curl must finish
# successfully and the body must be free of PHP error pages on both paths.
# The SeoHost origin answers 429 to rapid requests from one IP; then the same
# check runs from the server (a different IP), body included.
health(){
  local tmp code rc body
  tmp=$(mktemp)
  code=$("$CURL_BIN" -sS -L -o "$tmp" -w "%{http_code}" --max-time 30 -H 'Cache-Control: no-cache' "https://$DOMAIN/" 2>/dev/null); rc=$?
  body=$(cat "$tmp"); rm -f "$tmp"
  if [ $rc -eq 0 ] && [ "$code" = "429" ]; then
    local remote
    rcap remote "curl -sS -L --max-time 30 -H 'Cache-Control: no-cache' -w '\n__HTTP__%{http_code}' https://$DOMAIN/" || { echo "server-side check failed"; return; }
    code=$(sed -n 's/^__HTTP__//p' <<<"$remote" | tail -1); body=$(sed '/^__HTTP__/d' <<<"$remote"); rc=0
  fi
  [ $rc -eq 0 ] || { echo "curl failed (exit $rc, http $code)"; return; }
  [ "$code" = "200" ] || { echo "http=$code"; return; }
  if grep -qiE "critical error|fatal error|briefly unavailable|error establishing a database" <<<"$body"; then echo "http=200 but an error page"; return; fi
  grep -qi '</html>' <<<"$body" || { echo "http=200 but the page is cut short"; return; }
  echo "200-clean"
}

# The site's own host, from the live DB. Exact host, validated.
siteurl_host(){
  local su h
  rcap su 'wp option get siteurl' || return 1
  h=$(sed -E 's#^https?://##; s#[/:].*$##' <<<"$su" | tr 'A-Z' 'a-z')
  [[ "$h" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || return 1
  printf '%s' "$h"
}

# A dump is good only when all hold: gzip intact, tables present,
# mariadb-dump's closing "-- Dump completed" line, and the options row
# 'siteurl' = this site's URL (not just the host anywhere in the SQL).
# Reading stops early on purpose (grep -q), so no pipefail here.
dump_check(){
  local f="$1" host="$2" tables hre
  [ -n "$host" ] || { echo "no expected site host"; return 1; }
  gunzip -t "$f" 2>/dev/null || { echo "gzip integrity failed"; return 1; }
  tables=$(gunzip -c "$f" | grep -c "^CREATE TABLE")
  [ "${tables:-0}" -gt 0 ] || { echo "no tables in dump"; return 1; }
  gunzip -c "$f" | tail -c 400 | grep -q -- "-- Dump completed" || { echo "dump has no completion footer (cut short?)"; return 1; }
  hre=$(sed 's/[.]/\\./g' <<<"$host")
  gunzip -c "$f" | grep -qE "'siteurl','https?://$hre/?'" || { echo "options row siteurl is not $host (wrong site?)"; return 1; }
  echo "$tables tables"
}

todays_dump(){ ls "${DUMP_DIR_OVERRIDE:-$VAULT}/$(date +%Y)/$DAY"/*-db-*.sql.gz 2>/dev/null | head -1; }

verb_dump(){
  local dest_root="${DUMP_DIR_OVERRIDE:-$VAULT}" DEST F T existing err host
  [ -d "$dest_root" ] || die dump "backup folder not reachable: $dest_root"
  DEST="$dest_root/$(date +%Y)/$DAY"
  host=$(siteurl_host) || die dump "cannot read a valid siteurl over ssh"
  existing=$(todays_dump)
  if [ -n "$existing" ]; then
    T=$(dump_check "$existing" "$host") || die dump "today's dump $(basename "$existing") fails checks: $T"
    say dump SKIP "today's dump already filed and valid: $(basename "$existing") $T"; return 0
  fi
  mkdir -p "$DEST" || die dump "cannot create $DEST"
  F="$DEST/${DOMAIN%%.*}-db-$(date +%Y%m%d%H%M%S).sql.gz"
  err=$(mktemp)
  # wp-cli supplies credentials (wp-config-db.php via wp-config.php),
  # host/port/socket parsing and charset; --single-transaction is ours.
  if ! rssh 'wp db export - --single-transaction --quick 2>/tmp/maint-dump-err.$$ | gzip; s=$?; [ $s -ne 0 ] && cat /tmp/maint-dump-err.$$ >&2; rm -f /tmp/maint-dump-err.$$; exit $s' >"$F" 2>"$err"; then
    rm -f "$F"; die dump "remote dump failed: $(head -c 300 "$err" | tr '\n' ' ')"
  fi
  rm -f "$err"
  T=$(dump_check "$F" "$host") || { rm -f "$F"; die dump "dump rejected: $T"; }
  say dump OK "$(basename "$F") $(du -h "$F" | cut -f1) $T, siteurl $host"
}

# Read-only DB check over wp-cli (proves the MariaDB PATH fix on this host).
verb_dbcheck(){
  local n
  rcap n 'wp db query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()" --skip-column-names' \
    || die dbcheck "wp db query failed"
  [[ "$n" =~ ^[0-9]+$ ]] && [ "$n" -gt 0 ] || die dbcheck "unexpected table count '$n'"
  say dbcheck OK "$n tables via wp db query"
}

# --- parsing, always checked ----------------------------------------------------

# core_updates VAR: JSON array of available core updates. `wp core
# check-update` prints JSON only when one exists and "Success: ..." otherwise.
core_updates(){
  local __v="$1" raw
  rcap raw 'wp core check-update --format=json --fields=version' || return 1
  if jq -e 'type=="array"' >/dev/null 2>&1 <<<"$raw"; then printf -v "$__v" '%s' "$raw"
  elif grep -q '^Success:' <<<"$raw"; then printf -v "$__v" '[]'
  else return 1; fi
}

# pending_rows VAR KIND: tab-separated "slug from to" for plugins or themes
# with an update, schema and slugs validated before anything mutates.
pending_rows(){
  local __v="$1" kind="$2" raw rows
  rcap raw "wp $kind list --update=available --fields=name,version,update_version --format=json" || return 1
  jq -e 'type=="array" and all(.[]; (.name|type=="string") and (.version|type=="string") and (.update_version|type=="string"))' >/dev/null 2>&1 <<<"$raw" || return 1
  rows=$(jq -r '.[] | [.name,.version,.update_version] | @tsv' <<<"$raw") || return 1
  if [ -n "$rows" ] && grep -vqE $'^[a-z0-9._-]+\t[^\t]+\t[^\t]+$' <<<"$rows"; then return 1; fi
  printf -v "$__v" '%s' "$rows"
}

# Plugins excluded from updates. Validated in run_site.
excluded(){ printf '%s\n' "${EXCLUDE:-}" | sed -n 's/^--exclude=//p' | tr ',' '\n' | grep -v '^$' || true; }

# Active plugin list, checked; a failed read is an error, never "inactive".
active_plugins(){ local __v="$1" raw; rcap raw 'wp plugin list --status=active --field=name' || return 1; printf -v "$__v" '%s' "$raw"; }

lang_pending(){ # -> "core plugins themes" counts, all checked
  local __v="$1" c p t
  rcap c 'wp language core list --update=available --format=count' || return 1
  rcap p 'wp language plugin list --all --update=available --format=count' || return 1
  rcap t 'wp language theme list --all --update=available --format=count' || return 1
  [[ "$c$p$t" =~ ^[0-9]+$ ]] || return 1
  printf -v "$__v" '%s %s %s' "$c" "$p" "$t"
}

# --- verbs ---------------------------------------------------------------------

verb_plan(){
  local core plugins themes lang
  core_updates core || die plan "core check failed or unreadable"
  pending_rows plugins plugin || die plan "plugin list failed or unreadable"
  pending_rows themes theme || die plan "theme list failed or unreadable"
  lang_pending lang || die plan "language list failed or unreadable"
  say plan OK "core $(jq -r 'map(.version)|join(",")' <<<"$core"); plugins $(tr '\t\n' ' ;' <<<"$plugins"); themes $(tr '\t\n' ' ;' <<<"$themes"); translations pending (core plugins themes) $lang; excluded: $(excluded | tr '\n' ' ')"
}

# Remote lock: one maintenance operation per site at a time. The lock path is
# absolute (no cd into the site needed), release is checked, and a lock left
# behind (connection lost after mkdir) is reported, never silently ignored.
LOCK_HELD=0
LOCK_DIR='~/maint-snapshots/.lock-'
take_lock(){
  LOCK_HELD=2   # "maybe": a lost connection after mkdir must still be reported
  "$SSH_BIN" "$SSH_ALIAS" -o ConnectTimeout=20 -o BatchMode=yes \
    "mkdir -p ~/maint-snapshots && mkdir $LOCK_DIR$DOMAIN && date +%FT%T > $LOCK_DIR$DOMAIN/since" </dev/null >/dev/null 2>&1 \
    || { LOCK_HELD=0; die lock "another maintenance run holds $LOCK_DIR$DOMAIN (or the server is unreachable); remove it by hand only after checking nothing runs"; }
  LOCK_HELD=1
}
release_lock(){
  [ "$LOCK_HELD" = 0 ] && return 0
  if "$SSH_BIN" "$SSH_ALIAS" -o ConnectTimeout=20 -o BatchMode=yes "rm -f $LOCK_DIR$DOMAIN/since && rmdir $LOCK_DIR$DOMAIN" </dev/null >/dev/null 2>&1; then
    LOCK_HELD=0; return 0
  fi
  say lock WARN "could not release $LOCK_DIR$DOMAIN on $SSH_ALIAS: remove it by hand after checking no run is active"
  return 1
}
on_exit(){ local rc=$?; release_lock || rc=1; exit $rc; }
on_interrupt(){
  say interrupted STOP "stopped mid-run; check https://$DOMAIN/ and run verify; a leftover .maintenance file means an updater was cut off"
  exit 130
}

# File snapshot of everything an update can replace, pinned for today and
# bound to today's dump by a manifest (dump name + archive sha256).
#  - no folder or no "complete" marker: no update can have run on it yet
#    (updates start only after "complete"), so it is (re)built;
#  - "complete" but the archive or manifest does not check out: STOP, never
#    rebuild, because the files may already be half-updated.
# Space is checked every time (3x the size for a new archive, 1x headroom on
# reuse); sizes must be plain integers. Only complete snapshots of other days
# count for retention (newest one kept). Partial files are removed by a
# remote trap if the tar is cut off.
snapshot_files(){
  local dump_name="$1" dir="maint-snapshots/$DOMAIN-$DAY"
  rssh "
    export LC_ALL=C
    s=~/$dir
    P='wp-admin wp-includes wp-content/plugins wp-content/themes'
    for r in \$P; do [ -e \$r ] || { echo \"missing \$r\" >&2; exit 1; }; done
    for o in wp-content/languages wp-content/mu-plugins wp-content/object-cache.php wp-content/advanced-cache.php wp-content/db.php .htaccess; do [ -e \$o ] && P=\"\$P \$o\"; done
    size=\$(du -sk --exclude=wp-content/uploads --exclude=wp-content/cache --exclude=wp-content/litespeed \$P *.php | awk '{s+=\$1} END {printf \"%.0f\", s}') || { echo 'du failed' >&2; exit 1; }
    free=\$(df -Pk ~ | awk 'NR==2 {printf \"%.0f\", \$4}') || { echo 'df failed' >&2; exit 1; }
    case \"\$size\$free\" in ''|*[!0-9]*) echo \"bad sizes: size=\$size free=\$free\" >&2; exit 1 ;; esac
    if [ -f \$s/complete ]; then
      [ \"\$free\" -gt \"\$size\" ] || { echo \"not enough space: need \${size}k free \${free}k\" >&2; exit 1; }
      tar tzf \$s/files.tgz >/dev/null 2>&1 || { echo 'existing snapshot for today is unreadable: inspect, do not rebuild' >&2; exit 1; }
      want=\$(sed -n 's/^sha256 //p' \$s/manifest); got=\$(sha256sum \$s/files.tgz | cut -d' ' -f1)
      [ -n \"\$want\" ] && [ \"\$want\" = \"\$got\" ] || { echo 'existing snapshot does not match its manifest: inspect, do not rebuild' >&2; exit 1; }
      grep -qxF 'dump $dump_name' \$s/manifest || { echo 'existing snapshot belongs to another dump: inspect' >&2; exit 1; }
      echo REUSE; exit 0
    fi
    [ \"\$free\" -gt \"\$((size*3))\" ] || { echo \"not enough space: need \$((size*3))k free \${free}k\" >&2; exit 1; }
    mkdir -p \$s || exit 1
    trap 'rm -f \$s/files.tgz.partial' EXIT INT TERM HUP
    rm -f \$s/files.tgz.partial \$s/files.tgz \$s/manifest
    tar czf \$s/files.tgz.partial --exclude=wp-content/uploads --exclude=wp-content/cache --exclude=wp-content/litespeed -- \$P *.php || exit 1
    tar tzf \$s/files.tgz.partial >/dev/null || exit 1
    mv \$s/files.tgz.partial \$s/files.tgz || exit 1
    h=\$(sha256sum \$s/files.tgz | cut -d' ' -f1) || { echo 'sha256sum failed' >&2; exit 1; }
    [[ \"\$h\" =~ ^[0-9a-f]{64}\$ ]] || { echo \"bad archive hash '\$h'\" >&2; exit 1; }
    printf 'dump %s\\nsha256 %s\\n' '$dump_name' \"\$h\" > \$s/manifest || exit 1
    touch \$s/complete || exit 1
    trap - EXIT INT TERM HUP
    for old in \$(ls -1dt ~/maint-snapshots/$DOMAIN-*/ 2>/dev/null | grep -v \"/$DOMAIN-$DAY/\$\" | while read -r d; do [ -f \"\$d/complete\" ] && echo \"\$d\"; done | tail -n +2); do
      rm -rf -- \"\$old\" || { echo \"prune failed: \$old\" >&2; exit 1; }
    done
    echo NEW"
}

verb_update(){
  local H T dump host snapinfo snap="~/maint-snapshots/$DOMAIN-$DAY/files.tgz"
  H=$(health); [ "$H" = "200-clean" ] || die preflight "baseline unhealthy: $H"
  host=$(siteurl_host) || die preflight "cannot read a valid siteurl"
  dump=$(todays_dump)
  [ -n "$dump" ] || die preflight "no dump filed today: run the dump verb first"
  T=$(dump_check "$dump" "$host") || die preflight "today's dump fails checks: $T"
  say preflight OK "baseline 200-clean, dump $(basename "$dump") valid ($T)"

  # Parse everything before mutating anything.
  local cu pplug ptheme active
  core_updates cu || die preflight "core check failed or unreadable"
  pending_rows pplug plugin || die preflight "plugin list failed or unreadable"
  pending_rows ptheme theme || die preflight "theme list failed or unreadable"
  active_plugins active || die preflight "active plugin list failed"

  trap on_exit EXIT
  trap on_interrupt INT TERM HUP
  take_lock

  snapinfo=$(snapshot_files "$(basename "$dump")" 2>&1) || die snapshot "file snapshot failed: $(tr '\n' ' ' <<<"$snapinfo")"
  say snapshot OK "$snap ($(tail -1 <<<"$snapinfo" | tr 'A-Z' 'a-z'))"
  local rb="rollback: files $snap, DB $(basename "$dump")"

  # Core.
  local cb ca
  rcap cb 'wp core version' || die core "version read failed"
  if [ "$(jq 'length' <<<"$cu")" = "0" ]; then say core SKIP "already latest ($cb)"
  else
    rssh 'wp core update >/dev/null && wp core update-db >/dev/null' || die core "core update failed; $rb"
    rcap ca 'wp core version' || die core "version read-back failed; $rb"
    [ "$ca" != "$cb" ] || die core "version unchanged after update ($cb); $rb"
    say core OK "$cb -> $ca"
  fi

  # Plugins, one at a time; any failure stops the run.
  local slug from to got n=0
  while IFS=$'\t' read -r slug from to; do
    [ -n "$slug" ] || continue
    if excluded | grep -qxF "$slug"; then say plugins SKIP "$slug excluded"; continue; fi
    rssh "wp plugin update $slug >/dev/null" || die plugins "$slug $from -> $to failed; $rb"
    rcap got "wp plugin get $slug --field=version" || die plugins "$slug version read-back failed; $rb"
    [ "$got" = "$to" ] || die plugins "$slug is $got after update, expected $to; $rb"
    n=$((n+1))
  done <<<"$pplug"
  say plugins OK "$n updated"

  # Themes.
  local tn=0
  while IFS=$'\t' read -r slug from to; do
    [ -n "$slug" ] || continue
    rssh "wp theme update $slug >/dev/null" || die themes "$slug failed; $rb"
    rcap got "wp theme get $slug --field=version" || die themes "$slug read-back failed; $rb"
    [ "$got" = "$to" ] || die themes "$slug is $got after update, expected $to; $rb"
    tn=$((tn+1))
  done <<<"$ptheme"
  say themes OK "$tn updated"

  # Translations: the updater only warns on partial failure, so also prove
  # that nothing is left pending.
  local lp
  rssh 'wp language core update >/dev/null && wp language plugin update --all >/dev/null && wp language theme update --all >/dev/null' \
    || die translations "language update failed; $rb"
  lang_pending lp || die translations "cannot read pending translations; $rb"
  [ "$lp" = "0 0 0" ] || die translations "translations still pending after update (core plugins themes: $lp); $rb"
  say translations OK "none pending"

  # WooCommerce DB, where WooCommerce is active; read back the DB version.
  if grep -qxF woocommerce <<<"$active"; then
    local dbv pv
    rssh 'wp wc update >/dev/null' || die woocommerce "wc update failed; $rb"
    rcap dbv 'wp option get woocommerce_db_version' || die woocommerce "db version read failed"
    rcap pv 'wp plugin get woocommerce --field=version' || die woocommerce "plugin version read failed"
    [ "$dbv" = "$pv" ] || die woocommerce "DB version $dbv does not match plugin $pv after wc update; $rb"
    say woocommerce OK "DB $dbv"
  fi

  # Cache: purge where LiteSpeed is active; a failed purge is a failure.
  if grep -qxF litespeed-cache <<<"$active"; then
    rssh 'wp litespeed-purge all >/dev/null' || die cache "litespeed-purge failed; $rb"
    say cache OK "LiteSpeed purged"
  fi

  verb_verify || die update "verification failed after update; $rb"
  release_lock || die lock "update finished but the lock could not be released; $rb"
  say update OK "done; $rb"
}

# Read-only. Fails on: unhealthy homepage, core checksum failure, pending
# core / theme / non-excluded plugin updates, siteurl host not this domain.
verb_verify(){
  local H sums=ok cu pt pend host ok=1
  H=$(health)
  rssh 'wp core verify-checksums >/dev/null 2>&1' || sums=FAIL
  core_updates cu || { say verify FAIL "core check failed or unreadable"; return 1; }
  pending_rows pt theme || { say verify FAIL "theme list failed or unreadable"; return 1; }
  rcap pend 'wp plugin list --update=available --field=name' || { say verify FAIL "plugin list failed"; return 1; }
  pend=$(printf '%s\n' "$pend" | grep -v '^$' | grep -vxF -f <(excluded; echo "__none__") | tr '\n' ' ')
  host=$(siteurl_host) || { say verify FAIL "siteurl read failed"; return 1; }
  [ "$H" = "200-clean" ] || ok=0
  [ "$sums" = ok ] || ok=0
  [ "$(jq 'length' <<<"$cu")" = "0" ] || ok=0
  [ -z "$pt" ] || ok=0
  [ -z "${pend// /}" ] || ok=0
  [ "$host" = "$DOMAIN" ] || [ "$host" = "www.$DOMAIN" ] || ok=0
  say verify "$([ $ok = 1 ] && echo OK || echo FAIL)" "$H; core checksums $sums; core pending $(jq -r 'map(.version)|join(",")' <<<"$cu"); themes pending $(cut -f1 <<<"$pt" | tr '\n' ' '); plugins pending ${pend:-none}; siteurl host $host"
  [ $ok = 1 ]
}

run_site(){
  : "${DOMAIN:?}" "${SSH_ALIAS:?}" "${REMOTE_PATH:?}" "${VAULT:?}"
  if [ -n "${EXCLUDE:-}" ] && ! [[ "$EXCLUDE" =~ ^--exclude=[a-z0-9._-]+(,[a-z0-9._-]+)*$ ]]; then
    die config "EXCLUDE must be empty or --exclude=slug[,slug...] (got '$EXCLUDE')"
  fi
  case "${1:-all}" in
    dump)    verb_dump ;;
    plan)    verb_plan ;;
    update)  verb_update ;;
    verify)  verb_verify ;;
    dbcheck) verb_dbcheck ;;
    all)     verb_dump && verb_update ;;
    *) die usage "unknown verb '${1}' (dump|plan|update|verify|dbcheck|all)" ;;
  esac
}
