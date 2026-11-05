#!/usr/bin/env bash
# Shared helpers for the Social HQ Claude Code hooks. Sourced, never run.
#
# The laptop has no PHP, so PHP tools run in this order: php on PATH (the dev
# box), then the running `app` container of compose.yaml, then nowhere (the
# hook skips and says so, rather than failing).

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
STATE_DIR="${TMPDIR:-/tmp}/claude-socialhq"
mkdir -p "$STATE_DIR"

# Paths that belong to the Social HQ module (see SOCIALHQ.md). Anything else
# that git tracks came from the TryPost base and is listed in PATCHES.md when edited.
MODULE_RE='^(app/SocialHq/|database/migrations/socialhq/|routes/socialhq\.php$|config/socialhq\.php$|resources/js/(pages|components)/socialhq/|tests/(Feature|Unit|Browser)/SocialHq/|lang/[a-z_]+/socialhq\.php$|bin/socialhq-|\.github/workflows/socialhq-|\.ai/skills/socialhq-|\.claude/(hooks|rules|skills/socialhq-|settings\.json$|CLAUDE\.md$)|CLAUDE\.md$|docs/|PATCHES\.md$|SOCIALHQ\.md$)'

is_module_path() { [[ "$1" =~ $MODULE_RE ]]; }

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
