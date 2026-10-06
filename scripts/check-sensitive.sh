#!/usr/bin/env bash
# check-sensitive.sh - block secrets and instance-specific values before they enter the repository.
#
# Usage: scripts/check-sensitive.sh [--staged|--all]
#   (no flag) / --staged   scan the added lines of the staged diff (what a commit would add)
#   --all                  scan every tracked text file as stored in the index
#
# Output: "path:line: rule [masked]" on stdout, one line per finding. A value is never printed in full:
# at most its first 2 characters plus its length. Notices and errors go to stderr.
#
# Local denylist (D-06): KOKPIT_DENYLIST names a file OUTSIDE the repository, one fixed term per line
# ("#" comments and blank lines ignored). Rows containing a term (case-insensitive, Czech diacritics folded)
# are reported as "path:line: denylist"; the term and the line text are never printed. Unset or missing
# file: one notice on stderr and generic rules only (the CI path). Unreadable or in-repository: exit 2.
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
orig_pwd=$PWD
cd "$top"

if ! tmp=$(mktemp -d "${TMPDIR:-/tmp}/kokpit.XXXXXX"); then
  echo 'check-sensitive: cannot create a temporary directory' >&2
  exit 2
fi
# EXIT removes the directory on every path; INT/TERM convert to exit 2 so the EXIT trap still runs.
trap 'rm -rf "$tmp"' EXIT
trap 'exit 2' INT TERM

# Denylist state (D-06). Decided before any scanning so a misconfiguration fails loudly, never silently.
deny_active=0
dl=${KOKPIT_DENYLIST:-}
if [ -z "$dl" ]; then
  echo 'check-sensitive: KOKPIT_DENYLIST not set; generic patterns only' >&2
else
  case "$dl" in /*) ;; *) dl="$orig_pwd/$dl" ;; esac
  if [ ! -e "$dl" ]; then
    echo 'check-sensitive: KOKPIT_DENYLIST file not found; generic patterns only' >&2
  elif [ ! -f "$dl" ] || [ ! -r "$dl" ]; then
    echo 'check-sensitive: KOKPIT_DENYLIST is not readable' >&2
    exit 2
  else
    # HYG-03: the real list must never be committable. Compare physical directories (pwd -P on both
    # sides, no symlink-resolving flags: bash 3.2 and BSD tools); equal or prefix-with-slash = inside.
    dl_dir=$(cd "$(dirname "$dl")" && pwd -P) || dl_dir=''
    top_phys=$(cd "$top" && pwd -P)
    case "$dl_dir/" in
      "$top_phys"/*)
        echo 'check-sensitive: KOKPIT_DENYLIST must live outside the repository' >&2
        exit 2
        ;;
    esac
    # An empty pattern makes `grep -f` match EVERY line, so the copy drops CR, surrounding whitespace,
    # blank lines and comments (Pitfall 6). The grep -v exit status 1 (no terms left) is not an error.
    LC_ALL=C tr -d '\r' < "$dl" \
      | LC_ALL=C sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
      | LC_ALL=C grep -v -e '^#' -e '^$' > "$tmp/deny" || true
    if [ -s "$tmp/deny" ]; then
      deny_active=1
    else
      echo 'check-sensitive: KOKPIT_DENYLIST has no terms' >&2
    fi
  fi
fi

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
if [ "$rc" -gt 1 ]; then
  # For example an awk regex engine panic. Never treated as "clean".
  echo 'check-sensitive: scanner error' >&2
  exit 2
fi

# Denylist stage (D-06): same filtered rows as the generic scanner. Matched against path and text only (the
# line-number field is dropped, so a numeric term cannot match line numbers). C.UTF-8 makes -i fold Czech
# capitals, which LC_ALL=C does not; -a keeps invalid UTF-8 from turning a match into "Binary file matches".
# Only "path:line: denylist" is printed: never the term, never the line text (T-01-13).
deny_found=0
if [ "$deny_active" -eq 1 ]; then
  LC_ALL=C cut -f1,3- "$tmp/scan.tsv" > "$tmp/pt.txt"
  grc=0
  LC_ALL=C.UTF-8 grep -F -i -a -n -f "$tmp/deny" "$tmp/pt.txt" > "$tmp/deny.hits" 2> /dev/null || grc=$?
  if [ "$grc" -gt 1 ]; then
    echo 'check-sensitive: denylist matcher error' >&2
    exit 2
  fi
  if [ -s "$tmp/deny.hits" ]; then
    LC_ALL=C cut -d: -f1 "$tmp/deny.hits" > "$tmp/deny.ln"
    LC_ALL=C awk -F '\t' '
      NR == FNR { want[$1] = 1; next }
      (FNR in want) { printf "%s:%s: denylist\n", $1, $2; n++ }
      END { exit(n > 0 ? 1 : 0) }
    ' "$tmp/deny.ln" "$tmp/scan.tsv" || deny_found=1
  fi
fi

if [ "$rc" -eq 1 ] || [ "$deny_found" -eq 1 ]; then
  echo 'check-sensitive: findings above; see CONTRIBUTING.md' >&2
  exit 1
fi
echo "check-sensitive: clean ($mode)" >&2
exit 0
