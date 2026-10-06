#!/usr/bin/env bash
# test-denylist.sh - every KOKPIT_DENYLIST state of scripts/check-sensitive.sh (D-06, HYG-03).
# Terms are fictional (an invented company with Czech diacritics, an invented upper-case code). The denylist
# files live in TEST_TMP, outside every temp repository, and are never committed anywhere.
HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=/dev/null
. "$HERE/lib.sh"
SCRIPT="$REPO_ROOT/scripts/check-sensitive.sh"

term_a='Fiktivní Klient'
term_b='ACME-FAKE-CORP'
stripe=$(fake_stripe_key)

stage() {  # stage <repo> <path> <content>: write a file (creating directories) and git add it
  mkdir -p "$(dirname "$1/$2")"
  printf '%s' "$3" > "$1/$2"
  git -C "$1" add -- "$2"
}

# mkdeny <name> <content...>: write a denylist file outside any repository and print its path
mkdeny() {
  local f="$TEST_TMP/$1"
  shift
  printf '%s' "$*" > "$f"
  printf '%s\n' "$f"
}

# run_with <denylist-path> <repo> [args...]: run the script in <repo> with KOKPIT_DENYLIST set
run_with() {
  local dl=$1 repo=$2
  shift 2
  ( cd "$repo" && KOKPIT_DENYLIST="$dl" "$SCRIPT" "$@" )
}

# run_unset <repo> [args...]
run_unset() {
  local repo=$1
  shift
  ( cd "$repo" && env -u KOKPIT_DENYLIST "$SCRIPT" "$@" )
}

line_text="contract with $(printf '%s' "$term_b" | tr '[:upper:]' '[:lower:]') signed in March"

# (a) unset: clean change passes, one notice
repo=$(new_repo)
stage "$repo" a.txt "$(printf 'hello\nworld\n')"
assert_exit 0 "(a) denylist unset: clean change exits 0" run_unset "$repo"
assert_out_has "KOKPIT_DENYLIST not set" "(a) notice names the unset variable"
assert_eq "(a) exactly one notice line" 1 "$(grep -c 'KOKPIT_DENYLIST not set' "$TEST_TMP/out")"

# (b) a term in a different case blocks; neither the term nor the line text is printed
dl=$(mkdeny deny-b.txt "$(printf '# fictional terms\n%s\n%s\n' "$term_a" "$term_b")")
repo=$(new_repo)
stage "$repo" notes.txt "$(printf 'one\ntwo\n%s\nfour\n' "$line_text")"
assert_exit 1 "(b) denylisted term (other case) exits 1" run_with "$dl" "$repo"
assert_out_has "notes.txt:3: denylist" "(b) finding names file, line and rule"
assert_out_lacks "$term_b" "(b) term is not printed"
assert_out_lacks "acme-fake-corp" "(b) lower-case term is not printed"
assert_out_lacks "signed in March" "(b) line text is not printed"
assert_out_lacks "$line_text" "(b) whole line is not printed"

# (c) Czech diacritics fold: lower-case term matches upper-case content including the accented letter
if printf 'Í\n' | LC_ALL=C.UTF-8 grep -qi 'í' 2> /dev/null; then
  dl=$(mkdeny deny-c.txt "$(printf '%s\n' "$(printf '%s' "$term_a" | tr '[:upper:]' '[:lower:]')")")
  repo=$(new_repo)
  stage "$repo" c.txt "$(printf 'client: FIKTIVNÍ KLIENT\n')"
  assert_exit 1 "(c) diacritic-folded match exits 1" run_with "$dl" "$repo"
  assert_out_has "c.txt:1: denylist" "(c) folded match is reported"
  assert_out_lacks "FIKTIVNÍ" "(c) content is not printed"
else
  printf 'SKIP: (c) no C.UTF-8 locale with case folding on this system\n'
fi

