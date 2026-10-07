#!/usr/bin/env bash
# check-sensitive.sh - block secrets and instance-specific values before they enter the repository.
#
# Usage: scripts/check-sensitive.sh [--staged | --all | --history] [--] [FILE...]
#   (no flag) / --staged   scan the added lines of the staged diff (what a commit would add), including
#                          files git treats as binary, files with a -diff attribute or a textconv driver,
#                          and type changes
#   --all                  scan every tracked text file as stored in the index
#   --history              scan the added lines of every commit reachable from any ref (git log --all);
#                          findings are "path:line@<sha7>: rule [masked]" naming the introducing commit
#   FILE...                scan the named files as they are on disk (paths reported repository-relative)
# A mode flag and FILE operands are mutually exclusive, and so are two mode flags (exit 2).
#
# Output: "path:line: rule [masked]" on stdout, one line per finding. A value is never printed in full:
# at most its first 2 characters plus its length. Notices and errors go to stderr.
#
# Content-complete reading: every mode reads content as text. Git attributes and textconv drivers are ignored,
# and NUL bytes are read twice (once as a space, once removed) so UTF-16 and NUL-separated data are scanned.
# Each finding is printed once.
#
# Local denylist (D-06): KOKPIT_DENYLIST names a file OUTSIDE the repository, one fixed term per line
# ("#" comments and blank lines ignored). Rows containing a term (case-insensitive, Czech diacritics folded)
# are reported as "path:line: denylist"; the term and the line text are never printed. Unset or missing
# file: one notice on stderr and generic rules only (the CI path). Unreadable or in-repository: exit 2.
# Exit codes: 0 clean, 1 findings, 2 usage error, not a git repository, or scanner error (fail closed).
#
# Read-only: never writes to the git index or the working tree. Portable to bash 3.2 (macOS) and Linux.
set -eu

usage_text='usage: check-sensitive.sh [--staged | --all | --history] [--] [FILE...]'
usage() {
  printf '%s\n' "$usage_text" >&2
  exit 2
}

mode=''
nfiles=0
files=()
endopts=0
for arg in "$@"; do
  if [ "$endopts" -eq 0 ]; then
    case "$arg" in
      --) endopts=1; continue ;;
      --help) printf '%s\n' "$usage_text"; exit 0 ;;
      --staged | --all | --history)
        [ -z "$mode" ] || usage
        mode=${arg#--}
        continue
        ;;
      -*) usage ;;
    esac
  fi
  files[nfiles]=$arg
  nfiles=$((nfiles + 1))
done
if [ "$nfiles" -gt 0 ]; then
  [ -z "$mode" ] || usage
  mode=files
fi
[ -n "$mode" ] || mode=staged

here=$(cd "$(dirname "$0")" && pwd)
if ! top=$(git rev-parse --show-toplevel 2>/dev/null); then
  echo 'check-sensitive: not a git repository' >&2
  exit 2
fi
# Explicit operands are relative to the caller's directory: capture where it sits inside the repository.
prefix=$(git rev-parse --show-prefix)
orig_pwd=$PWD
top_phys=$(cd "$top" && pwd -P)
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

# lexically normalise a repository-relative path: drop "." and empty segments, resolve "..".
normpath() {
  printf '%s\n' "$1" | LC_ALL=C awk -F/ '{
    n = 0
    for (i = 1; i <= NF; i++) {
      s = $i
      if (s == "" || s == ".") continue
      if (s == ".." && n > 0 && out[n] != "..") { n--; continue }
      out[++n] = s
    }
    r = ""
    for (i = 1; i <= n; i++) r = r (i > 1 ? "/" : "") out[i]
    print r
  }'
}

# diff_to_rows: parse the raw git output in $tmp/diff.raw into rows ("path<TAB>line<TAB>text") in $tmp/rows.tsv.
# NUL bytes are read twice when present: once as a space (strings that a NUL separates in binary data keep
# their boundaries, so left-boundary rules still work) and once removed (UTF-16 text exposes its ASCII
# characters). Every generic rule shape is ASCII, so the two readings together make every shape visible.
# NUL bytes never occur in diff headers (a path cannot contain NUL), so neither reading confuses the parser.
# LC_ALL=C on every tr: BSD tr aborts with "Illegal byte sequence" on non-UTF-8 bytes otherwise.
diff_to_rows() {
  if ! LC_ALL=C tr '\000' ' ' < "$tmp/diff.raw" > "$tmp/diff.txt"; then
    echo 'check-sensitive: NUL filter failed' >&2
    exit 2
  fi
  if ! LC_ALL=C awk -f "$here/lib/diff2tsv.awk" "$tmp/diff.txt" > "$tmp/rows.tsv"; then
    echo 'check-sensitive: diff parser error' >&2
    exit 2
  fi
  nul=$(LC_ALL=C tr -cd '\000' < "$tmp/diff.raw" | LC_ALL=C wc -c) || nul=''
  case "$nul" in
    '' | *[!0-9' ']*)
      echo 'check-sensitive: NUL filter failed' >&2
      exit 2
      ;;
  esac
  if [ "$((nul + 0))" -gt 0 ]; then
    if ! LC_ALL=C tr -d '\000' < "$tmp/diff.raw" > "$tmp/diff.txt"; then
      echo 'check-sensitive: NUL filter failed' >&2
      exit 2
    fi
    if ! LC_ALL=C awk -f "$here/lib/diff2tsv.awk" "$tmp/diff.txt" >> "$tmp/rows.tsv"; then
      echo 'check-sensitive: diff parser error' >&2
      exit 2
    fi
  fi
}

