#!/usr/bin/env bash
# PreToolUse (Bash): stops the few commands that break repo rules or lose work.
# Deny = never right here. Ask = sometimes right; the user decides.
# Everything else falls through to the normal permission rules.

set -u
INPUT="$(cat)"
CMD="$(jq -r '.tool_input.command // empty' <<<"$INPUT")"
[ -z "$CMD" ] && exit 0

decide() {
  jq -n --arg d "$1" --arg r "$2" '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: $d, permissionDecisionReason: $r}}'
  exit 0
}

# AGENTS.md (Git): never add Co-Authored-By lines.
if grep -qE '\bgit\b.*\bcommit\b' <<<"$CMD" && grep -qiE 'co-authored-by|generated with \[?claude' <<<"$CMD"; then
  decide deny "AGENTS.md forbids Co-Authored-By and AI attribution lines in commits. Commit without them."
fi

# Force-pushing main or rewriting shared history.
if grep -qE '\bgit\b.*\bpush\b' <<<"$CMD" && grep -qE '(\s--force(\s|$)|\s-f(\s|$)|\s\+[a-z])' <<<"$CMD"; then
  decide deny "Force push is off. Use --force-with-lease on your own feature branch, and never on main."
fi

# Destroying a database that is not the test one.
if grep -qE 'artisan\s+(migrate:fresh|migrate:reset|migrate:rollback|db:wipe)' <<<"$CMD" && ! grep -q -- '--env=testing' <<<"$CMD"; then
  decide ask "This wipes or rolls back the development database. Approve only if losing its data is fine."
fi

# Boost rewrites the Boost block of AGENTS.md and its skills in .claude/skills.
if grep -qE 'artisan\s+boost:(install|update)' <<<"$CMD"; then
  decide ask "boost:install/update rewrites the Boost block of AGENTS.md and the Boost skills in .claude/skills, overwriting our edits there. Review the diff afterwards."
fi

# AGENTS.md: dependencies change only with approval.
if grep -qE '\bcomposer\s+(require|remove|update)\b|\bnpm\s+(install|i|add|uninstall|remove)\s+[^-]' <<<"$CMD"; then
  decide ask "This changes dependencies, which needs the user's approval (AGENTS.md)."
fi

# Throwing away uncommitted work.
if grep -qE '\bgit\s+(reset\s+--hard|clean\s+-[a-z]*f|checkout\s+--\s|restore\s+\.)' <<<"$CMD"; then
  decide ask "This discards uncommitted changes."
fi

exit 0
