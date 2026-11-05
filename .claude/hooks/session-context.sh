#!/usr/bin/env bash
# SessionStart (startup|resume|clear|compact): a few lines of live state that
# CLAUDE.md cannot know. Stdout becomes context. Keep it short.

set -u
. "$(dirname "$0")/lib/common.sh"
cd "$ROOT" || exit 0

BRANCH="$(git branch --show-current 2>/dev/null)"
AHEAD="$(git rev-list --count main..HEAD 2>/dev/null || echo ?)"
DIRTY="$(git status --porcelain 2>/dev/null | wc -l | tr -d ' ')"

echo "Social HQ: branch ${BRANCH:-detached} (${AHEAD} commits ahead of main, ${DIRTY} uncommitted files)."
echo "PHP runner: $(php_runner_name). Without one, pint and Pest cannot run here: say so instead of claiming they passed."
[ "$BRANCH" = main ] && echo "On main: create a branch before changing anything."
exit 0
