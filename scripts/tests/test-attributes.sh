#!/usr/bin/env bash
# test-attributes.sh - the git-attributes rule of scripts/check-sensitive.sh (gap 1, second layer, CR-01).
# A .gitattributes line that hides content from gitleaks (-diff, binary) or moves it outside git (filter=)
# must be reported in the hook (staged mode), in the CI tree scan (--all) and in explicit-file mode, but never
# for history: an attribute that a later commit removed must not keep CI red. All values are built at runtime.
HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=/dev/null
. "$HERE/lib.sh"
SCRIPT="$REPO_ROOT/scripts/check-sensitive.sh"

stage() {  # stage <repo> <path> <content>: write a file (creating directories) and git add it
  mkdir -p "$(dirname "$1/$2")"
  printf '%s' "$3" > "$1/$2"
  git -C "$1" add -- "$2"
}

# (a) -diff on a path pattern
repo=$(new_repo)
stage "$repo" .gitattributes "$(printf '*.dat -diff\n')"
assert_exit 1 "(a) staged '-diff' attribute exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has ".gitattributes:1: git-attributes" "(a) finding names file, line and rule"

# (b) the binary macro (-diff -merge -text)
repo=$(new_repo)
stage "$repo" .gitattributes "$(printf '*.bin binary\n')"
assert_exit 1 "(b) staged 'binary' macro exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has ".gitattributes:1: git-attributes" "(b) finding names file, line and rule"

# (c) a macro definition that bundles -diff, after a comment line
repo=$(new_repo)
stage "$repo" .gitattributes "$(printf '# macros\n[attr]hidden -diff -merge\n')"
assert_exit 1 "(c) '[attr]' macro bundling -diff exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has ".gitattributes:2: git-attributes" "(c) finding names the macro line"

# (d) a clean/smudge filter diverts the content outside git
repo=$(new_repo)
stage "$repo" .gitattributes "$(printf '*.big filter=lfs diff=lfs merge=lfs -text\n')"
assert_exit 1 "(d) 'filter=' attribute exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has ".gitattributes:1: git-attributes" "(d) finding names file, line and rule"

# (e) a .gitattributes in a sub-directory counts too
repo=$(new_repo)
stage "$repo" docs/.gitattributes "$(printf '*.dat -diff\n')"
assert_exit 1 "(e) sub-directory .gitattributes with -diff exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "docs/.gitattributes:1:" "(e) finding names the sub-directory file"

# (f) a CRLF line ending must not hide the token
repo=$(new_repo)
stage "$repo" .gitattributes "$(printf '*.dat -diff\r\n')"
assert_exit 1 "(f) CRLF line with -diff exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has ".gitattributes:1: git-attributes" "(f) finding names file, line and rule"

# (g) Laravel-style benign attributes and a comment that mentions -diff pass
repo=$(new_repo)
stage "$repo" .gitattributes "$(printf '* text=auto eol=lf\n*.php diff=php\n*.png -text\n/.github export-ignore\n# never use -diff or binary or filter=x here\n')"
assert_exit 0 "(g) benign attributes and a comment exit 0" in_dir "$repo" "$SCRIPT"

# (h) the same tokens in a file that is not named .gitattributes pass
repo=$(new_repo)
stage "$repo" notes.txt "$(printf '*.dat -diff\n')"
assert_exit 0 "(h) '-diff' in notes.txt exits 0" in_dir "$repo" "$SCRIPT"

# (i) current-state policy: --all reports a tracked line, --history never does, a removed line is clean
repo=$(new_repo)
printf '*.dat -diff\n' > "$repo/.gitattributes"
git -C "$repo" add .gitattributes
git -C "$repo" commit -q -m 'add attribute'
assert_exit 1 "(i) --all reports the tracked '-diff' line" in_dir "$repo" "$SCRIPT" --all
assert_out_has ".gitattributes:1: git-attributes" "(i) --all finding names file, line and rule"
assert_exit 0 "(i) --history does not report an attribute line" in_dir "$repo" "$SCRIPT" --history
printf '*.dat text\n' > "$repo/.gitattributes"
git -C "$repo" add .gitattributes
git -C "$repo" commit -q -m 'remove attribute'
assert_exit 0 "(i) --history is clean after the line was removed" in_dir "$repo" "$SCRIPT" --history
assert_exit 0 "(i) --all is clean after the line was removed" in_dir "$repo" "$SCRIPT" --all

# (k) explicit-file mode applies the rule to a .gitattributes operand
repo=$(new_repo)
printf '*.dat -diff\n' > "$repo/.gitattributes"
assert_exit 1 "(k) explicit .gitattributes operand with -diff exits 1" in_dir "$repo" "$SCRIPT" .gitattributes
assert_out_has ".gitattributes:1: git-attributes" "(k) finding names file, line and rule"

# (j) CODEOWNERS covers the attribute file (WR-06)
if grep -q '^/\.gitattributes ' "$REPO_ROOT/.github/CODEOWNERS"; then
  _ok "(j) CODEOWNERS has an entry for /.gitattributes"
else
  _fail "(j) CODEOWNERS lacks an entry for /.gitattributes"
fi

finish
