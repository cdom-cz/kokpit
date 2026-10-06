#!/usr/bin/env bash
# check-sensitive.sh - block secrets and instance-specific values before they enter the repository.
#
# Usage: scripts/check-sensitive.sh [--staged|--all]
#   (no flag) / --staged   scan the added lines of the staged diff (what a commit would add)
#   --all                  scan every tracked text file as stored in the index
#
# Output: "path:line: rule [masked]" on stdout, one line per finding. A value is never printed in full:
# at most its first 2 characters plus its length. Notices and errors go to stderr.
# Exit codes: 0 clean, 1 findings, 2 usage error, not a git repository, or scanner error (fail closed).
#
# Read-only: never writes to the git index or the working tree. Portable to bash 3.2 (macOS) and Linux.
set -eu

usage() {
  printf 'usage: check-sensitive.sh [--staged|--all]\n' >&2
  exit 2
}

mode=staged
if [ "$#" -gt 1 ]; then usage; fi
if [ "$#" -eq 1 ]; then
  case "$1" in
    --staged) mode=staged ;;
    --all) mode=all ;;
    *) usage ;;
  esac
fi

here=$(cd "$(dirname "$0")" && pwd)
if ! top=$(git rev-parse --show-toplevel 2>/dev/null); then
  echo 'check-sensitive: not a git repository' >&2
  exit 2
fi
cd "$top"

if ! tmp=$(mktemp -d "${TMPDIR:-/tmp}/kokpit.XXXXXX"); then
  echo 'check-sensitive: cannot create a temporary directory' >&2
  exit 2
fi
# EXIT removes the directory on every path; INT/TERM convert to exit 2 so the EXIT trap still runs.
trap 'rm -rf "$tmp"' EXIT
trap 'exit 2' INT TERM

# Producer: every row is "path<TAB>line<TAB>text".
if [ "$mode" = staged ]; then
  if ! git -c core.quotepath=off diff --cached -U0 --no-color --no-ext-diff \
      --src-prefix=a/ --dst-prefix=b/ --diff-filter=ACMR > "$tmp/diff.txt"; then
    echo 'check-sensitive: git diff failed' >&2
    exit 2
  fi
  if ! LC_ALL=C awk -f "$here/lib/diff2tsv.awk" "$tmp/diff.txt" > "$tmp/rows.tsv"; then
    echo 'check-sensitive: diff parser error' >&2
    exit 2
  fi
else
  rc=0
  git -c core.quotepath=off grep --cached -I -n -z -e '' > "$tmp/grep.out" || rc=$?
  # git grep: 0 = matches, 1 = no lines at all (empty tree); anything else is an error.
  if [ "$rc" -gt 1 ]; then
    echo 'check-sensitive: git grep failed' >&2
    exit 2
  fi
  LC_ALL=C tr '\0' '\t' < "$tmp/grep.out" > "$tmp/rows.tsv"
fi

# D-05: the scanner, its libraries, its allowlist and the gitleaks config necessarily contain the patterns.
# Exclude exactly these five paths, never directories, so a secret cannot hide in another file under scripts/.
LC_ALL=C awk -F '\t' '
  BEGIN {
    skip["scripts/check-sensitive.sh"] = 1
    skip["scripts/lib/scan.awk"] = 1
    skip["scripts/lib/diff2tsv.awk"] = 1
    skip["scripts/sensitive-allowlist.txt"] = 1
    skip[".gitleaks.toml"] = 1
  }
  !($1 in skip)
' "$tmp/rows.tsv" > "$tmp/scan.tsv"

# CS_ALLOWLIST is always set here, so a caller cannot redirect the allowlist. A missing file = no exemptions.
rc=0
CS_ALLOWLIST="$here/sensitive-allowlist.txt" LC_ALL=C awk -f "$here/lib/scan.awk" "$tmp/scan.tsv" || rc=$?

case "$rc" in
  0)
    echo "check-sensitive: clean ($mode)" >&2
    exit 0
    ;;
  1)
    echo 'check-sensitive: findings above; see CONTRIBUTING.md' >&2
    exit 1
    ;;
  *)
    # For example an awk regex engine panic. Never treated as "clean".
    echo 'check-sensitive: scanner error' >&2
    exit 2
    ;;
esac
