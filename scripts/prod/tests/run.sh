#!/bin/bash
# Fault tests for _lib.sh. No network and no real server: the fake ssh RUNS
# the transported command (cd, pipes, pipefail, tar, the PATH fix) in a
# sandbox home, against fake wp / curl / du / df executables. A scenario
# variable (FAKE) decides what goes wrong. Run: scripts/prod/tests/run.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
LIB="$HERE/../_lib.sh"
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
BIN="$T/bin"; mkdir -p "$BIN"
PASS=0; FAILS=0

# ---- fakes -----------------------------------------------------------------
cat >"$BIN/ssh" <<'EOF'
#!/bin/bash
# Drop alias and -o options; run the last argument like the server would.
cmd="${@: -1}"; printf '%s\n' "$cmd" >>"$FAKE_DIR/ssh.log"
[ "$FAKE" = unlock_fail ] && [[ "$cmd" == *"rmdir ~/maint-snapshots/.lock-"* ]] && exit 255
HOME="$FAKE_DIR/home" PATH="$FAKE_BIN:$PATH" FAKE_SERVER_SIDE=1 bash -c "$cmd"
EOF
cat >"$BIN/wp" <<'EOF'
#!/bin/bash
S="$FAKE_DIR"; a="$*"; echo "wp $a" >>"$S/wp.log"
site="https://site.test"; [ "$FAKE" = other_site ] && site="https://other.test"
case "$a" in
  "option get siteurl") echo "$site" ;;
  "db export - --single-transaction --quick")
    [ "$FAKE" = dump_fail ] && { printf -- '-- MariaDB dump\nCREATE TABLE `a` (x int);\n'; echo "mariadb-dump: Got error 2013" >&2; exit 2; }
    printf -- '-- MariaDB dump\nCREATE TABLE `wp_options` (x int);\n'
    [ "$FAKE" = dump_nosite ] || printf "INSERT INTO \`wp_options\` VALUES (1,'siteurl','%s','yes');\n" "$site"
    [ "$FAKE" = dump_nofooter ] || printf -- '-- Dump completed on 2026-10-02\n' ;;
  db\ query*) echo 42 ;;
  "core check-update --format=json --fields=version")
    if { [ "$FAKE" = core_pending ] || [ "$FAKE" = core_fail ]; } && [ ! -f "$S/core_done" ]; then echo '[{"version":"7.1.3"}]'
    else echo "Success: WordPress is at the latest version."; fi ;;
  "core version") [ -f "$S/core_done" ] && echo 7.1.3 || echo 7.1.2 ;;
  "core update") [ "$FAKE" = core_fail ] && exit 1; touch "$S/core_done" ;;
  "core update-db") : ;;
  "core verify-checksums") [ "$FAKE" = checksums_fail ] && exit 1; : ;;
  "plugin list --update=available --fields=name,version,update_version --format=json")
    [ "$FAKE" = bad_json ] && { echo "PHP Warning: something"; exit 0; }
    r='['; sep=''
    [ -f "$S/alpha_done" ] || { r="$r{\"name\":\"alpha\",\"version\":\"1.0\",\"update_version\":\"1.1\"}"; sep=','; }
    [ -f "$S/beta_done" ] || r="$r$sep{\"name\":\"beta\",\"version\":\"2.0\",\"update_version\":\"2.1\"}"
    echo "$r]" ;;
  "plugin list --update=available --field=name")
    case "$FAKE" in
      verify_pending) echo alpha ;;
      ok_verify|checksums_fail|other_site|rate_limited|rate_limited_broken|curl_cut) : ;;
      *) [ -f "$S/alpha_done" ] || echo alpha; [ -f "$S/beta_done" ] || echo beta ;;
    esac; exit 0 ;;
  "plugin list --status=active --field=name") [ "$FAKE" = active_fail ] && exit 1; echo alpha; echo beta ;;
  "plugin update alpha") [ "$FAKE" = plugin_fail ] && exit 1; [ "$FAKE" = plugin_stuck ] || touch "$S/alpha_done"; exit 0 ;;
  "plugin update beta") touch "$S/beta_done" ;;
  "plugin get alpha --field=version") [ -f "$S/alpha_done" ] && echo 1.1 || echo 1.0 ;;
  "plugin get beta --field=version") [ -f "$S/beta_done" ] && echo 2.1 || echo 2.0 ;;
  "theme list --update=available --fields=name,version,update_version --format=json") echo '[]' ;;
  "language core update"|"language plugin update --all"|"language theme update --all") [ "$FAKE" = lang_fail ] && exit 1; exit 0 ;;
  language\ *list*) [ "$FAKE" = lang_left ] && echo 1 || echo 0 ;;
  *) echo "fake wp: unhandled: $a" >&2; exit 99 ;;
