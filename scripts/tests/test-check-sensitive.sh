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
# path_hit  <description> <rule> <path> <content>   expects exit 1 and "<path>:1: <rule>" in the output
# path_pass <description> <path> <content>          expects exit 0
# case_hit / case_pass use the default path n.txt.
path_hit() {
  local r
  r=$(new_repo)
  stage "$r" "$3" "$4"
  assert_exit 1 "$1" in_dir "$r" "$SCRIPT"
  assert_out_has "$3:1: $2" "$1 (reported as $2 with file and line)"
}
path_pass() {
  local r
  r=$(new_repo)
  stage "$r" "$2" "$3"
  assert_exit 0 "$1" in_dir "$r" "$SCRIPT"
}
case_hit() { path_hit "$1" "$2" n.txt "$3"; }
case_pass() { path_pass "$1" n.txt "$2"; }

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

# (p) company-id rule: an 8-digit number is reported as company-id, masked
ico=$(fake_ico)
repo=$(new_repo)
stage "$repo" p.txt "$(printf 'ICO: %s\n' "$ico")"
assert_exit 1 "(p) 8-digit company ID exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "p.txt:1: company-id" "(p) finding names file, line and rule"
assert_out_lacks "$ico" "(p) the full number is not printed"

# (q) the documented placeholders pass in any path
case_pass "(q) placeholder 12345678 passes" "$(printf 'ICO: %s%s\n' 1234 5678)"
case_pass "(q) placeholder 00000000 passes" "$(printf 'ICO: %s%s\n' 0000 0000)"
path_pass "(q) placeholder 12345678 passes under src/" src/q.md "$(printf '%s%s\n' 1234 5678)"

# (r) invoice-like numbers: exempt under .planning/ only (path-scoped allowlist entry)
inv=$(printf '%s%s' 2026 0001)
path_pass "(r) invoice-like number under .planning/ passes" .planning/notes.md "$(printf 'Invoice %s\n' "$inv")"
path_hit "(r) the same number under src/ is reported" company-id src/notes.md "$(printf 'Invoice %s\n' "$inv")"

# (s) shapes that are not company IDs
case_pass "(s) an 8-digit group starting a UUID-like token passes" "$(printf '%s%s-aaaa-bbbb-cccc-dddddddddddd\n' 3141 5926)"
case_pass "(s) a 9-digit run passes" "$(printf '%s%s%s\n' 314 159 265)"
case_pass "(s) an 8-digit run inside a dotted version string passes" "$(printf 'v1.2.%s%s\n' 3141 5926)"

# (t) iban rule: checksum-valid IBAN of the exact country length
iban=$(fake_iban)
repo=$(new_repo)
stage "$repo" t.txt "$(printf 'IBAN: %s\n' "$iban")"
assert_exit 1 "(t) valid IBAN exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "t.txt:1: iban" "(t) finding names file, line and rule"
assert_out_lacks "$iban" "(t) the full IBAN is not printed"
grouped=$(printf '%s' "$iban" | sed 's/.\{4\}/& /g')
case_hit "(t) the same IBAN written in 4-character groups is reported" iban "$(printf 'IBAN: %s\n' "$grouped")"
last=${iban#"${iban%?}"}
wrong="${iban%?}$(( (last + 1) % 10 ))"
case_pass "(t) the IBAN with one digit changed passes (wrong checksum)" "$(printf 'IBAN: %s\n' "$wrong")"
case_pass "(t) a 24-character random uppercase identifier passes" "$(printf 'id: %s%s\n' QX12ABCDEFGH JKLMNPQRSTUV)"

# (u) cz-account rule: [prefix-]number/bank
acct=$(fake_account)
repo=$(new_repo)
stage "$repo" u.txt "$(printf 'account %s\n' "$acct")"
assert_exit 1 "(u) account number with prefix exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "u.txt:1: cz-account" "(u) finding names file, line and rule"
assert_out_lacks "$acct" "(u) the full account is not printed"
case_hit "(u) account number without prefix is reported" cz-account "$(printf 'account %s%s/%s%s\n' 34567 89012 99 99)"
case_pass "(u) a month/year string 10/2026 passes" "$(printf 'due 10/%s\n' 2026)"
case_pass "(u) a month/year string 1/2026 passes" "$(printf 'due 1/%s\n' 2026)"

# (v) public-ip rule: a public IPv4 address is reported, masked
ip=$(fake_ip)
repo=$(new_repo)
stage "$repo" v.txt "$(printf 'host %s\n' "$ip")"
assert_exit 1 "(v) public IPv4 exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "v.txt:1: public-ip" "(v) finding names file, line and rule"
assert_out_lacks "$ip" "(v) the full address is not printed"

# (w) private, loopback, link-local, documentation, CGNAT and multicast ranges pass
for addr in 10.1.2.3 172.16.0.1 172.31.255.254 192.168.1.1 127.0.0.1 0.0.0.0 169.254.1.1 \
            192.0.2.1 198.51.100.1 203.0.113.1 100.64.0.1 224.0.0.1; do
  case_pass "(w) $addr passes" "$(printf 'ip %s\n' "$addr")"
done

# (x) shapes that are not addresses
case_pass "(x) a version string preceded by a letter passes" "$(printf 'v%s\n' "$ip")"
case_pass "(x) an octet above 255 passes" "$(printf '%s.%s.%s.%s\n' 300 1 1 1)"
case_pass "(x) a five-part dotted number passes" "$(printf '%s.%s\n' "$ip" 5)"

# (y) hosting-host rule: Zerops and S3-provider hostnames
host=$(fake_host)
repo=$(new_repo)
stage "$repo" y.txt "$(printf 'url https://%s/\n' "$host")"
assert_exit 1 "(y) Zerops hostname exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "y.txt:1: hosting-host" "(y) finding names file, line and rule"
assert_out_lacks "$host" "(y) the full hostname is not printed"
for sfx in amazonaws.com r2.cloudflarestorage.com backblazeb2.com wasabisys.com digitaloceanspaces.com linodeobjects.com; do
  case_hit "(y) bucket host under $sfx is reported" hosting-host "$(printf 'endpoint %s.%s\n' fake-bucket "$sfx")"
done

# (z) home-path rule: personal home directories
home=$(fake_home)
repo=$(new_repo)
stage "$repo" z.txt "$(printf 'path %s\n' "$home")"
assert_exit 1 "(z) /Users/<name>/ exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "z.txt:1: home-path" "(z) finding names file, line and rule"
assert_out_lacks "fictionalperson" "(z) the user name is not printed"
case_hit "(z) /home/<name>/ is reported" home-path "$(printf 'path /%s/%s/projects\n' home fictionalperson)"
case_pass "(z) /Users/example/ passes" "path /Users/example/app"
case_pass "(z) /home/runner/ passes" "path /home/runner/work"
case_pass "(z) /home/ubuntu/ passes" "path /home/ubuntu/app"
case_pass "(z) /Users/Shared/ passes" "path /Users/Shared/tmp"

finish
