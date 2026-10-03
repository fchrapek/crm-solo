#!/bin/bash
# Shared library for local retainer maintenance (WordPress in DDEV).
#
# Contract: a repo is processed only when its worktree is clean. Every pass
# runs on its own target branch through an updates/<period> branch, commits
# only allowlisted paths, verifies, then fast-forwards the target. The local
# DB is snapshotted first and restored between and after passes. Nothing is
# pushed and nothing outside the local repo and its DDEV project is touched.
#
# Every check fails closed: a command that cannot answer stops the pass, it
# never reads as "clean", "empty" or "no errors".
#
# Output: one "step|status|detail" line per step on stdout. wp-cli and DDEV
# noise goes to the run log, never into commit messages.
set -uo pipefail

STATE_ROOT="${MAINT_STATE_ROOT:-$HOME/.local/state/retainer-maintenance}"
PERIOD="${MAINT_PERIOD:-$(date +%Y-%m)}"
MAINT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Tracked files that plugins rewrite at runtime (cache configs, generated
# CSS). A bootstrap that touches them is not an update; they are restored.
RUNTIME_RE='^(wp-config\.php$|wp-config-ddev\.php$)|^wp-content/(advanced-cache\.php|object-cache\.php|wp-rocket-config/|litespeed/|cache/|wphb-cache/|smush-webp/|uploads-webpc/|updraft/|backups-dup-lite/|debug\.log)'

say(){ printf '%s|%s|%s\n' "$1" "$2" "$3"; }
die(){ say "$1" FAIL "$2"; exit 1; }

# Git with hooks, autostash and signing off, so no repo or global config can
# act during a run.
G(){
  git -C "$REPO" -c core.hooksPath=/dev/null -c merge.autoStash=false -c status.renames=false -c diff.renames=false \
    -c rebase.autoStash=false -c commit.gpgSign=false -c advice.detachedHead=false "$@"
}

# Run git, capture stdout into the named variable, fail loudly if git fails.
gcap(){ local __v="$1"; shift; local __o; __o=$(G "$@") || { say git FAIL "git $* failed"; return 1; }; printf -v "$__v" '%s' "$__o"; }

# Porcelain status of everything (untracked included). Empty means clean.
tree_status(){ local __ts_out; gcap __ts_out status --porcelain=v1 --untracked-files=all || return 1; printf -v "$1" '%s' "$__ts_out"; }

# wp-cli inside the site's DDEV web container. PHP notices go to stderr there,
# so stdout stays parseable; stderr is appended to the run log.
W(){ (cd "$REPO" && ddev wp "$@" </dev/null 2>>"$LOG"); }

# wp-cli call that must return a JSON array. Result in the named variable.
wjson(){
  local __v="$1"; shift; local __o __l
  __o=$(W "$@") || { say wp FAIL "wp $* failed (see log)"; return 1; }
  __l=$(awk 'NF{l=$0} END{print l}' <<<"$__o")
  jq -e 'type=="array"' >/dev/null 2>&1 <<<"$__l" || { say wp FAIL "wp $* did not return a JSON list"; return 1; }
  printf -v "$__v" '%s' "$__l"
}

# A single scalar from wp-cli (option values, versions). Fails when empty.
wval(){
  local __v="$1"; shift; local __raw __o
  __raw=$(W "$@") || { say wp FAIL "wp $* failed (see log)"; return 1; }
  __o=$(awk 'NF{l=$0} END{print l}' <<<"$__raw")
  [ -n "$__o" ] || { say wp FAIL "wp $* returned nothing"; return 1; }
  printf -v "$__v" '%s' "$__o"
}

# Mutagen syncs host and container with a lag. Flush after every git change
# and every wp-cli write, before anything reads the other side.
sync_fs(){
  (cd "$REPO" && ddev mutagen sync </dev/null >>"$LOG" 2>&1) && return 0
  say sync FAIL "ddev mutagen sync failed (see log)"; return 1
}

repo_is_clean(){
  local st; tree_status st || return 9
  [ -z "$st" ] || return 1
  local sl; gcap sl stash list || return 9
  [ -z "$sl" ] || return 2
  local f p
  for f in MERGE_HEAD CHERRY_PICK_HEAD REVERT_HEAD BISECT_LOG rebase-merge rebase-apply; do
    gcap p rev-parse --path-format=absolute --git-path "$f" || return 9
    [ -e "$p" ] && return 3
  done
  return 0
}

clean_reason(){
  case "$1" in
    1) echo "worktree has changes: clean it first" ;;
    2) echo "stash present: clean it first" ;;
    3) echo "merge/rebase/cherry-pick in progress: finish it first" ;;
    *) echo "git could not inspect the repo" ;;
  esac
}

