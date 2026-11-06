#!/usr/bin/env bash
# PostToolUse (Edit|Write|MultiEdit): format and lint the one file just edited,
# the way CI's linter job will (composer lint, npm run format, npm run lint).
# Fixes what it can silently; reports the rest via stderr + exit 2 so Claude
# corrects it. Records the file for checks-on-stop.sh.

set -u
. "$(dirname "$0")/lib/common.sh"

INPUT="$(cat)"
FILE="$(jq -r '.tool_input.file_path // .tool_response.filePath // empty' <<<"$INPUT")"
SESSION="$(jq -r '.session_id // "nosession"' <<<"$INPUT")"
[ -z "$FILE" ] && exit 0
REL="$(rel_path "$FILE")" || exit 0
[ -f "$FILE" ] || exit 0

case "$REL" in
  vendor/*|node_modules/*|public/build/*|storage/*|bootstrap/cache/*) exit 0 ;;
esac

printf '%s\n' "$REL" >>"$(touched_file "$SESSION")"
PROBLEMS=""

case "$REL" in
  *.php)
    OUT="$(shq_php ./vendor/bin/pint --quiet "$REL" 2>&1)"
    EC=$?
    if [ $EC -eq 127 ]; then
      printf 'pint skipped: no PHP runner (php on PATH or the compose app container)\n' >&2
    elif [ $EC -ne 0 ]; then
      PROBLEMS+="pint could not format $REL:"$'\n'"$OUT"$'\n'
    fi
    ;;
  resources/js/*.vue|resources/js/*.ts)
    (cd "$ROOT" && npx --no-install prettier --write --log-level warn "$REL" >/dev/null 2>&1)
    OUT="$(cd "$ROOT" && npx --no-install eslint --fix "$REL" 2>&1)"
    [ $? -ne 0 ] && PROBLEMS+="ESLint issues in $REL:"$'\n'"$OUT"$'\n'
    ;;
esac

# Every locale must carry the English module copy (TryPost's locale parity test).
if [ "$REL" = "lang/en/socialhq.php" ]; then
  (cd "$ROOT" && bin/socialhq-sync-lang) || PROBLEMS+="bin/socialhq-sync-lang failed"$'\n'
fi

# Our tables are shq_*. A change to a TryPost table is allowed, but listed in PATCHES.md.
case "$REL" in
  database/migrations/socialhq/*.php)
    while IFS= read -r t; do
      [ -z "$t" ] && continue
      case "$t" in
        shq_*) ;;
        *) grep -qF "\`$t\`" "$ROOT/PATCHES.md" 2>/dev/null \
             || PROBLEMS+="$REL changes TryPost's table $t: list \`$t\` in PATCHES.md. New tables of ours are named shq_*."$'\n' ;;
      esac
    done < <(grep -oE "Schema::(create|table)\('[a-z0-9_]+'" "$FILE" | sed -E "s/.*\('//; s/'$//" | sort -u)
    ;;
  database/migrations/*.php)
    PROBLEMS+="$REL is in TryPost's migrations folder. Our migrations go in database/migrations/socialhq/."$'\n'
    ;;
esac

if [ -n "$PROBLEMS" ]; then
  printf '%s' "$PROBLEMS" >&2
  exit 2
fi
exit 0
