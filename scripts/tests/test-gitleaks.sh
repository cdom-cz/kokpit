#!/usr/bin/env bash
# test-gitleaks.sh - the gitleaks layer (.gitleaks.toml) against planted fakes in temp repositories.
# Local runs skip in quick mode or when gitleaks is absent. In CI (CI=true) a missing gitleaks is a failure,
# because the workflow installs it before this suite runs. Every fake value is built from fragments.
HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=/dev/null
. "$HERE/lib.sh"

[ "${KOKPIT_TEST_QUICK:-}" = "1" ] && skip "quick mode (KOKPIT_TEST_QUICK=1)"
if ! command -v gitleaks > /dev/null 2>&1; then
  if [ "${CI:-}" = "true" ]; then
    printf 'FAIL: gitleaks missing in CI\n'
    exit 1
  fi
  skip "gitleaks is not on PATH"
fi

# The config is copied outside the temp repositories, so the repository under test never contains it.
CFG="$TEST_TMP/gitleaks.toml"
cp "$REPO_ROOT/.gitleaks.toml" "$CFG"
REPORT="$TEST_TMP/r.json"

# glk <repo> [extra gitleaks args...]: run from inside the repository, always redacted, JSON report.
glk() {
  local repo=$1
  shift
  rm -f "$REPORT"
  ( cd "$repo" && gitleaks git --config "$CFG" --redact --no-banner --ignore-gitleaks-allow \
      --report-format json --report-path "$REPORT" "$@" )
}

put() {  # put <repo> <path> <content>
  mkdir -p "$(dirname "$1/$2")"
  printf '%s\n' "$3" > "$1/$2"
}

commit_all() {  # commit_all <repo> <message>
  git -C "$1" add -A
  git -C "$1" commit -q -m "$2"
}

# The exact log options the CI step passes to gitleaks (see .github/workflows/hygiene.yml). The default log range
# skips merge diffs and content git treats as binary; these options make gitleaks read both. UTF-16 stays
# invisible to gitleaks and is covered by scripts/check-sensitive.sh --history.
GLK_LOG_OPTS='--all --diff-merges=first-parent --text --no-textconv'

glk_ci() {  # glk_ci <repo>: the CI form of the history scan
  glk "$1" --log-opts="$GLK_LOG_OPTS"
}

# Fake bodies: mixed-case alphanumerics with high entropy, so the default rules' entropy checks pass.
b1=aB3dE5fG7hJ9kL1mN3pQ5rS7
b2=Zx8Cv6Bn4Mq2Wr0Ty9Ui7Op5
b3=Lk3Jh5Gf7Ds9Aq1We3Rt5Yu7
b4=Pn6Bv4Cx2Zs0Ad8Fg6Hj4Kl2
b5=Qw9Er7Ty5Ui3Op1As9Df7Gh5
pk=$(printf '%s%s%s' pk _live_ "$b1")
wh=$(printf '%s%s%s' whsec _ "$b2")
acct=$(printf '%s%s%s' acct _ "$b3")
plink=$(printf '%s%s%s' plink _ "$b4")
ghp=$(fake_ghp)
home=$(fake_home)/src
host=$(fake_host)
ico=$(fake_ico)
zt=$(printf '%s%s%s' ZEROPS _TOKEN "=$b5")

# (a) a clean commit: exit 0
clean=$(new_repo)
put "$clean" README.md "plain documentation, nothing sensitive"
commit_all "$clean" clean
assert_exit 0 "(a) gitleaks git on a clean history exits 0" glk "$clean"

# (b) one default-rule hit plus one hit per custom rule, in one commit
bad=$(new_repo)
put "$bad" planted.txt "$(printf 'token: %s\nstripe: %s\nhook: %s\naccount: %s\nlink: %s\nhome: %s\nico: %s\nendpoint: %s\n%s' \
  "$ghp" "$pk" "$wh" "$acct" "$plink" "$home" "$ico" "$host" "$zt")"
commit_all "$bad" planted
assert_exit 1 "(b) gitleaks git on planted fakes exits 1" glk "$bad"
for id in github-pat kokpit-stripe-publishable-or-webhook kokpit-stripe-object-id kokpit-home-path \
  kokpit-ico-like kokpit-hosting-endpoint kokpit-zerops-token; do
  if grep -qF "\"RuleID\": \"$id\"" "$REPORT"; then _ok "(b) report contains rule $id"; else _fail "(b) report lacks rule $id"; fi
done
# two values share each Stripe rule (publishable key + webhook secret, account id + payment link id): both must be found
for id in kokpit-stripe-publishable-or-webhook kokpit-stripe-object-id; do
  assert_eq "(b) rule $id fired once per planted value" "2" "$(grep -cF "\"RuleID\": \"$id\"" "$REPORT")"
done
# nothing of any fake value may appear in the redacted report
for body in "$b1" "$b2" "$b3" "$b4" "$b5" "$ico" fictionalperson "$host"; do
  if grep -qF -e "$body" "$REPORT"; then _fail "(b) report leaks a fake value ($(printf '%s' "$body" | cut -c1-3)...)"; else _ok "(b) report has no fake value ($(printf '%s' "$body" | cut -c1-3)...)"; fi
done
assert_exit 1 "(b) stdout run of the same scan exits 1" glk "$bad" --verbose
for body in "$b1" "$b2" "$b3" "$b4" "$b5" "$ico" fictionalperson "$host"; do
  assert_out_lacks "$body" "(b) verbose stdout has no fake value ($(printf '%s' "$body" | cut -c1-3)...)"