default_branch(){
  G symbolic-ref --short refs/remotes/origin/HEAD 2>/dev/null | sed 's@^origin/@@'
}

# targets.conf: "<repo-dir-name> <branch> [flag=<ignored file>]". A flag file
# is created for that branch's pass and removed after (a site can select a
# second DB with an ignored marker file such as .use-rewrite).
conf_flag(){
  [ -f "$MAINT_DIR/targets.conf" ] || return 0
  awk -v n="$(basename "$REPO")" -v b="$1" '$1==n && $2==b {for(i=3;i<=NF;i++) if($i ~ /^flag=/){sub(/^flag=/,"",$i); print $i}}' "$MAINT_DIR/targets.conf"
}

# Targets: default, develop/dev if present, the checked-out branch, extra
# branches from targets.conf. Maintenance branches are never targets.
targets(){
  local def cur b
  def=$(default_branch); cur=$(G branch --show-current)
  {
    [ -n "$def" ] && echo "$def"
    for b in develop dev; do G show-ref -q --verify "refs/heads/$b" && echo "$b"; done
    [ -n "$cur" ] && echo "$cur"
    [ -f "$MAINT_DIR/targets.conf" ] && awk -v n="$(basename "$REPO")" '$1==n{print $2}' "$MAINT_DIR/targets.conf"
  } | grep -v '^updates/' | awk '!seen[$0]++' | while read -r b; do
    G show-ref -q --verify "refs/heads/$b" && echo "$b"
  done
}

update_branch_for(){
  local t="$1" def; def=$(default_branch)
  if [ "$t" = "$def" ]; then echo "updates/$PERIOD"; else echo "updates/$PERIOD-${t//\//-}"; fi
}

ddev_json(){ (cd "$REPO" && ddev describe -j </dev/null 2>/dev/null); }
ddev_running(){ ddev_json | jq -e '.raw.status=="running"' >/dev/null 2>&1; }
site_url(){ ddev_json | jq -er '.raw.primary_url'; }

# Homepage probe, local only: follows redirects but the final host must be the
# DDEV host and curl must succeed. Prints "<code> <ok-host 0|1> <html 0|1>".
home_probe(){
  local url host tmp out code eff ehost html=0 ok=0
  url=$(site_url) || { echo "000 0 0"; return 1; }
  host="${url#*://}"; host="${host%%/*}"; tmp=$(mktemp)
  out=$(curl -k -sS -L --max-redirs 5 --max-time 90 -H 'Cache-Control: no-cache' -o "$tmp" \
        -w '%{http_code} %{url_effective}' "$url/?maint_nocache=$RANDOM" 2>>"$LOG") || { rm -f "$tmp"; echo "000 0 0"; return 1; }
  code="${out%% *}"; eff="${out#* }"; ehost="${eff#*://}"; ehost="${ehost%%/*}"
  [ "$ehost" = "$host" ] && ok=1
  grep -qi '</html>' "$tmp" && html=1; rm -f "$tmp"
  echo "$code $ok $html"
}

