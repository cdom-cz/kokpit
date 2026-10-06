#!/usr/bin/env bash
# test-lefthook.sh - real commits through the lefthook pre-commit hook (local only, never run in CI).
# Skips when CI=true, in quick mode, or when lefthook / gitleaks are not on PATH.
HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=/dev/null
. "$HERE/lib.sh"

[ "${CI:-}" = "true" ] && skip "CI=true (the hook is a local developer tool; CI runs the scanner directly)"
[ "${KOKPIT_TEST_QUICK:-}" = "1" ] && skip "quick mode (KOKPIT_TEST_QUICK=1)"
command -v lefthook > /dev/null 2>&1 || skip "lefthook is not on PATH"
command -v gitleaks > /dev/null 2>&1 || skip "gitleaks is not on PATH"

GIT=$(command -v git)
stripe=$(fake_stripe_key)
body='aB3dE5fG7hJ9kL1mN3pQ5rS7'

# hook_repo: a temp repository holding copies of the hook configuration and scanner, committed before
# the hook is installed. Optional files (allowlist, gitleaks config) are copied only if they exist, so this
# test stays valid as later plans add them.
hook_repo() {
  local r f
  r=$(new_repo)
  mkdir -p "$r/scripts"
  cp "$REPO_ROOT/lefthook.yml" "$r/"
  cp "$REPO_ROOT/scripts/check-sensitive.sh" "$r/scripts/"
  cp -R "$REPO_ROOT/scripts/lib" "$r/scripts/"
  for f in scripts/install-hooks.sh scripts/sensitive-allowlist.txt .gitleaks.toml; do
    if [ -f "$REPO_ROOT/$f" ]; then cp "$REPO_ROOT/$f" "$r/$f"; fi
  done
  git -C "$r" add -A
  git -C "$r" commit -q -m init
  printf '%s\n' "$r"
}

stage_file() {  # stage_file <repo> <path> <content>
  mkdir -p "$(dirname "$1/$2")"
  printf '%s\n' "$3" > "$1/$2"
  git -C "$1" add -- "$2"
}

unstage_all() {  # unstage_all <repo>: reset the index and remove untracked files created by the test
  git -C "$1" reset -q
  rm -rf "${1:?}/secret.txt" "${1:?}/clean.txt" "${1:?}/sub"
}

repo=$(hook_repo)

# 1. installer: installs, is idempotent, leaves core.hooksPath unset
assert_exit 0 "install-hooks.sh installs the hook" in_dir "$repo" scripts/install-hooks.sh
assert_exit 0 "the pre-commit hook file exists and is managed by lefthook" grep -q lefthook "$repo/.git/hooks/pre-commit"
sum1=$(cksum "$repo/.git/hooks/pre-commit" 2> /dev/null || true)
assert_exit 0 "install-hooks.sh is idempotent (second run)" in_dir "$repo" scripts/install-hooks.sh
sum2=$(cksum "$repo/.git/hooks/pre-commit" 2> /dev/null || true)
assert_eq "hook file is unchanged by the second install" "$sum1" "$sum2"
assert_eq "core.hooksPath stays unset" "" "$(git -C "$repo" config --get core.hooksPath || true)"

# 2. a staged fake key is rejected, masked, and HEAD does not move
head_before=$(git -C "$repo" rev-parse HEAD)
stage_file "$repo" secret.txt "$(printf 'x\n%s' "$stripe")"
assert_exit 1 "commit with a staged fake key is rejected" in_dir "$repo" git commit -q -m secret
assert_out_has "secret.txt:2: key-prefix" "rejected commit names file, line and rule"
assert_out_lacks "$stripe" "rejected commit output has no full key"
assert_out_lacks "$body" "rejected commit output has no key body"
assert_eq "HEAD did not move after the rejected commit" "$head_before" "$(git -C "$repo" rev-parse HEAD)"
unstage_all "$repo"

# 3. a clean commit succeeds
stage_file "$repo" clean.txt "clean content"
assert_exit 0 "clean commit succeeds" in_dir "$repo" git commit -q -m clean
assert_eq "HEAD moved after the clean commit" "1" "$([ "$(git -C "$repo" rev-parse HEAD)" != "$head_before" ] && echo 1 || echo 0)"

# 4. commit from a sub-directory: rejected, finding path is repo-relative
mkdir -p "$repo/sub"
stage_file "$repo" sub/s.txt "$stripe"
assert_exit 1 "commit from a sub-directory with a staged fake is rejected" in_dir "$repo/sub" git commit -q -m sub
assert_out_has "sub/s.txt:1: key-prefix" "finding path is repo-relative"
unstage_all "$repo"

# 5. fail closed: when lefthook cannot be found at all, a clean commit is rejected.
# The generated hook bakes in the absolute path of the lefthook binary that installed it and falls back to it
# when PATH has no lefthook. To simulate a machine without lefthook, install with a private copy of the
# binary, delete the copy, and commit with a reduced PATH.
lhbin="$TEST_TMP/lhbin"
mkdir -p "$lhbin"
cp "$(command -v lefthook)" "$lhbin/lefthook" 2> /dev/null || true
if env PATH=/usr/bin:/bin sh -c 'command -v lefthook' > /dev/null 2>&1; then
  printf 'notice: lefthook is on the reduced PATH, skipping the missing-lefthook case\n'
elif ! "$lhbin/lefthook" version > /dev/null 2>&1; then
  printf 'notice: lefthook binary cannot be copied (shim?), skipping the missing-lefthook case\n'
else
  repo3=$(hook_repo)
  assert_exit 0 "installer run with a private lefthook copy" in_dir "$repo3" env PATH="$lhbin:$PATH" scripts/install-hooks.sh
  rm -rf "$lhbin"
  head_before=$(git -C "$repo3" rev-parse HEAD)
  stage_file "$repo3" clean.txt "clean content without lefthook"
  assert_exit 1 "clean commit is rejected when lefthook cannot be found" in_dir "$repo3" env PATH=/usr/bin:/bin "$GIT" commit -q -m nolefthook
  assert_out_has "aborted due to lefthook settings" "the rejection comes from assert_lefthook_installed"
  assert_eq "HEAD did not move without lefthook" "$head_before" "$(git -C "$repo3" rev-parse HEAD)"
fi

# 6. a configured core.hooksPath: the installer refuses and prints the reset hint
repo2=$(hook_repo)
git -C "$repo2" config core.hooksPath .alt-hooks
assert_exit 1 "install-hooks.sh refuses when core.hooksPath is set" in_dir "$repo2" scripts/install-hooks.sh
assert_out_has "--reset-hooks-path" "the refusal prints lefthook's reset hint"
assert_eq "no hook was installed over the hooks path override" "0" "$([ -f "$repo2/.git/hooks/pre-commit" ] && echo 1 || echo 0)"

finish
