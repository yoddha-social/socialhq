#!/usr/bin/env bash
# PreToolUse (Edit|Write|MultiEdit).
#   1. Denies edits to built, generated and secret files, saying what to edit instead.
#   2. Notes TryPost base files missing from PATCHES.md, and Boost skill copies.
#   3. Hands Claude every .ai/rules file whose globs (.ai/rules/index.md) cover the
#      path, once per session. AGENTS.md says to read them; this makes it happen.

set -u
. "$(dirname "$0")/lib/common.sh"

INPUT="$(cat)"
FILE="$(jq -r '.tool_input.file_path // empty' <<<"$INPUT")"
SESSION="$(jq -r '.session_id // "nosession"' <<<"$INPUT")"
[ -z "$FILE" ] && exit 0
REL="$(rel_path "$FILE")" || exit 0

deny() {
  jq -n --arg r "$1" '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "deny", permissionDecisionReason: $r}}'
  exit 0
}

case "$REL" in
  vendor/*|node_modules/*|public/build/*|bootstrap/ssr/*|storage/framework/*)
    deny "$REL is installed or built output. Change the source instead." ;;
  resources/js/actions/*|resources/js/routes/*|resources/js/wayfinder/*)
    deny "Wayfinder generates $REL. Change the PHP route or controller; the files regenerate on the next build." ;;
  .env|.env.production|.env.backup)
    deny "$REL holds secrets. Add the variable to .env.example and tell the user what to set." ;;
  composer.lock|package-lock.json)
    deny "$REL is written by composer/npm. Change dependencies with the tool, after the user approves." ;;
  lang/en/socialhq.php) ;;
  lang/*/socialhq.php)
    deny "bin/socialhq-sync-lang overwrites $REL from lang/en/socialhq.php. Edit the English file; the PostToolUse hook runs the sync." ;;
esac

NOTES=""

case "$REL" in
  .claude/skills/socialhq-*|.agents/skills/socialhq-*) ;;
  .claude/skills/*|.agents/skills/*)
    NOTES+="$REL is a Boost skill: a manual boost:update copies it again. To keep a change, put it in .ai/skills/<same-name>/SKILL.md, which overrides the Boost copy."$'\n' ;;
esac

# A file from the TryPost base: fine to edit, but log it so security
# cherry-picks from TryPost know where they may conflict.
if ! is_module_path "$REL" && (cd "$ROOT" && git ls-files --error-unmatch -- "$REL" >/dev/null 2>&1) \
   && ! grep -qF "\`$REL\`" "$ROOT/PATCHES.md" 2>/dev/null; then
  NOTES+="$REL comes from the TryPost base and is not in PATCHES.md yet. Add a row (file, change, why) in the same change."$'\n'
fi

# Matching .ai/rules files, once per session each.
INDEX="$ROOT/.ai/rules/index.md"
SEEN="$STATE_DIR/$SESSION.rules"
if [ -f "$INDEX" ]; then
  while IFS='|' read -r _ globs rule _; do
    rule="$(xargs <<<"$rule")"
    case "$rule" in .ai/rules/*.md) ;; *) continue ;; esac
    grep -qxF "$rule" "$SEEN" 2>/dev/null && continue
    IFS=',' read -ra parts <<<"$globs"
    for g in "${parts[@]}"; do
      g="$(xargs <<<"$g")"
      # In [[ == ]] a * also matches "/", so "app/**" covers every depth.
      # shellcheck disable=SC2053
      if [[ "$REL" == $g ]]; then
        printf '%s\n' "$rule" >>"$SEEN"
        NOTES+=$'\n'"Project rule $rule applies to $REL:"$'\n'"$(cat "$ROOT/$rule")"$'\n'
        break
      fi
    done
  done < <(grep '^|' "$INDEX")
fi

[ -z "$NOTES" ] && exit 0
jq -n --arg c "$NOTES" '{hookSpecificOutput: {hookEventName: "PreToolUse", additionalContext: $c}}'