# debug.log read inside the container (authoritative, no Mutagen lag).
# Prints the byte size, or "none" when the site does not log there.
debuglog_size(){
  (cd "$REPO" && ddev exec 'if [ -f wp-content/debug.log ]; then wc -c < wp-content/debug.log; else echo none; fi' </dev/null 2>>"$LOG") | tr -d ' \r'
}
fatals_since(){
  local start="$1" out
  [ "$start" = "none" ] && start=0
  # Prints: none | rotated | <count>. awk exits 0 whether or not it matches.
  out=$( (cd "$REPO" && ddev exec "if [ ! -f wp-content/debug.log ]; then echo none; exit 0; fi; sz=\$(wc -c < wp-content/debug.log) || exit 3; if [ \"\$sz\" -lt $start ]; then echo rotated; exit 0; fi; tail -c +$(( start + 1 )) wp-content/debug.log | awk '/PHP Fatal/{n++} END{print n+0}'" </dev/null 2>>"$LOG") | tr -d ' \r') || { echo "ERR"; return 1; }
  case "$out" in none) [ "$1" = "none" ] && echo "n/a" || echo "ERR" ;; rotated) echo "rotated" ;; ''|*[!0-9]*) echo "ERR" ;; *) echo "$out" ;; esac
}

# One lock per repository (git common dir), so projects sharing a repo run once.
lock_repo(){
  local key; key=$(G rev-parse --path-format=absolute --git-common-dir | shasum | cut -c1-16) || die lock "git common dir"
  mkdir -p "$STATE_ROOT/locks"
  LOCK="$STATE_ROOT/locks/$key"
  mkdir "$LOCK" 2>/dev/null || die lock "another run holds $LOCK"
}
unlock_repo(){ [ -n "${LOCK:-}" ] && rmdir "$LOCK" 2>/dev/null; LOCK=""; }

# Changed paths (one per line) matching an extended regex. Live config files
# are never returned; wp-config-sample.php is core and allowed.
changed_paths(){
  local __v="$1" re="$2" __cp
  # -z keeps names unquoted; NULs are turned into newlines in the pipe, before
  # anything lands in a bash variable (bash variables cannot hold NUL).
  __cp=$(G status --porcelain=v1 -z --untracked-files=all --no-renames | tr '\0' '\n') || { say git FAIL "git status failed"; return 1; }
  printf -v "$__v" '%s' "$(printf '%s\n' "$__cp" | cut -c4- | { grep -E "$re" || true; } \
    | awk '!/^wp-config/ || $0=="wp-config-sample.php"' | { grep -v '^\.maintenance$' || true; })"
}

# Put tracked runtime files back the way the target had them and remove
# untracked runtime files. They are ours: the tree was clean at the start.
restore_runtime(){
  local mod unt
  gcap mod -c diff.renames=false diff --name-only HEAD || return 1
  mod=$(printf '%s\n' "$mod" | { grep -E "$RUNTIME_RE" || true; })
  gcap unt ls-files --others --exclude-standard || return 1
  unt=$(printf '%s\n' "$unt" | { grep -E "$RUNTIME_RE" || true; })
  if [ -n "$mod" ]; then
    printf '%s\n' "$mod" | GIT_LITERAL_PATHSPECS=1 G restore --source=HEAD --staged --worktree --pathspec-from-file=- >>"$LOG" 2>&1 \
      || { say runtime FAIL "could not restore: $(echo $mod)"; return 1; }
  fi
  if [ -n "$unt" ]; then
    (cd "$REPO" && printf '%s\n' "$unt" | while read -r f; do rm -f -- "$f" || exit 1; done) \
      || { say runtime FAIL "could not remove: $(echo $unt)"; return 1; }
  fi
  RESTORED="$(echo $mod $unt)"
}

# Escape a list of slugs (one per line) for an extended regex alternation.
re_alt(){ sed 's/[.[\*^$()+?{|]/\\&/g' | paste -sd'|' -; }

# Stage exactly the allowlisted changed paths and commit. Sets COMMIT to the
# new short sha, or empty when there was nothing to commit.
commit_allowlisted(){
  local regex="$1" msg="$2" list
  COMMIT=""
  changed_paths list "$regex" || return 1
  [ -n "$list" ] || return 0
  printf '%s\n' "$list" | GIT_LITERAL_PATHSPECS=1 G add -A --pathspec-from-file=- >>"$LOG" 2>&1 || return 1
  G commit -q -m "$msg" >>"$LOG" 2>&1 || return 1
  gcap COMMIT rev-parse --short HEAD
}

