#!/usr/bin/env bash
# test-check-sensitive.sh - staged and --all cases for scripts/check-sensitive.sh.
# Runs the real script against temp repositories; all fake values are built at runtime (see lib.sh).
HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=/dev/null
. "$HERE/lib.sh"
SCRIPT="$REPO_ROOT/scripts/check-sensitive.sh"

stage() {  # stage <repo> <path> <content>: write a file (creating directories) and git add it
  mkdir -p "$(dirname "$1/$2")"
  printf '%s' "$3" > "$1/$2"
  git -C "$1" add -- "$2"
}

stripe=$(fake_stripe_key)
ghp=$(fake_ghp)
aws=$(fake_aws)
body='aB3dE5fG7hJ9kL1mN3pQ5rS7'

# (a) a clean staged file passes
repo=$(new_repo)
stage "$repo" a.txt "$(printf 'hello\nworld\n')"
assert_exit 0 "(a) clean staged file exits 0" in_dir "$repo" "$SCRIPT"

# (b) a fake Stripe key on line 3 of 4 is reported with file and line, masked
repo=$(new_repo)
stage "$repo" b.txt "$(printf 'one\ntwo\n%s\nfour\n' "$stripe")"
assert_exit 1 "(b) staged fake Stripe key exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "b.txt:3: key-prefix" "(b) finding names file, line and rule"
assert_out_has "sk*** (" "(b) value is masked to 2 characters plus length"
assert_out_lacks "$stripe" "(b) full key is not printed"
assert_out_lacks "$body" "(b) key body is not printed"

# (c) the same for GitHub and AWS style keys
repo=$(new_repo)
stage "$repo" c1.txt "$(printf 'x\n%s\n' "$ghp")"
stage "$repo" c2.txt "$(printf 'x\ny\n%s\n' "$aws")"
assert_exit 1 "(c) staged fake GitHub and AWS keys exit 1" in_dir "$repo" "$SCRIPT"
assert_out_has "c1.txt:2: key-prefix" "(c) GitHub key reported at its line"
assert_out_has "c2.txt:3: key-prefix" "(c) AWS key reported at its line"
assert_out_lacks "$ghp" "(c) full GitHub key is not printed"
assert_out_lacks "$aws" "(c) full AWS key is not printed"

# (d) a file name containing a space is reported with its full name
repo=$(new_repo)
stage "$repo" "my notes.txt" "$(printf '%s\n' "$stripe")"
assert_exit 1 "(d) fake key in a file with a space in its name exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "my notes.txt:1: key-prefix" "(d) full file name is reported"

# (e) an added line starting with '++' must not be read as a diff header (decoy path, wrong line number)
repo=$(new_repo)
stage "$repo" e.txt "$(printf 'one\n++ b/decoy.txt\n%s\n' "$ghp")"
assert_exit 1 "(e) fake key after a '++ b/...' line exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "e.txt:3: key-prefix" "(e) correct file and line number"
assert_out_lacks "decoy.txt" "(e) the decoy header did not redirect the finding"

# (f) prose that only names the bare prefixes (no body) is not a finding
repo=$(new_repo)
stage "$repo" f.md 'Prefixes to avoid: sk_live_ pk_live_ whsec_ plink_ acct_ ghp_ AKIA'
assert_exit 0 "(f) bare prefixes without a body exit 0" in_dir "$repo" "$SCRIPT"

# (g) --all scans the index: a committed fake is found, a clean repo passes
repo=$(new_repo)
stage "$repo" g.txt "$(printf 'one\n%s\n' "$stripe")"
git -C "$repo" commit -q -m seed
assert_exit 1 "(g) --all exits 1 on a committed fake" in_dir "$repo" "$SCRIPT" --all
assert_out_has "g.txt:2: key-prefix" "(g) --all reports path:line: rule"
assert_out_lacks "$stripe" "(g) --all never prints the full key"
repo=$(new_repo)
stage "$repo" g.txt "$(printf 'one\ntwo\n')"
git -C "$repo" commit -q -m seed
assert_exit 0 "(g) --all exits 0 on a clean repository" in_dir "$repo" "$SCRIPT" --all

# (h) D-05: exclusion by exact path only
repo=$(new_repo)
stage "$repo" scripts/sensitive-allowlist.txt "$(printf '%s\n' "$ghp")"
assert_exit 0 "(h) fake in an excluded exact path exits 0" in_dir "$repo" "$SCRIPT"
stage "$repo" scripts/other.txt "$(printf '%s\n' "$ghp")"
assert_exit 1 "(h) the same fake in another file under scripts/ exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "scripts/other.txt:1: key-prefix" "(h) the other file is reported"
assert_out_lacks "sensitive-allowlist.txt" "(h) the excluded path is not reported"
git -C "$repo" commit -q -m seed
assert_exit 1 "(h) --all also reports only the non-excluded file" in_dir "$repo" "$SCRIPT" --all
assert_out_has "scripts/other.txt:1: key-prefix" "(h) --all reports scripts/other.txt"
assert_out_lacks "sensitive-allowlist.txt" "(h) --all does not report the excluded path"