esac
EOF
cat >"$BIN/curl" <<'EOF'
#!/bin/bash
# Homepage fetch, local (with -o) or from the server (FAKE_SERVER_SIDE).
out=""; fmt=""; while [ $# -gt 0 ]; do case "$1" in -o) out="$2"; shift ;; -w) fmt="$2"; shift ;; esac; shift; done
code=200; body="<html>ok</html>"
[ "$FAKE" = broken_after ] && [ -f "$FAKE_DIR/beta_done" ] && body="<html>There has been a critical error</html>"
if [ -z "${FAKE_SERVER_SIDE:-}" ]; then
  case "$FAKE" in rate_limited|rate_limited_broken) code=429; body="Too Many" ;; esac
else
  [ "$FAKE" = rate_limited_broken ] && body="<html>fatal error</html>"
fi
if [ "$FAKE" = curl_cut ]; then [ -n "$out" ] && printf '%s' "<html>half" >"$out"; printf '200'; exit 28; fi
if [ -n "$out" ]; then printf '%s' "$body" >"$out"; else printf '%s' "$body"; fi
printf '%s' "${fmt//%\{http_code\}/$code}" | sed 's/\\n/\n/g'
EOF
cat >"$BIN/du" <<'EOF'
#!/bin/bash
echo "100	total"
EOF
cat >"$BIN/df" <<'EOF'
#!/bin/bash
echo "Filesystem 1024-blocks Used Available Capacity Mounted"
if [ "$FAKE" = disk_full ]; then echo "fs 1000 990 10 99% /"; else echo "fs 100000000 1 99999999 1% /"; fi
EOF
cat >"$BIN/sha256sum" <<'EOF2'
#!/bin/bash
[ "$FAKE" = hash_fail ] && exit 1
shasum -a 256 "$@"
EOF2
chmod +x "$BIN"/*

LAST_DIR=""
run(){ # run(scenario, verb, seed) prints the output; status of the verb
  local scenario="$1" verb="$2" seed="${3:-}"
  local dir="$T/$scenario-$RANDOM"
  mkdir -p "$dir/vault" "$dir/home/x/wp-admin" "$dir/home/x/wp-includes" "$dir/home/x/wp-content/plugins" "$dir/home/x/wp-content/themes"
  echo "<?php" >"$dir/home/x/index.php"; echo "admin" >"$dir/home/x/wp-admin/a.php"
  if [ -n "$seed" ]; then local d; d="$dir/vault/$(date +%Y)/$(date +%Y%m%d)"; mkdir -p "$d"
    printf -- "-- MariaDB dump\nCREATE TABLE \`wp_options\` (x int);\nINSERT INTO \`wp_options\` VALUES (1,'siteurl','https://site.test','yes');\n-- Dump completed\n" | gzip >"$d/site-db-1.sql.gz"; fi
  local snaps="$dir/home/maint-snapshots" today; today=$(date +%Y%m%d)
  case "$scenario" in
    snap_corrupt) mkdir -p "$snaps/site.test-$today"; echo junk >"$snaps/site.test-$today/files.tgz"; touch "$snaps/site.test-$today/complete" ;;
    snap_partial_left) mkdir -p "$snaps/site.test-$today"; echo junk >"$snaps/site.test-$today/files.tgz.partial" ;;
    snap_retention) mkdir -p "$snaps/site.test-20260901" "$snaps/site.test-20260915" "$snaps/site.test-20260920"
      touch "$snaps/site.test-20260901/complete" "$snaps/site.test-20260920/complete"
      touch -t 202609010000 "$snaps/site.test-20260901"; touch -t 202609150000 "$snaps/site.test-20260915"; touch -t 202609200000 "$snaps/site.test-20260920" ;;
  esac
  local remote_path='~/x'; [ "$scenario" = cd_fail ] && remote_path='~/missing'
  local exclude=''; [ "$scenario" = bad_exclude ] && exclude='--exclude=a --exclude=b'
  echo "$dir" >"$T/last"
  FAKE="$scenario" FAKE_DIR="$dir" FAKE_BIN="$BIN" SSH_BIN="$BIN/ssh" CURL_BIN="$BIN/curl" bash -c "
    DOMAIN=site.test; SSH_ALIAS=fake; REMOTE_PATH='$remote_path'; VAULT='$dir/vault'; EXCLUDE='$exclude'
    source '$LIB'; run_site $verb" 2>&1
}
expect(){ # name, want exit, pattern, output, exit
  if [ "$5" = "$2" ] && grep -qE "$3" <<<"$4"; then PASS=$((PASS+1)); echo "ok   $1"
  else FAILS=$((FAILS+1)); echo "FAIL $1 (rc=$5, want $2, pattern '$3')"; sed 's/^/     /' <<<"$4"; fi
}
never_ran(){ # name, pattern that must NOT appear in the wp log of the last run
  local d; d=$(cat "$T/last")
  if [ -f "$d/wp.log" ] && grep -qE "$2" "$d/wp.log"; then FAILS=$((FAILS+1)); echo "FAIL $1"; sed 's/^/     /' "$d/wp.log"; else PASS=$((PASS+1)); echo "ok   $1"; fi
}
check(){ if eval "$2"; then PASS=$((PASS+1)); echo "ok   $1"; else FAILS=$((FAILS+1)); echo "FAIL $1"; fi; }

# dump
out=$(run ok dump); expect "dump: valid dump kept, siteurl row checked" 0 '^dump\|OK\|.*siteurl site.test' "$out" $?
out=$(run dump_fail dump); expect "dump: real pipeline failure is caught" 1 '^dump\|FAIL\|remote dump failed.*2013' "$out" $?
out=$(run dump_nofooter dump); expect "dump: missing footer rejected" 1 'no completion footer' "$out" $?
out=$(run dump_nosite dump); expect "dump: siteurl row missing rejected" 1 'options row siteurl is not site.test' "$out" $?
out=$(run cd_fail dump); expect "transport: failed cd stops the call" 1 '^dump\|FAIL' "$out" $?
never_ran "transport: nothing ran after the failed cd" '.'
# update preflight and parsing
out=$(run ok update); expect "update: refuses without today's dump" 1 'no dump filed today' "$out" $?
out=$(run other_site update seed); expect "update: dump of another site refused" 1 "fails checks: options row siteurl is not other.test" "$out" $?
out=$(run bad_json update seed); expect "update: unreadable plugin list stops before mutating" 1 '^preflight\|FAIL\|plugin list failed or unreadable' "$out" $?
never_ran "update: nothing mutated after the bad JSON" ' update'
out=$(run active_fail update seed); expect "update: failed active-plugin read is an error" 1 'active plugin list failed' "$out" $?
out=$(run bad_exclude plan); expect "config: unsupported EXCLUDE syntax rejected" 1 '^config\|FAIL' "$out" $?
# snapshot and lock
out=$(run disk_full update seed); expect "snapshot: refuses when space is short" 1 'not enough space' "$out" $?
never_ran "snapshot: no update after the space check failed" ' update'
out=$(run ok update seed); expect "update: clean run passes" 0 '^update\|OK' "$out" $?
D=$(cat "$T/last"); S="$D/home/maint-snapshots/site.test-$(date +%Y%m%d)"
check "snapshot: archive exists, complete, holds wp-admin" '[ -f "$S/complete" ] && tar tzf "$S/files.tgz" | grep -q wp-admin/a.php'
check "snapshot: no .partial left" '! ls "$S"/*.partial >/dev/null 2>&1'
check "lock: released after the run" '[ ! -d "$D/home/maint-snapshots/.lock-site.test" ]'
out=$(run ok update seed); D=$(cat "$T/last"); S="$D/home/maint-snapshots/site.test-$(date +%Y%m%d)"
check "snapshot: manifest binds the dump and the archive hash" 'grep -qx "dump site-db-1.sql.gz" "$S/manifest" && [ "$(sed -n "s/^sha256 //p" "$S/manifest")" = "$(shasum -a 256 "$S/files.tgz" | cut -d" " -f1)" ]'
out=$(run snap_corrupt update seed); expect "snapshot: a complete but unreadable snapshot stops, never rebuilt" 1 'unreadable: inspect, do not rebuild' "$out" $?
never_ran "snapshot: no update after the corrupt snapshot" ' update'
out=$(run snap_partial_left update seed); expect "snapshot: leftovers without complete marker are rebuilt" 0 '^snapshot\|OK.*\(new\)' "$out" $?
out=$(run snap_retention update seed); D=$(cat "$T/last"); M="$D/home/maint-snapshots"
check "retention: newest complete other day kept" '[ -d "$M/site.test-20260920" ]'
check "retention: older complete day pruned" '[ ! -d "$M/site.test-20260901" ]'
check "retention: incomplete day never counted (left for inspection)" '[ -d "$M/site.test-20260915" ]'
out=$(run unlock_fail update seed); expect "lock: a failed release is reported and the run is not OK" 1 'lock\|WARN\|could not release' "$out" $?
grep -q '^update|OK' <<<"$out" && { FAILS=$((FAILS+1)); echo "FAIL lock: update printed OK despite the failed release"; } || { PASS=$((PASS+1)); echo "ok   lock: no update OK when release failed"; }
out=$(run hash_fail update seed); expect "snapshot: a failed hash stops before complete" 1 '^snapshot\|FAIL\|.*sha256sum failed' "$out" $?
D=$(cat "$T/last"); check "snapshot: no complete marker after a failed hash" '[ ! -f "$D/home/maint-snapshots/site.test-$(date +%Y%m%d)/complete" ]'
never_ran "snapshot: no update after the failed hash" ' update'
# mutation failures
out=$(run plugin_fail update seed); expect "update: failing plugin stops the run with the rollback" 1 '^plugins\|FAIL\|alpha 1.0 -> 1.1 failed; rollback: files' "$out" $?
never_ran "update: beta not touched after alpha failed" 'plugin update beta'
D=$(cat "$T/last"); check "lock: released after a failed run" '[ ! -d "$D/home/maint-snapshots/.lock-site.test" ]'
out=$(run plugin_stuck update seed); expect "update: version not reached is a failure" 1 'alpha is 1.0 after update, expected 1.1' "$out" $?
out=$(run core_fail update seed); expect "update: core failure stops the run" 1 '^core\|FAIL\|core update failed' "$out" $?
out=$(run core_pending update seed); expect "update: core lands and is read back" 0 '^core\|OK\|7.1.2 -> 7.1.3' "$out" $?
out=$(run lang_fail update seed); expect "update: translation command failure stops the run" 1 '^translations\|FAIL\|language update failed' "$out" $?
out=$(run lang_left update seed); expect "update: translations still pending is a failure" 1 'translations still pending' "$out" $?
out=$(run broken_after update seed); expect "update: broken site after update fails" 1 '^update\|FAIL\|verification failed' "$out" $?
# health
out=$(run rate_limited verify); expect "health: 429 falls back to the server, page checked" 0 '^verify\|OK\|200-clean' "$out" $?
out=$(run rate_limited_broken verify); expect "health: error page seen through the 429 fallback fails" 1 'error page' "$out" $?
out=$(run curl_cut verify); expect "health: curl transfer failure is not healthy" 1 'curl failed' "$out" $?
# verify
out=$(run checksums_fail verify); expect "verify: core checksum failure fails" 1 'core checksums FAIL' "$out" $?
out=$(run verify_pending verify); expect "verify: pending plugin fails" 1 'plugins pending alpha' "$out" $?
out=$(run other_site verify); expect "verify: siteurl on another host fails" 1 'siteurl host other.test' "$out" $?
out=$(run ok_verify verify); expect "verify: clean site passes" 0 '^verify\|OK' "$out" $?

# PATH fix: a failed lookup never puts "." on PATH.
out=$(PATH=/usr/bin:/bin bash -c 'source "'"$LIB"'"; command(){ return 1; }; eval "$MARIADB_PATH_FIX"; case ":$PATH:" in *:.:*) echo dot ;; *) echo clean ;; esac' 2>&1); rc=$?
expect "PATH fix: failed lookup adds nothing" 0 '^clean$' "$out" $rc

echo "---- $PASS passed, $FAILS failed"
[ $FAILS -eq 0 ]
