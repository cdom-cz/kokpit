#!/usr/bin/env bash
# test-docs.sh - static checks that the hygiene rule and procedure stay in CONTRIBUTING.md and
# .claude/CLAUDE.md. Plain Bash, no external tools, so it runs in quick mode and in CI.
#
# Required phrases are ASCII and are matched byte-wise (LC_ALL=C grep -F), so the encoding or Unicode
# normalisation of the surrounding (possibly Czech) text can never produce a false pass or fail.
HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=/dev/null
. "$HERE/lib.sh"

# Phrases both documents must contain.
BOTH_PHRASES=(
  'Fictional data only'
  'git status'
  'git diff --staged'
  'scripts/check-sensitive.sh'
)

# Phrases only CONTRIBUTING.md must contain (the full hygiene reference).
CONTRIB_PHRASES=(
  'secret scanning'
  'push protection'
  'KOKPIT_DENYLIST'
  'CI Passed'
  '--no-verify'
  'LEFTHOOK=0'
  'SECURITY.md'
  'lefthook 2.1.17'
  'gitleaks 8.30.1'
  'scripts/install-hooks.sh'
  'Require actions to be pinned to a full-length commit SHA'
  'Review .gitignore'
  'Require review from Code Owners'
  'composed and decomposed'
  'git-attributes'
  '--diff-merges=first-parent'
  'path-scoped allowlist entry'
  'ddev start'
  'composer ci'
  'uuidv7()'
  'timestamptz'
  'MorphMap'
  'SequenceAllocator'
  'PartnerIsolated'
  'NotPartnerScoped'
  'AccessRule'
  'CanaryRegistry'
  'lang/cs'
)

# has_phrase <file> <phrase>: byte-wise fixed-string match
has_phrase() { LC_ALL=C grep -F -q -e "$2" "$1"; }

# docs_problems <root>: print one line per problem found in <root>/CONTRIBUTING.md and
# <root>/.claude/CLAUDE.md. No output means the documents are fine.
docs_problems() {
  local root=$1 f p
  for f in CONTRIBUTING.md .claude/CLAUDE.md; do
    if [ ! -f "$root/$f" ]; then
      printf 'missing: %s\n' "$f"
    elif [ ! -s "$root/$f" ]; then
      printf 'empty: %s\n' "$f"
    else
      for p in "${BOTH_PHRASES[@]}"; do
        has_phrase "$root/$f" "$p" || printf 'phrase missing in %s: %s\n' "$f" "$p"
      done
    fi
  done
  if [ -s "$root/CONTRIBUTING.md" ]; then
    for p in "${CONTRIB_PHRASES[@]}"; do
      has_phrase "$root/CONTRIBUTING.md" "$p" || printf 'phrase missing in CONTRIBUTING.md: %s\n' "$p"
    done
  fi
  if [ -s "$root/.claude/CLAUDE.md" ]; then
    local c="$root/.claude/CLAUDE.md"
    has_phrase "$c" '## Repository Hygiene' || printf 'heading missing in .claude/CLAUDE.md: ## Repository Hygiene\n'
    # The hygiene section must sit outside every GSD marker pair, or a regeneration would erase it.
    awk '
      /<!-- GSD:[a-z-]+-start/ { depth++; next }
      /<!-- GSD:[a-z-]+-end/   { if (depth > 0) depth--; next }
      /^## Repository Hygiene$/ { if (depth > 0) bad = 1 }
      END { exit bad ? 1 : 0 }
    ' "$c" || printf 'hygiene heading is inside a GSD marker block in .claude/CLAUDE.md\n'
    # The generated stack, conventions and architecture blocks described the GSD framework (and leaked a
    # personal path); they must not come back.
    if LC_ALL=C grep -E -q '<!-- GSD:(stack|conventions|architecture)' "$c"; then
      printf 'generated GSD stack/conventions/architecture block present in .claude/CLAUDE.md\n'
    fi
  fi
}

# --- the real documents -------------------------------------------------------------------------------

problems=$(docs_problems "$REPO_ROOT")
if [ -z "$problems" ]; then
  _ok "CONTRIBUTING.md and .claude/CLAUDE.md carry every required phrase and keep the hygiene section outside GSD blocks"
else
  _fail "documents are missing required content"
  printf '%s\n' "$problems" | sed 's/^/    /'
fi

# --- the check itself must fail when it should ---------------------------------------------------------

