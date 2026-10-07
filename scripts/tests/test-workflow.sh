#!/usr/bin/env bash
# test-workflow.sh - the "CI Passed" aggregator must wait for every other job of the Hygiene workflow.
#
# The organisation ruleset requires only the check named "CI Passed". A job that is missing from its needs
# list would run, fail and still leave the gate green, so every job id under jobs: except ci-passed itself
# has to appear in ci-passed.needs. The check reads the real workflow; a mutation case on a temp copy proves
# that a dropped id is reported. Plain awk and sed, bash 3.2 safe (no associative arrays, no mapfile).
HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=/dev/null
. "$HERE/lib.sh"

WORKFLOW="$REPO_ROOT/.github/workflows/hygiene.yml"

# job_ids <workflow>: one job id per line, in file order (keys indented two spaces directly under jobs:)
job_ids() {
  awk '
    /^jobs:[[:space:]]*$/ { in_jobs = 1; next }
    in_jobs && /^[^[:space:]#]/ { in_jobs = 0 }
    in_jobs && /^  [A-Za-z0-9_-]+:[[:space:]]*$/ { id = $1; sub(/:$/, "", id); print id }
  ' "$1"
}

# ci_passed_needs <workflow>: one id per line from the inline `needs: [a, b]` list of the ci-passed job
ci_passed_needs() {
  awk '
    /^  ci-passed:[[:space:]]*$/ { in_job = 1; next }
    in_job && /^  [A-Za-z0-9_-]+:[[:space:]]*$/ { in_job = 0 }
    in_job && /^    needs:[[:space:]]*\[/ {
      line = $0
      sub(/^    needs:[[:space:]]*\[/, "", line)
      sub(/\].*$/, "", line)
      n = split(line, parts, ",")
      for (i = 1; i <= n; i++) { gsub(/[[:space:]]/, "", parts[i]); if (parts[i] != "") print parts[i] }
    }
  ' "$1"
}

# missing_from_needs <workflow>: ids of jobs (except ci-passed) that ci-passed does not wait for
missing_from_needs() {
  local wf=$1 needs id
  needs=$(ci_passed_needs "$wf")
  job_ids "$wf" | while IFS= read -r id; do
    [ "$id" = "ci-passed" ] && continue
    printf '%s\n' "$needs" | grep -qxF -- "$id" || printf '%s\n' "$id"
  done
}

# --- the extraction itself is not vacuous ------------------------------------------------------------------
ids=$(job_ids "$WORKFLOW")
needs=$(ci_passed_needs "$WORKFLOW")
job_count=$(printf '%s\n' "$ids" | grep -c . || true)
needs_count=$(printf '%s\n' "$needs" | grep -c . || true)

if [ "$job_count" -ge 2 ]; then _ok "found $job_count jobs under jobs:"; else _fail "found only $job_count jobs under jobs:"; fi
if printf '%s\n' "$ids" | grep -qxF ci-passed; then _ok "the ci-passed job exists"; else _fail "the ci-passed job is missing"; fi
if [ "$needs_count" -ge 1 ]; then _ok "ci-passed.needs lists $needs_count jobs"; else _fail "ci-passed.needs is empty or not an inline list"; fi

# --- the real workflow: nothing is missing -----------------------------------------------------------------
missing=$(missing_from_needs "$WORKFLOW")
assert_eq "every job except ci-passed is in ci-passed.needs" "" "$missing"

# the check name stays exactly what the ruleset requires
if grep -qxF '    name: CI Passed' "$WORKFLOW"; then _ok "the aggregator is named exactly CI Passed"; else _fail "the aggregator is not named exactly CI Passed"; fi

# --- mutation: drop one id from needs on a temp copy and it must be reported --------------------------------
victim=$(printf '%s\n' "$ids" | grep -vxF ci-passed | tail -n 1)
if [ -z "$victim" ]; then
  _fail "no job to use for the mutation case"
else
  mutant="$TEST_TMP/hygiene-mutant.yml"
  # remove the victim id (and one separating comma) from the needs line of ci-passed only
  awk -v victim="$victim" '
    /^  ci-passed:[[:space:]]*$/ { in_job = 1 }
    in_job && /^    needs:/ {
      n = split($0, halves, "[")
      list = halves[2]; sub(/\].*$/, "", list)
      m = split(list, parts, ",")
      out = ""
      for (i = 1; i <= m; i++) {
        p = parts[i]; gsub(/[[:space:]]/, "", p)
        if (p == victim || p == "") continue
        out = (out == "" ? p : out ", " p)
      }
      print "    needs: [" out "]"
      next
    }
    { print }
  ' "$WORKFLOW" > "$mutant"
  mutant_missing=$(missing_from_needs "$mutant")
  assert_eq "mutation: dropping '$victim' from needs is reported" "$victim" "$mutant_missing"
fi

# --- mutation: a brand new job that is not in needs is reported ----------------------------------------------
added="$TEST_TMP/hygiene-added.yml"
awk '
  /^  ci-passed:[[:space:]]*$/ && !done { print "  brand-new-job:"; print "    runs-on: ubuntu-24.04"; print ""; done = 1 }
  { print }
' "$WORKFLOW" > "$added"
assert_eq "mutation: a new job that is not in needs is reported" "brand-new-job" "$(missing_from_needs "$added")"

finish
