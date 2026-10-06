#!/usr/bin/env bash
# install-hooks.sh - install the lefthook-managed pre-commit hook in this clone (run once per clone).
#
# Fail closed (D-09): exits 1 with an install hint when lefthook or gitleaks is missing, and exits 1 with a
# hint when core.hooksPath is set (lefthook will not install over it and this script never forces it).
# Otherwise runs `lefthook install` and `lefthook check-install`; running it again changes nothing.
# Tested with lefthook 2.1.17 and gitleaks 8.30.1: other versions only produce a warning.
set -eu

LEFTHOOK_TESTED=2.1.17
GITLEAKS_TESTED=8.30.1

cd "$(dirname "$0")/.."

missing=0
if ! command -v lefthook > /dev/null 2>&1; then
  echo 'install-hooks: lefthook is not installed.' >&2
  echo "  Install it with: brew install lefthook   or   mise use -g lefthook@$LEFTHOOK_TESTED" >&2
  missing=1
fi
if ! command -v gitleaks > /dev/null 2>&1; then
  echo 'install-hooks: gitleaks is not installed.' >&2
  echo "  Install it with: brew install gitleaks   or   mise use -g gitleaks@$GITLEAKS_TESTED" >&2
  missing=1
fi
if [ "$missing" -ne 0 ]; then exit 1; fi

case "$(lefthook version 2> /dev/null || true)" in
  *"$LEFTHOOK_TESTED"*) ;;
  *) echo "install-hooks: warning: tested with lefthook $LEFTHOOK_TESTED, found a different version" >&2 ;;
esac
case "$(gitleaks version 2> /dev/null || true)" in
  *"$GITLEAKS_TESTED"*) ;;
  *) echo "install-hooks: warning: tested with gitleaks $GITLEAKS_TESTED, found a different version" >&2 ;;
esac

if ! git rev-parse --git-dir > /dev/null 2>&1; then
  echo 'install-hooks: not inside a git repository' >&2
  exit 1
fi

hooks_path=$(git config --get core.hooksPath 2> /dev/null || true)
if [ -n "$hooks_path" ]; then
  echo 'install-hooks: core.hooksPath is set, so lefthook cannot install its hook.' >&2
  echo '  Find out which tool set it:  git config --show-origin --get core.hooksPath' >&2
  echo '  If it is safe to hand the path over to lefthook, run:  lefthook install --reset-hooks-path' >&2
  exit 1
fi

lefthook install
lefthook check-install
echo 'install-hooks: pre-commit hook installed (sensitive-content, gitleaks)'
