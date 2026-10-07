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

# --- --all, --history and explicit files ---------------------------------------------------------------

short() { git -C "$1" rev-parse --short=7 "$2"; }

# three fakes hidden three different ways, each distinct so the output names every file
hidden_trio() {  # hidden_trio <repo>: write and stage a -diff file, a NUL file and a UTF-16 file
  stage "$1" .gitattributes "$(printf '*.dat -diff\n')"
  stage "$1" g1.dat "$(printf 'one\n%s\n' "$stripe")"
  printf 'bin\000%s\n' "$ghp" > "$1/g2.txt"
  utf16le "key: $aws" > "$1/g3.txt"
  git -C "$1" add g2.txt g3.txt
}

stripe=$(fake_stripe_key)
ghp=$(fake_ghp)
aws=$(fake_aws)

# (g) --all reads every committed file in full, whatever git thinks of it
repo=$(new_repo)
hidden_trio "$repo"
git -C "$repo" commit -q -m seed
assert_exit 1 "(g) --all exits 1 on committed hidden fakes" in_dir "$repo" "$SCRIPT" --all
assert_out_has "g1.dat:2: key-prefix" "(g) --all names the -diff file"
assert_out_has "g2.txt:1: key-prefix" "(g) --all names the NUL file"
assert_out_has "g3.txt:1: key-prefix" "(g) --all names the UTF-16 file"
assert_out_lacks "$stripe" "(g) --all never prints a full key"

# (h) --history finds the same content after it was deleted; --all on the clean tree passes
repo=$(new_repo)
hidden_trio "$repo"
git -C "$repo" commit -q -m seed
sha_h=$(short "$repo" HEAD)
git -C "$repo" rm -q g1.dat g2.txt g3.txt
git -C "$repo" commit -q -m drop
assert_exit 1 "(h) --history exits 1 on deleted hidden fakes" in_dir "$repo" "$SCRIPT" --history
assert_out_has "g1.dat:2@${sha_h}: key-prefix" "(h) -diff file named with the adding commit"
assert_out_has "g2.txt:1@${sha_h}: key-prefix" "(h) NUL file named with the adding commit"
assert_out_has "g3.txt:1@${sha_h}: key-prefix" "(h) UTF-16 file named with the adding commit"
assert_exit 0 "(h) --all on the same repository exits 0" in_dir "$repo" "$SCRIPT" --all

# (i) a line present only in a merge result is found, naming the merge commit
build_merge() {  # build_merge <repo> <extra-line-or-empty>
  printf 'base\n' > "$1/m.txt"
  git -C "$1" add m.txt
  git -C "$1" commit -q -m base
  git -C "$1" checkout -q -b feat
  stage "$1" f.txt "$(printf 'feature\n')"
  git -C "$1" commit -q -m feature
  git -C "$1" checkout -q main
  stage "$1" o.txt "$(printf 'other\n')"
  git -C "$1" commit -q -m other
  git -C "$1" merge -q --no-ff --no-commit feat
  if [ -n "$2" ]; then printf '%s\n' "$2" >> "$1/m.txt"; git -C "$1" add m.txt; fi
  git -C "$1" commit -q -m merge
}
repo=$(new_repo)
build_merge "$repo" "$stripe"
sha_i=$(short "$repo" HEAD)
assert_exit 1 "(i) --history exits 1 on an evil merge" in_dir "$repo" "$SCRIPT" --history
assert_out_has "m.txt:2@${sha_i}: key-prefix" "(i) finding names the merge commit"
repo=$(new_repo)
build_merge "$repo" ""
assert_exit 0 "(i) a merge of clean branches exits 0" in_dir "$repo" "$SCRIPT" --history

# (j) a fake moved out of a D-05 exempt path by a rename is found, staged and in history
repo=$(new_repo)
stage "$repo" scripts/lib/scan.awk "$(printf 'x\n%s\n' "$stripe")"
assert_exit 0 "(j) the fake in the exempt path is not reported" in_dir "$repo" "$SCRIPT"
git -C "$repo" commit -q -m exempt
git -C "$repo" mv scripts/lib/scan.awk moved.txt
assert_exit 1 "(j) staged rename out of the exempt path exits 1" in_dir "$repo" "$SCRIPT"
assert_out_has "moved.txt:2: key-prefix" "(j) staged finding names the new path"
git -C "$repo" commit -q -m rename
sha_j=$(short "$repo" HEAD)
assert_exit 1 "(j) --history exits 1 after the rename" in_dir "$repo" "$SCRIPT" --history
assert_out_has "moved.txt:2@${sha_j}: key-prefix" "(j) history names the rename commit"

# (k) explicit operands are read in full: NUL and UTF-16 operands are scanned, empty ones add nothing
repo=$(new_repo)
printf 'bin\000%s\n' "$stripe" > "$repo/k1.bin"
utf16le "token: $ghp" > "$repo/k2.txt"
: > "$repo/k3.txt"
assert_exit 1 "(k) NUL and UTF-16 operands with fakes exit 1" in_dir "$repo" "$SCRIPT" k1.bin k2.txt k3.txt
assert_out_has "k1.bin:1: key-prefix" "(k) NUL operand reported"
assert_out_has "k2.txt:1: key-prefix" "(k) UTF-16 operand reported"
assert_out_lacks "skipping" "(k) no operand is skipped"
assert_exit 0 "(k) an empty operand alone exits 0" in_dir "$repo" "$SCRIPT" k3.txt
assert_out_lacks "skipping" "(k) the empty operand prints no skip notice"

# (l) binary content without any rule shape adds no finding
repo=$(new_repo)
png="$repo/p.png"
printf '\211PNG\r\n\032\n\000\000\000\000IHDR' > "$png"
git -C "$repo" add p.png
assert_exit 0 "(l) PNG-style header staged exits 0" in_dir "$repo" "$SCRIPT"
git -C "$repo" commit -q -m png
assert_exit 0 "(l) PNG-style header --all exits 0" in_dir "$repo" "$SCRIPT" --all
assert_exit 0 "(l) PNG-style header as an operand exits 0" in_dir "$repo" "$SCRIPT" p.png

# (m) --all is read-only: index, object database and the temporary directory are untouched
repo=$(new_repo)
hidden_trio "$repo"
git -C "$repo" commit -q -m seed
priv="$TEST_TMP/priv-tmp"
mkdir -p "$priv"
sum_before=$(cksum < "$repo/.git/index")
objs_before=$(git -C "$repo" count-objects -v)
assert_exit 1 "(m) --all with findings runs under a private TMPDIR" in_dir "$repo" env TMPDIR="$priv" "$SCRIPT" --all
assert_eq "(m) the index checksum is unchanged" "$sum_before" "$(cksum < "$repo/.git/index")"
assert_eq "(m) the object database is unchanged" "$objs_before" "$(git -C "$repo" count-objects -v)"
assert_eq "(m) the private TMPDIR is empty after findings" "0" "$(count_entries "$priv")"
git -C "$repo" rm -q g1.dat g2.txt g3.txt
git -C "$repo" commit -q -m clean
assert_exit 0 "(m) --all on a clean tree runs under a private TMPDIR" in_dir "$repo" env TMPDIR="$priv" "$SCRIPT" --all
assert_eq "(m) the private TMPDIR is empty after a clean run" "0" "$(count_entries "$priv")"

finish
