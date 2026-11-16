#!/usr/bin/env bash
# Shared helpers for the Social HQ Claude Code hooks. Sourced, never run.
#
# The laptop has no PHP, so PHP tools run in this order: php on PATH (the dev
# box), then the running `app` container of compose.yaml, then nowhere (the
# hook skips and says so, rather than failing).

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
STATE_DIR="${TMPDIR:-/tmp}/claude-socialhq"
mkdir -p "$STATE_DIR"

# Paths that belong to the Social HQ module (see SOCIALHQ.md and the "Files we
# added" list in PATCHES.md). Anything else that git tracks came from the
# TryPost base and is listed in PATCHES.md when edited.
MODULE_RE='^(app/SocialHq/|database/(migrations/socialhq|factories/SocialHq|data/socialhq)/|routes/socialhq(\.php$|/)|config/socialhq\.php$|resources/js/(pages|components)/socialhq/|resources/views/prompts/socialhq/|resources/views/mail/socialhq-|maizzle/templates/socialhq-|public/images/socialhq/|tests/(Feature|Unit|Browser)/SocialHq/|tests/fixtures/socialhq/|lang/[A-Za-z_-]+/socialhq(_[a-z]+)?\.php$|bin/socialhq-|\.github/workflows/socialhq-|\.ai/skills/socialhq-|\.(claude|agents)/skills/socialhq-|\.claude/(hooks|rules)/|\.claude/settings\.json$|CLAUDE\.md$|docs/(history|upstream)/|plans/|PATCHES\.md$|SOCIALHQ\.md$|SUGGESTIONS\.md$)'

# The TryPost tree to compare against, when this clone has it: the fork point
# and upstream's main. A path in neither was added by us.
UPSTREAM_REF="$(cd "$ROOT" && git rev-parse -q --verify refs/remotes/upstream/main 2>/dev/null)"
FORK_BASE="${UPSTREAM_REF:+$(cd "$ROOT" && git merge-base HEAD "$UPSTREAM_REF" 2>/dev/null)}"

is_module_path() {
  [[ "$1" =~ $MODULE_RE ]] && return 0
  [ -n "$FORK_BASE" ] || return 1
  ! (cd "$ROOT" && { git cat-file -e "$FORK_BASE:$1" 2>/dev/null || git cat-file -e "$UPSTREAM_REF:$1" 2>/dev/null; })
}

# Every path pattern in the File column of PATCHES.md's table, one per line.
# A bare name (`MailLocalizationTest.php`) is taken as written (a file at the
# root) and in the folder of the path before it in the same cell; one {a,b}
# group is expanded; * and ** stay globs.
patches_patterns() {
  local tok dir="" pre rest inner post a alts
  while IFS= read -r tok; do
    case "$tok" in
      @@ROW) dir=""; continue ;;
      */*) dir="${tok%/*}/" ;;
      *) [ -n "$dir" ] && printf '%s\n' "$tok"; tok="$dir$tok" ;;
    esac
    if [[ "$tok" == *"{"*"}"* ]]; then
      pre="${tok%%\{*}"; rest="${tok#*\{}"; inner="${rest%%\}*}"; post="${rest#*\}}"
      IFS=',' read -ra alts <<<"$inner"
      for a in "${alts[@]}"; do printf '%s\n' "$pre$a$post"; done
    else
      printf '%s\n' "$tok"
    fi
  done < <(awk -F'|' '/^\| [0-9]+ \|/ { n = split($3, t, "`"); for (i = 2; i <= n; i += 2) print t[i]; print "@@ROW" }' "$ROOT/PATCHES.md" 2>/dev/null)
}

# Whether PATCHES.md lists the path: exactly, through a glob or brace row, or by
# a listed folder (`public/images/socialhq/*`, `app/Mcp/Tools/**`).
PATCHES_PATTERNS=""
in_patches() {
  local pat
  [ -n "$PATCHES_PATTERNS" ] || PATCHES_PATTERNS="$(patches_patterns)"
  while IFS= read -r pat; do
    # In [[ == ]] an unquoted * also matches "/", so ** and * cover every depth.
    # shellcheck disable=SC2053
    [[ "$1" == $pat || "$1" == "${pat%/}"/* ]] && return 0
  done <<<"$PATCHES_PATTERNS"
  return 1
}

# TryPost base files changed on this branch (against main's merge base, working
# tree included) that PATCHES.md does not list yet.
unlisted_patches() {
  local base f
  base="$(cd "$ROOT" && { git merge-base HEAD main 2>/dev/null || git merge-base HEAD origin/main 2>/dev/null; })" || return 0
  [ -n "$base" ] || return 0
  while IFS= read -r f; do
    is_module_path "$f" || in_patches "$f" || printf '%s\n' "$f"
  done < <(cd "$ROOT" && git diff --name-only "$base" --)
}

# Echo a repo-relative path for an absolute one inside the repo; fail otherwise.
rel_path() {
  case "$1" in
    "$ROOT"/*) printf '%s' "${1#"$ROOT"/}" ;;
    *) return 1 ;;
  esac
}

# Run a PHP command from the repo root: shq_php ./vendor/bin/pint app/Foo.php
# Returns 127 when no PHP runner is available.
shq_php() {
  if command -v php >/dev/null 2>&1; then
    (cd "$ROOT" && "$@")
  elif (cd "$ROOT" && docker compose ps --status running --services 2>/dev/null | grep -qx app); then
    (cd "$ROOT" && docker compose exec -T app "$@")
  else
    return 127
  fi
}

php_runner_name() {
  if command -v php >/dev/null 2>&1; then echo "php on PATH"
  elif (cd "$ROOT" && docker compose ps --status running --services 2>/dev/null | grep -qx app); then echo "docker compose app container"
  else echo "none"
  fi
}

touched_file() { printf '%s/%s.touched' "$STATE_DIR" "$1"; }
