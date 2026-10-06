#!/usr/bin/env bash
# run.sh - run every scripts/tests/test-*.sh and print a summary.
#
# Usage: scripts/tests/run.sh [--quick]
#   --quick   export KOKPIT_TEST_QUICK=1: tests that need lefthook or gitleaks skip themselves.
# Each test file runs with the interpreter running this script, so `/bin/bash scripts/tests/run.sh` tests
# under bash 3.2. A test exiting 77 counts as SKIP. The real repository's `git status` is captured before
# and after; any difference fails the run (tests must only touch temp repositories).
# Exit 0 = every file passed or skipped; exit 1 = a failure, a changed real repo, or no test ran.
set -u

here=$(cd "$(dirname "$0")" && pwd)
repo_root=$(cd "$here/../.." && pwd)

for arg in "$@"; do
  case "$arg" in
    --quick) export KOKPIT_TEST_QUICK=1 ;;
    *) printf 'usage: run.sh [--quick]\n' >&2; exit 2 ;;
  esac
done

# A caller's git environment (for example when run from inside a hook) must not leak into the tests.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_PREFIX

status_of_real_repo() { git --no-optional-locks -C "$repo_root" status --porcelain 2>/dev/null || true; }

before=$(status_of_real_repo)

n_pass=0
n_fail=0
n_skip=0
n_files=0

for f in "$here"/test-*.sh; do
  [ -f "$f" ] || continue
  n_files=$((n_files + 1))
  rel=${f#"$repo_root"/}
  printf '== %s\n' "$rel"
  rc=0
  "$BASH" "$f" || rc=$?
  case "$rc" in
    0) n_pass=$((n_pass + 1)) ;;
    77) n_skip=$((n_skip + 1)); printf 'SKIP: %s\n' "$rel" ;;
    *) n_fail=$((n_fail + 1)); printf 'FAILED: %s (exit %d)\n' "$rel" "$rc" ;;
  esac
done

after=$(status_of_real_repo)
if [ "$before" != "$after" ]; then
  n_fail=$((n_fail + 1))
  printf 'FAILED: git status of the real repository changed during the tests\n'
fi

printf 'PASS %d FAIL %d SKIP %d\n' "$n_pass" "$n_fail" "$n_skip"
if [ "$n_files" -eq 0 ]; then
  printf 'FAILED: no test file found\n'
  exit 1
fi
[ "$n_fail" -eq 0 ]
