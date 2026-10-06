#!/usr/bin/env bash
# test-modes.sh - explicit file lists, mode conflicts and --history for scripts/check-sensitive.sh.
# Fake values are built at runtime (see lib.sh); only the key-prefix rule is used so these cases do not
# depend on any other rule family.
HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=/dev/null
. "$HERE/lib.sh"
SCRIPT="$REPO_ROOT/scripts/check-sensitive.sh"

stripe=$(fake_stripe_key)
ghp=$(fake_ghp)

put() {  # put <repo> <path> <content>: write a file (creating directories), no git add
  mkdir -p "$(dirname "$1/$2")"
  printf '%s' "$3" > "$1/$2"
}

commit_file() {  # commit_file <repo> <path> <content> <message>: write, stage and commit
  put "$1" "$2" "$3"
  git -C "$1" add -- "$2"
  git -C "$1" commit -q -m "$4"
}

short() { git -C "$1" rev-parse --short=7 "$2"; }

# --- explicit files -----------------------------------------------------------------------------------

# (a) a relative operand from a sub-directory is reported with its repository-relative path
repo=$(new_repo)
put "$repo" sub/f.txt "$(printf 'one\ntwo\n%s\n' "$stripe")"
assert_exit 1 "(a) explicit file with a fake key exits 1" in_dir "$repo/sub" "$SCRIPT" f.txt
assert_out_has "sub/f.txt:3: key-prefix" "(a) path is repository-relative, line is correct"
assert_out_lacks "$stripe" "(a) full key is not printed"

# (b) ../ operands are normalised; absolute operands inside the repository are made relative
put "$repo" top.txt "$(printf '%s\n' "$ghp")"
assert_exit 1 "(b) ../ operand from a sub-directory exits 1" in_dir "$repo/sub" "$SCRIPT" ../top.txt
assert_out_has "top.txt:1: key-prefix" "(b) reported as top.txt, not sub/../top.txt"
assert_exit 1 "(b) absolute operand inside the repository exits 1" in_dir "$repo/sub" "$SCRIPT" "$repo/top.txt"
assert_out_has "top.txt:1: key-prefix" "(b) absolute operand reported repository-relative"

# (c) a clean explicit file passes, with or without being tracked
put "$repo" clean.txt "$(printf 'hello\nworld\n')"
assert_exit 0 "(c) clean explicit file exits 0" in_dir "$repo" "$SCRIPT" clean.txt

# (d) bad operands are errors, not "clean"
assert_exit 2 "(d) nonexistent file exits 2" in_dir "$repo" "$SCRIPT" no-such-file.txt
assert_exit 2 "(d) directory operand exits 2" in_dir "$repo" "$SCRIPT" sub

# (e) a file whose name starts with a dash is scanned when passed after --
put "$repo" -dash.txt "$(printf 'x\n%s\n' "$stripe")"
assert_exit 1 "(e) -dash.txt after -- is scanned" in_dir "$repo" "$SCRIPT" -- -dash.txt
assert_out_has "-dash.txt:2: key-prefix" "(e) reported under its own name"
assert_exit 2 "(e) the same operand before -- is an unknown option" in_dir "$repo" "$SCRIPT" -dash.txt

# (f) several operands in one run, including a name with a space and one containing '='
put "$repo" "my notes.txt" "$(printf '%s\n' "$stripe")"
put "$repo" "a=b.txt" "$(printf 'x\n%s\n' "$ghp")"
assert_exit 1 "(f) several operands exit 1" in_dir "$repo" "$SCRIPT" clean.txt "my notes.txt" a=b.txt
assert_out_has "my notes.txt:1: key-prefix" "(f) name with a space reported"
assert_out_has "a=b.txt:2: key-prefix" "(f) name with '=' is a file, not an awk assignment"

# (g) empty and binary files contribute nothing
: > "$repo/empty.txt"
printf 'bin\000%s\n' "$stripe" > "$repo/blob.bin"
assert_exit 0 "(g) empty file exits 0" in_dir "$repo" "$SCRIPT" empty.txt
assert_exit 0 "(g) binary file is skipped, exit 0" in_dir "$repo" "$SCRIPT" blob.bin
assert_out_has "binary" "(g) skipped binary is announced on stderr"

# (h) D-05 exclusions apply to explicit files as well
put "$repo" scripts/lib/scan.awk "$(printf '%s\n' "$stripe")"
assert_exit 0 "(h) an excluded path is not scanned" in_dir "$repo" "$SCRIPT" scripts/lib/scan.awk

