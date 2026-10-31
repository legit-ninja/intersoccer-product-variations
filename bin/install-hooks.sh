#!/usr/bin/env bash
#
# Installs the InterSoccer pre-commit hook (bin/pre-commit) for this clone.
#
#   bin/install-hooks.sh               install
#   bin/install-hooks.sh --uninstall   remove it (restores any hook it replaced)
#   bin/install-hooks.sh --hooks-dir D install into directory D instead
#
# The installed hook is a small wrapper that runs bin/pre-commit from whichever
# worktree you commit in, and does nothing in worktrees whose branch does not
# have bin/pre-commit yet. If a pre-commit hook already existed it is kept as
# pre-commit.previous and still runs first.
#
# Bypass the checks for a single commit with: git commit --no-verify

set -eu

MARKER="intersoccer-pre-commit-wrapper"
HOOKS_DIR=""
ACTION=install
while [ $# -gt 0 ]; do
    case "$1" in
        --uninstall) ACTION=uninstall ;;
        --hooks-dir) HOOKS_DIR=$2; shift ;;
        -h|--help) sed -n '2,14p' "$0"; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
    shift
done

git rev-parse --show-toplevel >/dev/null
if [ -z "$HOOKS_DIR" ]; then
    # Honours core.hooksPath; for linked worktrees this is the shared hooks dir.
    HOOKS_DIR=$(git rev-parse --git-path hooks)
fi
mkdir -p "$HOOKS_DIR"
HOOK="$HOOKS_DIR/pre-commit"

if [ "$ACTION" = uninstall ]; then
    if [ -f "$HOOK" ] && grep -q "$MARKER" "$HOOK"; then
        rm -f "$HOOK"
        [ -f "$HOOKS_DIR/pre-commit.previous" ] && mv "$HOOKS_DIR/pre-commit.previous" "$HOOK"
        echo "Removed the InterSoccer pre-commit hook."
    else
        echo "InterSoccer pre-commit hook is not installed."
    fi
    exit 0
fi

if [ -f "$HOOK" ] && ! grep -q "$MARKER" "$HOOK"; then
    mv "$HOOK" "$HOOKS_DIR/pre-commit.previous"
    echo "Kept your existing pre-commit hook as pre-commit.previous (it still runs first)."
fi

cat > "$HOOK" <<'WRAPPER'
#!/bin/sh
# intersoccer-pre-commit-wrapper (installed by bin/install-hooks.sh)
hooks_dir=$(dirname "$0")
if [ -x "$hooks_dir/pre-commit.previous" ]; then
    "$hooks_dir/pre-commit.previous" "$@" || exit $?
fi
top=$(git rev-parse --show-toplevel 2>/dev/null) || exit 0
if [ -f "$top/bin/pre-commit" ]; then
    exec bash "$top/bin/pre-commit" "$@"
fi
exit 0
WRAPPER
chmod +x "$HOOK"

# Create an empty local deny-list (outside the repo) if there is none yet.
DENY_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/intersoccer-hooks"
if [ ! -f "$DENY_DIR/denylist" ]; then
    mkdir -p "$DENY_DIR"
    printf '%s\n' "# Local deny-list for the InterSoccer pre-commit hook." \
        "# One string per line (case-insensitive), e.g. internal hostnames." \
        "# This file lives outside the repo; never commit its contents." > "$DENY_DIR/denylist"
    chmod 600 "$DENY_DIR/denylist"
    echo "Created an empty local deny-list in $DENY_DIR"
fi

echo "Installed the InterSoccer pre-commit hook."
echo "Bypass for one commit with: git commit --no-verify"