# Producer: every row is "path<TAB>line<TAB>text". Every mode reads content as text: git attributes (-diff,
# binary), textconv drivers and NUL-based binary detection must not hide a byte from the rules.
case "$mode" in
  staged | history)
    if [ "$mode" = staged ]; then
      # --text ignores the diff attribute and NUL-based binary detection; --no-textconv ignores textconv
      # drivers from any config; --no-renames shows a renamed file in full, so content moved out of a D-05
      # exempt path is scanned; --diff-filter=d excludes only deletions (the old ACMR filter dropped type
      # changes, for example a symlink replaced by a regular file).
      if ! git -c core.quotepath=off diff --cached --text --no-textconv --no-renames -U0 --no-color --no-ext-diff \
          --src-prefix=a/ --dst-prefix=b/ --diff-filter=d > "$tmp/diff.raw"; then
        echo 'check-sensitive: git diff failed' >&2
        exit 2
      fi
    else
      # One "commit <hash>" line per commit; diff2tsv.awk turns it into the @<sha7> suffix. Merge commits
      # are not diffed by `git log -p`; their content is covered by the parents (assumption A-HYG-05).
      if ! git -c core.quotepath=off log --all -p --text --no-textconv --no-renames -U0 --no-color --no-ext-diff \
          --src-prefix=a/ --dst-prefix=b/ --diff-filter=d --format='commit %H' > "$tmp/diff.raw"; then
        echo 'check-sensitive: git log failed' >&2
        exit 2
      fi
    fi
    diff_to_rows
    ;;
  files)
    scan_files=()
    nscan=0
    i=0
    while [ "$i" -lt "$nfiles" ]; do
      f=${files[$i]}
      i=$((i + 1))
      case "$f" in
        /*)
          # Absolute: made repository-relative when its physical directory is inside the repository.
          abs_dir=$(cd "$(dirname "$f")" 2> /dev/null && pwd -P) || abs_dir=''
          rel=''
          case "$abs_dir/" in
            "$top_phys"/*) rel=$(normpath "${abs_dir#"$top_phys"}/$(basename "$f")") ;;
          esac
          if [ -n "$rel" ]; then op="./$rel"; else op=$f; fi
          ;;
        *)
          rel=$(normpath "$prefix$f")
          op="./$rel"
          ;;
      esac
      if [ ! -f "$op" ] || [ ! -r "$op" ]; then
        echo "check-sensitive: not a readable file: $f" >&2
        exit 2
      fi
      [ -s "$op" ] || continue
      if ! grep -Iq . "$op"; then
        echo "check-sensitive: skipping binary or blank file: $f" >&2
        continue
      fi
      scan_files[nscan]=$op
      nscan=$((nscan + 1))
    done
    # "./"-prefixed operands cannot be read as options or as awk var=value assignments (T-01-17).
    if [ "$nscan" -gt 0 ]; then
      LC_ALL=C awk 'FNR == 1 { f = FILENAME; sub(/^\.\//, "", f) } { print f "\t" FNR "\t" $0 }' \
        "${scan_files[@]}" > "$tmp/rows.tsv"
    else
      : > "$tmp/rows.tsv"
    fi
    ;;
  all)
    rc=0
    git -c core.quotepath=off grep --cached -I -n -z -e '' > "$tmp/grep.out" || rc=$?
    # git grep: 0 = matches, 1 = no lines at all (empty tree); anything else is an error.
    if [ "$rc" -gt 1 ]; then
      echo 'check-sensitive: git grep failed' >&2
      exit 2
    fi
    LC_ALL=C tr '\0' '\t' < "$tmp/grep.out" > "$tmp/rows.tsv"
    ;;
esac

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
CS_ALLOWLIST="$here/sensitive-allowlist.txt" LC_ALL=C awk -f "$here/lib/scan.awk" "$tmp/scan.tsv" > "$tmp/findings.txt" || rc=$?
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
    ' "$tmp/deny.ln" "$tmp/scan.tsv" >> "$tmp/findings.txt" || deny_found=1
  fi
fi

# Each finding is printed once, in first-seen order (the two NUL readings can report the same line twice).
if ! LC_ALL=C awk '!seen[$0]++' "$tmp/findings.txt"; then
  echo 'check-sensitive: scanner error' >&2
  exit 2
fi
if [ "$rc" -eq 1 ] || [ "$deny_found" -eq 1 ]; then
  echo 'check-sensitive: findings above; see CONTRIBUTING.md' >&2
  exit 1
fi
echo "check-sensitive: clean ($mode)" >&2
exit 0