done

# (c) documented placeholders alone produce no finding
ph=$(new_repo)
put "$ph" notes.md "$(printf 'ico: 12345678\ncompany_id = 00000000\npath: /Users/example/project\nrunner: /home/runner/work\nshared: /Users/Shared/tmp\n')"
commit_all "$ph" placeholders
assert_exit 0 "(c) placeholders (12345678, 00000000, /Users/example/, /home/runner/, /Users/Shared/) are not reported" glk "$ph"

# (d) the history case: the bad file is deleted in a later commit, gitleaks still reports it
git -C "$bad" rm -q planted.txt
git -C "$bad" commit -q -m "remove planted file"
assert_eq "(d) the planted file is gone from the tree" "0" "$([ -e "$bad/planted.txt" ] && echo 1 || echo 0)"
assert_exit 1 "(d) a fake deleted in a later commit is still reported from history" glk "$bad"
if grep -qF '"RuleID": "github-pat"' "$REPORT"; then _ok "(d) history finding names the default rule"; else _fail "(d) history finding lacks github-pat"; fi

# (e) staged mode, as the hook runs it: staged fake exits 1, clean staged file exits 0
st=$(new_repo)
put "$st" README.md "readme"
commit_all "$st" init
put "$st" staged.txt "key: $ghp"
git -C "$st" add -- staged.txt
assert_exit 1 "(e) --pre-commit --staged on a staged fake exits 1" glk "$st" --pre-commit --staged
assert_out_lacks "$b1" "(e) staged-mode output has no fake value"
git -C "$st" reset -q
rm -f "$st/staged.txt"
put "$st" fine.txt "nothing sensitive here"
git -C "$st" add -- fine.txt
assert_exit 0 "(e) --pre-commit --staged on a clean staged file exits 0" glk "$st" --pre-commit --staged

# --- the CI form: --log-opts with text and first-parent merge diffs ---------------------------------------

# (f) the workflow carries exactly the log-opts string the cases below use
if grep -qF -- "--log-opts=\"$GLK_LOG_OPTS\"" "$REPO_ROOT/.github/workflows/hygiene.yml"; then
  _ok "(f) the workflow passes the same --log-opts string as this suite"
else
  _fail "(f) the workflow does not carry --log-opts=\"$GLK_LOG_OPTS\""
fi

# (f) a committed -diff file holding a fake key is reported by the CI form
hid=$(new_repo)
put "$hid" .gitattributes '*.dat -diff'
put "$hid" a.dat "token: $ghp"
commit_all "$hid" hidden
assert_exit 1 "(f) CI form reports a fake in a -diff-attributed file" glk_ci "$hid"
if grep -qF '"RuleID": "github-pat"' "$REPORT"; then _ok "(f) the report names rule github-pat"; else _fail "(f) the report lacks github-pat"; fi
assert_out_lacks "$b1" "(f) stdout has no fake value"
if grep -qF -e "$b1" "$REPORT"; then _fail "(f) report leaks the fake value"; else _ok "(f) report has no fake value"; fi

# (g) a committed file holding the fake key followed by a NUL byte is reported by the CI form
nul=$(new_repo)
printf 'token: %s\n\000' "$ghp" > "$nul/n.txt"
commit_all "$nul" nul
assert_exit 1 "(g) CI form reports a fake in a NUL-containing file" glk_ci "$nul"
if grep -qF '"RuleID": "github-pat"' "$REPORT"; then _ok "(g) the report names rule github-pat"; else _fail "(g) the report lacks github-pat"; fi
assert_out_lacks "$b1" "(g) stdout has no fake value"
if grep -qF -e "$b1" "$REPORT"; then _fail "(g) report leaks the fake value"; else _ok "(g) report has no fake value"; fi

# (h) an evil merge: the fake exists only in the merge result, never in a branch commit
evil=$(new_repo)
put "$evil" base.txt "base"
commit_all "$evil" base
git -C "$evil" checkout -q -b side
put "$evil" side.txt "side"
commit_all "$evil" side
git -C "$evil" checkout -q main
put "$evil" main.txt "main"
commit_all "$evil" main
git -C "$evil" merge -q --no-ff --no-commit side > /dev/null
put "$evil" evil.txt "token: $ghp"
git -C "$evil" add evil.txt
git -C "$evil" commit -q -m "merge side"
assert_exit 1 "(h) CI form reports a fake that exists only in a merge commit" glk_ci "$evil"
if grep -qF '"RuleID": "github-pat"' "$REPORT"; then _ok "(h) the report names rule github-pat"; else _fail "(h) the report lacks github-pat"; fi
assert_out_lacks "$b1" "(h) stdout has no fake value"
if grep -qF -e "$b1" "$REPORT"; then _fail "(h) report leaks the fake value"; else _ok "(h) report has no fake value"; fi

# (i) a clean history with a branch and a merge exits 0 in the CI form
cm=$(new_repo)
put "$cm" base.txt "base"
commit_all "$cm" base
git -C "$cm" checkout -q -b side
put "$cm" side.txt "side"
commit_all "$cm" side
git -C "$cm" checkout -q main
put "$cm" main.txt "main"
commit_all "$cm" main
git -C "$cm" merge -q --no-ff -m "merge side" side > /dev/null
assert_exit 0 "(i) CI form on a clean history with a merge exits 0" glk_ci "$cm"

finish