# Package directories (plugins or themes) as shipped: stage everything inside,
# including files a broad .gitignore rule would hide (Yoast src/presentations,
# LiteSpeed data_structure/*.sql). Only .DS_Store stays out.
commit_packages(){
  local kind="$1" msg="$2" slugs="$3" s
  COMMIT=""
  while read -r s; do
    [ -n "$s" ] || continue
    GIT_LITERAL_PATHSPECS=1 G add -A -f -- "wp-content/$kind/$s/" >>"$LOG" 2>&1 || return 1
  done <<<"$slugs"
  local ds; gcap ds diff --cached --name-only || return 1
  ds=$(printf '%s\n' "$ds" | { grep -E '(^|/)\.DS_Store$' || true; })
  [ -n "$ds" ] && { printf '%s\n' "$ds" | GIT_LITERAL_PATHSPECS=1 G rm -q --cached --pathspec-from-file=- >>"$LOG" 2>&1 || return 1; }
  local staged; gcap staged diff --cached --name-only || return 1
  [ -n "$staged" ] || return 0
  G commit -q -m "$msg" >>"$LOG" 2>&1 || return 1
  gcap COMMIT rev-parse --short HEAD || return 1
  package_completion "$kind" "$slugs"
}

# The container (Linux, case-sensitive) can hold folders that differ only in
# case (WPML ships src/Rest/ and src/REST/). On APFS they merge and Mutagen
# delivers some files late, so git can miss them. Compare the container's
# file list with git, case-insensitively, and commit what is missing.
package_completion(){
  local kind="$1" slugs="$2" s inside tracked missing=""
  while read -r s; do
    [ -n "$s" ] || continue
    inside=$( (cd "$REPO" && ddev exec "cd wp-content/$kind/$s && find . -type f ! -name .DS_Store | sed 's|^\\./||'" </dev/null 2>>"$LOG") | tr -d '\r') \
      || { say package FAIL "could not list wp-content/$kind/$s in the container"; return 1; }
    gcap tracked ls-files -- "wp-content/$kind/$s/" || return 1
    missing="$missing$(comm -23 <(printf '%s\n' "$inside" | sed "s|^|wp-content/$kind/$s/|" | tr '[:upper:]' '[:lower:]' | sort -u) \
                                 <(printf '%s\n' "$tracked" | tr '[:upper:]' '[:lower:]' | sort -u))
"
  done <<<"$slugs"
  missing=$(printf '%s' "$missing" | grep -v '^$' || true)
  [ -n "$missing" ] || return 0
  # Add each missing file under the path as the container spells it.
  local f real
  while read -r f; do
    real=$(cd "$REPO" && ddev exec "cd wp-content && find $kind -ipath '$(sed 's|^wp-content/||' <<<"$f")' -type f | head -1" </dev/null 2>>"$LOG" | tr -d '\r')
    [ -n "$real" ] || { say package FAIL "missing file $f not found in the container"; return 1; }
    sync_fs || return 1
    GIT_LITERAL_PATHSPECS=1 G add -f -- "wp-content/$real" >>"$LOG" 2>&1 || { say package FAIL "could not add wp-content/$real"; return 1; }
  done <<<"$missing"
  G commit -q -m "Update $kind: add package files git missed (case-variant folders)" >>"$LOG" 2>&1 || return 1
  local c2; gcap c2 rev-parse --short HEAD || return 1
  say package INFO "completion commit $c2: $(printf '%s\n' "$missing" | wc -l | tr -d ' ') file(s) under case-variant folders"
}

# Plugin directories git does not manage at all (no tracked file inside):
# updating them would change the local site without changing the commit.
unmanaged_plugins(){
  local d out=""
  for d in "$REPO"/wp-content/plugins/*/; do
    d="${d%/}"; d="${d##*/}"
    [ -n "$(G ls-files -- "wp-content/plugins/$d/" | head -1)" ] || out="$out,$d"
  done
  echo "${out#,}"
}