# (d) blank, whitespace-only, comment and CRLF lines never match everything
dl="$TEST_TMP/deny-d.txt"
printf '\r\n   \r\n\t\r\n# comment only\r\n\r\n' > "$dl"
repo=$(new_repo)
stage "$repo" d.txt "$(printf 'plain text\nmore plain text\n')"
assert_exit 0 "(d) denylist without real terms does not match every line" run_with "$dl" "$repo"
assert_out_has "KOKPIT_DENYLIST has no terms" "(d) notice says the list is empty"
dl="$TEST_TMP/deny-d2.txt"
printf '\r\n   \r\n# comment\r\n%s\r\n\r\n' "$term_b" > "$dl"
assert_exit 0 "(d) CRLF list with a term still passes a clean change" run_with "$dl" "$repo"
repo=$(new_repo)
stage "$repo" d2.txt "$(printf 'x\n%s\n' "$term_b")"
assert_exit 1 "(d) CRLF list: the term (no stray CR) still matches" run_with "$dl" "$repo"
assert_out_has "d2.txt:2: denylist" "(d) CRLF term reported at its line"

# (e) a path that does not exist: notice, generic rules only
repo=$(new_repo)
stage "$repo" e.txt "$(printf 'hello\n')"
assert_exit 0 "(e) missing denylist file: clean change exits 0" run_with "$TEST_TMP/does-not-exist.txt" "$repo"
assert_out_has "KOKPIT_DENYLIST file not found" "(e) notice says the file was not found"

# (f) an unreadable denylist is an error, not a silent skip
if [ "$(id -u)" -eq 0 ]; then
  printf 'SKIP: (f) running as root, unreadable files are readable\n'
else
  dl=$(mkdeny deny-f.txt "$term_b")
  chmod 000 "$dl"
  repo=$(new_repo)
  stage "$repo" f.txt "$(printf 'hello\n')"
  assert_exit 2 "(f) unreadable denylist exits 2" run_with "$dl" "$repo"
  assert_out_has "not readable" "(f) error names the problem"
  chmod 600 "$dl"
fi

# (g) a denylist inside the repository is refused, also in a sub-directory and via a relative path
repo=$(new_repo)
printf '%s\n' "$term_b" > "$repo/deny.txt"
mkdir -p "$repo/sub"
printf '%s\n' "$term_b" > "$repo/sub/deny.txt"
stage "$repo" g.txt "$(printf 'hello\n')"
assert_exit 2 "(g) denylist in the repository root exits 2" run_with "$repo/deny.txt" "$repo"
assert_out_has "outside the repository" "(g) error names the problem"
assert_exit 2 "(g) denylist in a repository sub-directory exits 2" run_with "$repo/sub/deny.txt" "$repo"
assert_exit 2 "(g) relative path into the repository exits 2" run_with "sub/deny.txt" "$repo"
mkdir -p "${repo}-lists"
printf '%s\n' "$term_b" > "${repo}-lists/deny.txt"
assert_exit 0 "(g) sibling directory sharing the repository name prefix is allowed" run_with "${repo}-lists/deny.txt" "$repo"

# (h) a term only in a staged file's path blocks (the path is printed by design: it is the finding)
dl=$(mkdeny deny-h.txt "$term_b")
repo=$(new_repo)
stage "$repo" "docs/acme-fake-corp-notes.txt" "$(printf 'harmless content\n')"
assert_exit 1 "(h) term only in the path exits 1" run_with "$dl" "$repo"
assert_out_has "docs/acme-fake-corp-notes.txt:1: denylist" "(h) path finding reported"
assert_out_lacks "harmless content" "(h) line text is not printed"

# (i) generic rules stay active while a denylist is set
dl=$(mkdeny deny-i.txt "$term_b")
repo=$(new_repo)
stage "$repo" i.txt "$(printf 'x\n%s\n' "$stripe")"
assert_exit 1 "(i) fake key with a denylist set still exits 1" run_with "$dl" "$repo"
assert_out_has "i.txt:2: key-prefix" "(i) generic rule still reports"
assert_out_lacks "$stripe" "(i) full key is not printed"

# (j) a numeric term matches content, never line numbers
dl=$(mkdeny deny-j.txt "7")
repo=$(new_repo)
stage "$repo" j.txt "$(printf 'a\nb\nc\nd\ne\nf\ng\nh\n')"
assert_exit 0 "(j) numeric term does not match line numbers" run_with "$dl" "$repo"

# (k) --all honours the denylist too
dl=$(mkdeny deny-k.txt "$term_b")
repo=$(new_repo)
stage "$repo" k.txt "$(printf 'x\nsee %s here\n' "$term_b")"
assert_exit 1 "(k) --all with a denylist exits 1" run_with "$dl" "$repo" --all
assert_out_has "k.txt:2: denylist" "(k) --all finding reported"
assert_out_lacks "see $term_b" "(k) --all does not print the line"

finish