# (i) the denylist applies to explicit files
put "$repo" d.txt "$(printf 'nothing\nthe ACME-FAKE-CORP deal\n')"
printf 'acme-fake-corp\n' > "$TEST_TMP/deny.txt"
assert_exit 1 "(i) denylist term in an explicit file exits 1" in_dir "$repo" env KOKPIT_DENYLIST="$TEST_TMP/deny.txt" "$SCRIPT" d.txt
assert_out_has "d.txt:2: denylist" "(i) explicit-file denylist finding reported"
assert_out_lacks "ACME-FAKE-CORP" "(i) term is not printed"

# --- argument parser ----------------------------------------------------------------------------------

assert_exit 2 "(j) --all with --history exits 2" in_dir "$repo" "$SCRIPT" --all --history
assert_exit 2 "(j) --staged with --all exits 2" in_dir "$repo" "$SCRIPT" --staged --all
assert_exit 2 "(j) --all with a file operand exits 2" in_dir "$repo" "$SCRIPT" --all clean.txt
assert_exit 2 "(j) --history with a file operand exits 2" in_dir "$repo" "$SCRIPT" --history clean.txt
assert_exit 2 "(j) unknown option exits 2" in_dir "$repo" "$SCRIPT" --bogus
assert_exit 0 "(j) --help exits 0" in_dir "$repo" "$SCRIPT" --help
assert_out_has "--history" "(j) usage mentions --history"

# --- history ------------------------------------------------------------------------------------------

# (k) a key added in commit A and removed in commit B is found, naming A; --all sees only the clean tree
repo=$(new_repo)
commit_file "$repo" b.txt "$(printf 'one\ntwo\n%s\n' "$stripe")" "add"
sha_a=$(short "$repo" HEAD)
commit_file "$repo" b.txt "$(printf 'one\ntwo\nthree\n')" "remove"
assert_exit 1 "(k) --history finds a secret that only exists in an old commit" in_dir "$repo" "$SCRIPT" --history
assert_out_has "b.txt:3@${sha_a}: key-prefix" "(k) finding names file, line and the introducing commit"
assert_out_lacks "$stripe" "(k) full key is not printed"
assert_exit 0 "(k) --all on the same repository is clean" in_dir "$repo" "$SCRIPT" --all

# (l) a clean history exits 0
repo=$(new_repo)
commit_file "$repo" a.txt "$(printf 'hello\n')" "one"
commit_file "$repo" c.txt "$(printf 'world\n')" "two"
assert_exit 0 "(l) clean history exits 0" in_dir "$repo" "$SCRIPT" --history

# (m) a fake committed only inside a D-05 excluded path is not reported
repo=$(new_repo)
commit_file "$repo" scripts/lib/scan.awk "$(printf '%s\n' "$stripe")" "excluded"
assert_exit 0 "(m) excluded path in history exits 0" in_dir "$repo" "$SCRIPT" --history

# (n) a secret on another branch is found (--all refs), with the right commit
repo=$(new_repo)
commit_file "$repo" a.txt "$(printf 'hello\n')" "base"
git -C "$repo" checkout -q -b feature
commit_file "$repo" "my notes.txt" "$(printf 'x\n%s\n' "$ghp")" "feature"
sha_f=$(short "$repo" HEAD)
git -C "$repo" checkout -q main
assert_exit 1 "(n) secret on another branch exits 1" in_dir "$repo" "$SCRIPT" --history
assert_out_has "my notes.txt:2@${sha_f}: key-prefix" "(n) the introducing commit on the other branch is named"

# (o) the denylist applies to history and is also reported with the commit
repo=$(new_repo)
commit_file "$repo" a.txt "$(printf 'x\nthe ACME-FAKE-CORP deal\n')" "add term"
sha_d=$(short "$repo" HEAD)
commit_file "$repo" a.txt "$(printf 'x\n')" "drop term"
assert_exit 1 "(o) denylist term only in an old commit exits 1" in_dir "$repo" env KOKPIT_DENYLIST="$TEST_TMP/deny.txt" "$SCRIPT" --history
assert_out_has "a.txt:2@${sha_d}: denylist" "(o) denylist finding names the commit"
assert_out_lacks "ACME-FAKE-CORP" "(o) term is not printed"

# (p) a repository without commits is clean, not an error
repo=$(new_repo)
assert_exit 0 "(p) --history in a repository without commits exits 0" in_dir "$repo" "$SCRIPT" --history

# (q) staged mode output is unchanged by the commit-capture logic: no @sha suffix
repo=$(new_repo)
commit_file "$repo" a.txt "$(printf 'x\n')" "base"
put "$repo" q.txt "$(printf '%s\n' "$stripe")"
git -C "$repo" add q.txt
assert_exit 1 "(q) staged mode still exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "q.txt:1: key-prefix" "(q) staged finding has no commit suffix"

finish
