#!/usr/bin/env bash
# Stop (async + asyncRewake): the slower checks, only when this session edited
# files since the last run. Runs in the background after Claude stops and wakes
# it (exit 2) with a report when something fails.
#
#   - vue-tsc, when .vue/.ts changed
#   - pint --test and the Pest tests affected by the change (--tia), when PHP changed
#
# Browser tests need a build and take minutes: CI runs them, this does not.

set -u
. "$(dirname "$0")/lib/common.sh"

INPUT="$(cat)"
SESSION="$(jq -r '.session_id // "nosession"' <<<"$INPUT")"
# Claude is already continuing because of a Stop hook: don't start another round.
[ "$(jq -r '.stop_hook_active // false' <<<"$INPUT")" = "true" ] && exit 0
TRACK="$(touched_file "$SESSION")"
[ -s "$TRACK" ] || exit 0

FILES="$(sort -u "$TRACK")"
rm -f "$TRACK"   # consume: the next Stop is a no-op unless new edits happen

REPORT=""
fail() { REPORT+=$'\n'"[FAIL] $1"$'\n'"$2"$'\n'; }
skip() { REPORT+=$'\n'"[SKIP] $1"$'\n'; }

cd "$ROOT" || exit 0

# 1. Types.
if grep -qE '\.(vue|ts)$' <<<"$FILES"; then
  OUT="$(npx --no-install vue-tsc --noEmit 2>&1)" || fail "vue-tsc --noEmit" "$(tail -n 40 <<<"$OUT")"
fi

# 2. PHP style and affected tests.
PHP_FILES="$(grep -E '\.php$' <<<"$FILES" | while read -r f; do [ -f "$f" ] && echo "$f"; done)"
if [ -n "$PHP_FILES" ]; then
  # shellcheck disable=SC2086
  OUT="$(shq_php ./vendor/bin/pint --test $PHP_FILES 2>&1)"
  EC=$?
  if [ $EC -eq 127 ]; then
    skip "pint and Pest (no PHP runner: start the compose app container or use the dev box)"
  else
    [ $EC -ne 0 ] && fail "pint --test" "$OUT"
    OUT="$(shq_php ./vendor/bin/pest --compact --parallel --tia 2>&1)" || fail "pest --tia (affected tests)" "$(tail -n 60 <<<"$OUT")"
  fi
fi

if grep -q '^\[FAIL\]' <<<"$REPORT"; then
  printf 'Checks after this session failed:%s\n' "$REPORT" >&2
  exit 2
fi
exit 0