# mk_root <dir>: copy both documents into a temp root
mk_root() {
  mkdir -p "$1/.claude"
  cp "$REPO_ROOT/CONTRIBUTING.md" "$1/CONTRIBUTING.md" 2> /dev/null || : > "$1/CONTRIBUTING.md"
  cp "$REPO_ROOT/.claude/CLAUDE.md" "$1/.claude/CLAUDE.md" 2> /dev/null || : > "$1/.claude/CLAUDE.md"
}

expect_problem() {  # expect_problem <description> <root> <expected-substring>
  local out
  out=$(docs_problems "$2")
  if [ -n "$out" ] && printf '%s\n' "$out" | grep -qF -e "$3"; then
    _ok "$1"
  else
    _fail "$1 (problems: ${out:-none})"
  fi
}

# the unmodified copy is fine (proves the mutations below are what trips the check)
r="$TEST_TMP/base"; mk_root "$r"
assert_eq "unmodified copy has no problems" "" "$(docs_problems "$r")"

# missing files
r="$TEST_TMP/nofiles"; mkdir -p "$r"
expect_problem "missing CONTRIBUTING.md is reported" "$r" 'missing: CONTRIBUTING.md'
expect_problem "missing .claude/CLAUDE.md is reported" "$r" 'missing: .claude/CLAUDE.md'

# empty files
r="$TEST_TMP/empty"; mk_root "$r"; : > "$r/CONTRIBUTING.md"; : > "$r/.claude/CLAUDE.md"
expect_problem "empty CONTRIBUTING.md is reported" "$r" 'empty: CONTRIBUTING.md'
expect_problem "empty .claude/CLAUDE.md is reported" "$r" 'empty: .claude/CLAUDE.md'

# every required phrase, removed one at a time from a copy, must be reported (case change counts as removal)
for p in "${BOTH_PHRASES[@]}"; do
  r="$TEST_TMP/nophrase"; rm -rf "$r"; mk_root "$r"
  LC_ALL=C sed "s|$p|REDACTED|g" "$REPO_ROOT/.claude/CLAUDE.md" > "$r/.claude/CLAUDE.md"
  expect_problem "phrase removed from CLAUDE.md is reported: $p" "$r" "phrase missing in .claude/CLAUDE.md: $p"
  LC_ALL=C sed "s|$p|REDACTED|g" "$REPO_ROOT/CONTRIBUTING.md" > "$r/CONTRIBUTING.md"
  expect_problem "phrase removed from CONTRIBUTING.md is reported: $p" "$r" "phrase missing in CONTRIBUTING.md: $p"
done
for p in "${CONTRIB_PHRASES[@]}"; do
  r="$TEST_TMP/nophrase"; rm -rf "$r"; mk_root "$r"
  LC_ALL=C sed "s|$p|REDACTED|g" "$REPO_ROOT/CONTRIBUTING.md" > "$r/CONTRIBUTING.md"
  expect_problem "phrase removed from CONTRIBUTING.md is reported: $p" "$r" "phrase missing in CONTRIBUTING.md: $p"
done

# a phrase that differs only in a byte of surrounding text must not hide a missing one: non-ASCII text next
# to the phrase (Czech, composed and decomposed) does not change the verdict
r="$TEST_TMP/czech"; mk_root "$r"
{ printf 'P\303\250\305\231\303\255li\305\241 \305\276lu\305\245ou\304\215k\303\275 k\305\257\305\210\n'; cat "$REPO_ROOT/CONTRIBUTING.md"; } > "$r/CONTRIBUTING.md"
assert_eq "non-ASCII text around the phrases does not change the verdict" "" "$(docs_problems "$r")"

# the hygiene heading moved inside a marker pair
r="$TEST_TMP/inblock"; mk_root "$r"
{ printf '<!-- GSD:project-start source:PROJECT.md -->\n'; cat "$REPO_ROOT/.claude/CLAUDE.md"; printf '<!-- GSD:project-end -->\n'; } > "$r/.claude/CLAUDE.md"
expect_problem "hygiene heading inside a GSD block is reported" "$r" 'inside a GSD marker block'

# a regenerated stack block
r="$TEST_TMP/stack"; mk_root "$r"
{ cat "$REPO_ROOT/.claude/CLAUDE.md"; printf '\n<!-- GSD:stack-start source:codebase/STACK.md -->\nx\n<!-- GSD:stack-end -->\n'; } > "$r/.claude/CLAUDE.md"
expect_problem "a regenerated stack block is reported" "$r" 'generated GSD stack/conventions/architecture block'

finish