# (i) usage and environment errors exit 2
repo=$(new_repo)
assert_exit 2 "(i) unknown flag exits 2" in_dir "$repo" "$SCRIPT" --bogus
assert_exit 2 "(i) two arguments exit 2" in_dir "$repo" "$SCRIPT" --staged --all
nogit=$(mktemp -d "$TEST_TMP/nogit.XXXXXX")
assert_exit 2 "(i) outside a git repository exits 2" in_dir "$nogit" env GIT_CEILING_DIRECTORIES="$TEST_TMP" "$SCRIPT"
assert_out_has "not a git repository" "(i) outside a repo prints the reason"

# (j) the script's mktemp directory is removed on every exit path
repo=$(new_repo)
stage "$repo" j.txt "$(printf '%s\n' "$stripe")"
tdir=$(mktemp -d "$TEST_TMP/tmpdir.XXXXXX")
assert_exit 1 "(j) findings run with a private TMPDIR exits 1" in_dir "$repo" env TMPDIR="$tdir" "$SCRIPT"
assert_eq "(j) TMPDIR is empty after a findings run" 0 "$(count_entries "$tdir")"
git -C "$repo" reset -q
assert_exit 0 "(j) clean run with a private TMPDIR exits 0" in_dir "$repo" env TMPDIR="$tdir" "$SCRIPT"
assert_eq "(j) TMPDIR is empty after a clean run" 0 "$(count_entries "$tdir")"

# (k) read-only: neither the index nor the working tree changes
repo=$(new_repo)
stage "$repo" k.txt "$(printf 'one\n%s\n' "$stripe")"
index_before=$(cksum < "$repo/.git/index")
tree_before=$(git --no-optional-locks -C "$repo" status --porcelain)
assert_exit 1 "(k) findings run exits 1" in_dir "$repo" "$SCRIPT"
assert_eq "(k) git index is byte-identical after the run" "$index_before" "$(cksum < "$repo/.git/index")"
assert_eq "(k) git status is unchanged after the run" "$tree_before" "$(git --no-optional-locks -C "$repo" status --porcelain)"

# (l) a crashing scanner fails closed (exit 2), it is never reported as clean
broken="$TEST_TMP/broken"
mkdir -p "$broken/scripts"
cp -R "$REPO_ROOT/scripts/check-sensitive.sh" "$REPO_ROOT/scripts/lib" "$broken/scripts/"
printf 'BEGIN { exit 3 }\n' > "$broken/scripts/lib/scan.awk"
repo=$(new_repo)
stage "$repo" l.txt "$(printf '%s\n' "$stripe")"
assert_exit 2 "(l) scanner exiting with an error exits 2" in_dir "$repo" "$broken/scripts/check-sensitive.sh"
assert_out_has "scanner error" "(l) the failure is reported as a scanner error"

# Helpers for the per-rule cases: every case stages one file in a fresh temp repository.
# case_hit  <description> <rule> <content>   expects exit 1 and "n.txt:1: <rule>" in the output
# case_pass <description> <content>          expects exit 0
case_hit() {
  local r
  r=$(new_repo)
  stage "$r" n.txt "$3"
  assert_exit 1 "$1" in_dir "$r" "$SCRIPT"
  assert_out_has "n.txt:1: $2" "$1 (reported as $2 with file and line)"
}
case_pass() {
  local r
  r=$(new_repo)
  stage "$r" n.txt "$2"
  assert_exit 0 "$1" in_dir "$r" "$SCRIPT"
}

AT='@'

# (m) email rule: a non-example address is reported with file and line, masked
mail=$(fake_email)
repo=$(new_repo)
stage "$repo" m.txt "$(printf 'contact: %s\n' "$mail")"
assert_exit 1 "(m) non-example e-mail exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "m.txt:1: email" "(m) finding names file, line and rule"
assert_out_lacks "$mail" "(m) the full address is not printed"
assert_out_lacks "jane" "(m) the local part is not printed"
assert_out_lacks "corp-fake" "(m) the domain is not printed"

# (n) addresses at example domains and reserved TLDs pass
case_pass "(n) address at example.com passes" "$(printf 'a%sexample.com\n' "$AT")"
case_pass "(n) address at a subdomain of example.org passes" "$(printf 'a%sdocs.example.org\n' "$AT")"
case_pass "(n) address at example.net passes" "$(printf 'a%sexample.net\n' "$AT")"
case_pass "(n) address at a .test host passes" "$(printf 'a%smail.fake.test\n' "$AT")"
case_pass "(n) address at a .invalid host passes" "$(printf 'a%smail.fake.invalid\n' "$AT")"
case_pass "(n) address at localhost passes" "$(printf 'a%slocalhost\n' "$AT")"
case_pass "(n) address at a .localhost host passes" "$(printf 'a%sapp.localhost\n' "$AT")"

# (o) allowlist: the SSH remote notation passes, another user at the same domain does not
case_pass "(o) the git-at-github SSH notation passes (reviewed entry)" "$(printf 'git%sgithub.com:owner/repo.git\n' "$AT")"
case_hit "(o) another user at github.com is reported" email "$(printf 'bob%sgithub.com\n' "$AT")"

finish
