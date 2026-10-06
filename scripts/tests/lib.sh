# shellcheck shell=bash
# lib.sh - assertion helpers, temp repositories and fake-value builders for scripts/tests/test-*.sh.
# Source it from a test file. Plain Bash, no dependencies, bash 3.2 safe.
#
# Every git operation of a test runs inside a temp repository under TEST_TMP; the real repository is never
# touched (run.sh verifies this). Every fake value is assembled from fragments at runtime, so no single
# line of any test file matches a scanner rule (and nothing real-looking is ever committed).

REPO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
export REPO_ROOT
TEST_TMP=$(mktemp -d "${TMPDIR:-/tmp}/kokpit-test.XXXXXX")
trap 'rm -rf "$TEST_TMP"' EXIT

# Isolate temp repositories from the developer's git configuration (signing, hooksPath, templates).
export GIT_CONFIG_GLOBAL=/dev/null
export GIT_CONFIG_NOSYSTEM=1
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_PREFIX
# A developer's real denylist must not leak into the tests; denylist tests set the variable explicitly.
unset KOKPIT_DENYLIST

pass=0
fail=0

_ok() { pass=$((pass + 1)); printf 'ok - %s\n' "$1"; }
_fail() { fail=$((fail + 1)); printf 'FAIL: %s\n' "$1"; }

# assert_exit <expected-exit> <description> <command...>   (output kept in $TEST_TMP/out for assert_out_*)
assert_exit() {
  local want=$1 desc=$2 got=0
  shift 2
  "$@" > "$TEST_TMP/out" 2>&1 || got=$?
  if [ "$got" -eq "$want" ]; then
    _ok "$desc"
  else
    _fail "$desc (exit $got, want $want)"
    sed 's/^/    /' "$TEST_TMP/out"
  fi
}

# assert_out_has <fixed-string> [description]: the last assert_exit output contains the string
assert_out_has() {
  if grep -qF -e "$1" "$TEST_TMP/out"; then _ok "${2:-output has: $1}"; else _fail "${2:-output lacks: $1}"; fi
}

# assert_out_lacks <fixed-string> [description]: the last assert_exit output must not contain the string
assert_out_lacks() {
  if grep -qF -e "$1" "$TEST_TMP/out"; then _fail "${2:-output leaks: $1}"; else _ok "${2:-output has no: $1}"; fi
}

# assert_eq <description> <expected> <actual>
assert_eq() {
  if [ "$2" = "$3" ]; then _ok "$1"; else _fail "$1 (got '$3', want '$2')"; fi
}

# count_entries <dir>: number of entries (including dotfiles) directly inside a directory
count_entries() { find "$1" -mindepth 1 -maxdepth 1 | wc -l | tr -d ' '; }

# in_dir <dir> <command...>: run a command with the given working directory
in_dir() {
  local d=$1
  shift
  ( cd "$d" && "$@" )
}

# new_repo: print the path of a fresh temp repository (identity set, signing off). Removed with TEST_TMP.
new_repo() {
  local d
  d=$(mktemp -d "$TEST_TMP/repo.XXXXXX")
  ( cd "$d" \
    && git init -q -b main . \
    && git config user.email t@example.com \
    && git config user.name tester \
    && git config commit.gpgsign false )
  printf '%s\n' "$d"
}

# skip <reason>: the whole test file is skipped (run.sh counts exit 77 as SKIP)
skip() {
  printf 'SKIP: %s\n' "$*"
  exit 77
}

# finish: print the counts and exit non-zero if any assertion failed
finish() {
  printf '# assertions passed: %d, failed: %d\n' "$pass" "$fail"
  [ "$fail" -eq 0 ] || exit 1
  exit 0
}

# iban_cz <20-digit BBAN>: checksum-valid CZ IBAN (verified under /bin/bash 3.2)
iban_cz() {
  local bban=$1 num r=0 i d
  num="${bban}123500"                      # 'C'=12 'Z'=35 then 00
  for (( i = 0; i < ${#num}; i++ )); do d=${num:i:1}; r=$(( (r * 10 + d) % 97 )); done
  printf 'CZ%02d%s\n' $(( 98 - r )) "$bban"
}

# Fake-value builders, one per D-02 category. Fictional: invented names, a non-existent bank code, a fake domain.
fake_email()      { printf 'jane%scorp-fake.cz' '@'; }
fake_ico()        { printf '%s%s' 2712 3456; }
fake_iban()       { iban_cz 00000000000000000000; }
fake_account()    { printf '%s-%s%s/%s%s' 12 34567 89012 99 99; }
fake_ip()         { printf '%s.%s.%s.%s' 45 33 22 11; }
fake_host()       { printf '%s.%s.%s' fake-svc zerops app; }
fake_home()       { printf '/%s/%s/%s' Users fictionalperson projects; }
fake_stripe_key() { printf '%s%s%s' sk _live_ aB3dE5fG7hJ9kL1mN3pQ5rS7; }
fake_ghp()        { printf '%s%s%s' gh p_ aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5; }
fake_aws()        { printf '%s%s%s' AK IA FAKEFIXTURE01234; }
