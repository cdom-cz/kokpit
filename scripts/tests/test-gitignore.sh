#!/usr/bin/env bash
# test-gitignore.sh - ignore/track verdicts of the real .gitignore, proven with `git check-ignore --no-index`.
#
# --no-index makes the verdict depend on the patterns alone, not on whether a path is already tracked, and
# none of the paths below is ever created, so the verdicts hold for an empty checkout. Everything runs in a
# temp repository that holds a copy of the real .gitignore.
HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=/dev/null
. "$HERE/lib.sh"

repo=$(new_repo)
cp "$REPO_ROOT/.gitignore" "$repo/.gitignore"

# verdict <repo> <path>: exit 0 = ignored, 1 = trackable (check-ignore exits 128 on a real error)
ignored() { assert_exit 0 "ignored: $2" git -C "$1" check-ignore -q --no-index -- "$2"; }
trackable() { assert_exit 1 "trackable: $2" git -C "$1" check-ignore -q --no-index -- "$2"; }

# --- ignored: secrets, local AI tooling and IDE settings, local data, credentials, runtime, caches -----
for p in \
  .env .env.local .ddev/.env .ddev/.env.web private.key cert.pem \
  .claude/settings.local.json .claude/agents/x.md .claude/hooks/y.js CLAUDE.local.md \
  .idea/workspace.xml .vscode/settings.json .cursor/rules .aider.chat.history.md .DS_Store \
  local/data.txt dump.sql db.dump backup.sql.gz export.csv report.xlsx report.xls sheet.ods \
  db.sqlite db.sqlite3 exports/a.pdf \
  auth.json cert.p12 cert.pfx release.keystore id_rsa \
  storage/logs/app.log storage/app/file.bin app.log vendor/x/y.php node_modules/x/index.js public/build/app.js \
  coverage/index.html .phpunit.cache/x .phpunit.result.cache .php-cs-fixer.cache \
  .planning/debug/x.md .planning/logs/x.md
do
  ignored "$repo" "$p"
done

# --- trackable: hygiene artefacts and the paths next to ignored ones --------------------------------------
for p in \
  .env.example .claude/CLAUDE.md .ddev/config.yaml \
  scripts/check-sensitive.sh scripts/sensitive-allowlist.txt lefthook.yml .gitleaks.toml \
  .github/workflows/hygiene.yml CONTRIBUTING.md LICENSE .planning/ROADMAP.md
do
  trackable "$repo" "$p"
done

# --- the directory itself is never ignored (it would make the negation impossible) --------------------
if grep -qxF '.claude/' "$REPO_ROOT/.gitignore"; then
  _fail ".gitignore must not ignore the .claude/ directory itself"
else
  _ok ".gitignore does not ignore the .claude/ directory itself"
fi

# --- ordering: the last matching pattern wins -------------------------------------------------------------
wild_line=$(grep -nxF '.claude/*' "$REPO_ROOT/.gitignore" | head -n 1 | cut -d: -f1)
neg_line=$(grep -nxF '!.claude/CLAUDE.md' "$REPO_ROOT/.gitignore" | head -n 1 | cut -d: -f1)
if [ -n "$wild_line" ] && [ -n "$neg_line" ] && [ "$neg_line" -gt "$wild_line" ]; then
  _ok "!.claude/CLAUDE.md (line $neg_line) comes after .claude/* (line $wild_line)"
else
  _fail "!.claude/CLAUDE.md must come after .claude/* (lines: neg='${neg_line:-none}' wildcard='${wild_line:-none}')"
fi

# reversed order in a copy: the negation loses and CLAUDE.md becomes ignored, so the order really matters
swapped=$(new_repo)
{
  grep -vxF -e '.claude/*' -e '!.claude/CLAUDE.md' "$REPO_ROOT/.gitignore"
  printf '%s\n' '!.claude/CLAUDE.md' '.claude/*'
} > "$swapped/.gitignore"
assert_exit 0 "reversed order makes .claude/CLAUDE.md ignored (order matters)" \
  git -C "$swapped" check-ignore -q --no-index -- .claude/CLAUDE.md

# ignoring the directory itself in a copy: the negation cannot re-include a file below an excluded parent
dirrule=$(new_repo)
{
  grep -vxF '.claude/*' "$REPO_ROOT/.gitignore"
  printf '%s\n' '.claude/'
} > "$dirrule/.gitignore"
assert_exit 0 "ignoring .claude/ itself makes .claude/CLAUDE.md ignored (negation dead)" \
  git -C "$dirrule" check-ignore -q --no-index -- .claude/CLAUDE.md

# --- a verdict does not depend on the paths existing or being tracked -------------------------------------
# (same answers in a repository where the files really exist and one is tracked)
real=$(new_repo)
cp "$REPO_ROOT/.gitignore" "$real/.gitignore"
mkdir -p "$real/.claude"
printf 'x\n' > "$real/.claude/CLAUDE.md"
printf 'x\n' > "$real/.claude/settings.local.json"
git -C "$real" add -f -- .claude/settings.local.json
assert_exit 1 "existing .claude/CLAUDE.md is still trackable" git -C "$real" check-ignore -q --no-index -- .claude/CLAUDE.md
assert_exit 0 "an already-tracked .claude/settings.local.json is still reported ignored with --no-index" \
  git -C "$real" check-ignore -q --no-index -- .claude/settings.local.json

finish
