#!/usr/bin/env bash
# test-content.sh - content-complete reading for scripts/check-sensitive.sh (gap 1, CR-01, WR-02, WR-05).
# Content that git attributes, NUL bytes, UTF-16 encoding, textconv drivers, type changes, renames or merge
# commits would hide from a plain diff must still reach the rules, in every mode. All fake values are built
# at runtime (see lib.sh).
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

# --- staged mode --------------------------------------------------------------------------------------

# (a) a path marked -diff in .gitattributes is still read
repo=$(new_repo)
stage "$repo" .gitattributes "$(printf '*.dat -diff\n')"
stage "$repo" a.dat "$(printf 'one\n%s\n' "$ghp")"
assert_exit 1 "(a) staged -diff file with a fake key exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "a.dat:2: key-prefix" "(a) finding names the -diff file and line"
assert_out_lacks "$ghp" "(a) full key is not printed"

# (b) the binary macro attribute does not hide content either
repo=$(new_repo)
stage "$repo" .gitattributes "$(printf '*.bin2 binary\n')"
stage "$repo" b.bin2 "$(printf 'one\n%s\n' "$stripe")"
assert_exit 1 "(b) staged binary-attribute file with a fake key exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "b.bin2:2: key-prefix" "(b) finding names the binary-attribute file"

# (c) a NUL byte anywhere in the file does not turn the scanner off
repo=$(new_repo)
mkdir -p "$repo"
printf 'one\n%s\n\000' "$stripe" > "$repo/c.txt"
printf 'bin\000%s\n' "$stripe" > "$repo/c2.txt"
git -C "$repo" add c.txt c2.txt
assert_exit 1 "(c) staged NUL-containing files with fake keys exit 1" in_dir "$repo" "$SCRIPT"
assert_out_has "c.txt:2: key-prefix" "(c) trailing NUL: finding at its line"
assert_out_has "c2.txt:1: key-prefix" "(c) NUL before the key: the space variant keeps the boundary"
assert_out_lacks "$stripe" "(c) full key is not printed"

# (d) UTF-16 text is readable once its NUL bytes are removed
repo=$(new_repo)
mkdir -p "$repo"
utf16le "token: $stripe" > "$repo/d.txt"
git -C "$repo" add d.txt
assert_exit 1 "(d) staged UTF-16 file with a fake key exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "d.txt:1: key-prefix" "(d) UTF-16 finding at its line"
assert_out_lacks "$stripe" "(d) full key is not printed"

# (e) a textconv driver that prints nothing cannot hide content
repo=$(new_repo)
git -C "$repo" config diff.hide.textconv true
stage "$repo" .gitattributes "$(printf '*.tc diff=hide\n')"
stage "$repo" e.tc "$(printf '%s\n' "$stripe")"
assert_exit 1 "(e) file behind a textconv driver exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "e.tc:1: key-prefix" "(e) textconv finding names the file"

# (f) a regular file that replaced a symlink (type change) is scanned
repo=$(new_repo)
stage "$repo" target.txt "$(printf 'plain\n')"
ln -s target.txt "$repo/link.txt"
git -C "$repo" add link.txt
git -C "$repo" commit -q -m seed
rm "$repo/link.txt"
printf '%s\n' "$stripe" > "$repo/link.txt"
git -C "$repo" add link.txt
assert_exit 1 "(f) type change symlink to regular file with a fake key exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "link.txt:1: key-prefix" "(f) type-change finding names the file"

finish
