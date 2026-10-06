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

finish
