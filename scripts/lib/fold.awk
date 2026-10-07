# fold.awk - locale-free text fold for denylist matching (HYG-03, D-06).
#
# Contract: input any lines, run with LC_ALL=C; output exactly one line per input line. Every line is
# reduced to a canonical form, and the scanner applies the same fold to the term list and to the scanned
# path and text, so a term matches if and only if the folded forms match as fixed strings (byte-wise):
#   - ASCII A-Z becomes lower case (tolower under LC_ALL=C changes ASCII letters only);
#   - every letter of U+00C0 to U+017F (Latin-1 Supplement and Latin Extended-A, UTF-8) except the
#     multiplication sign (U+00D7) and the division sign (U+00F7) becomes its lower-case ASCII base
#     letter or letters (for example the accented i becomes i, the sharp s becomes ss, the ligature
#     ae becomes ae, the stroked l becomes l); upper and lower case forms map to the same result;
#   - every other byte is kept exactly as written (Greek, Cyrillic and other scripts compare as written,
#     including their case, and so do bytes that are not valid UTF-8).
# No locale, iconv or transliteration table of the system is involved, so macOS awk, mawk and gawk
# produce the same bytes. Source rules: ASCII only (octal escapes), every string literal well below 120
# characters (mawk rejects very long literals), POSIX awk only.
#
# Tables: one list of exactly 64 space-separated tokens per UTF-8 lead byte, one token per continuation
# byte from octal 200 to 277. Token "=" leaves the character unchanged. BEGIN checks the token counts
# and exits 2 on a mismatch.

# The 64 continuation bytes, octal 200 to 277, built from four literals of 16 escapes.
function build_cont() {
  cont = "\200\201\202\203\204\205\206\207\210\211\212\213\214\215\216\217"
  cont = cont "\220\221\222\223\224\225\226\227\230\231\232\233\234\235\236\237"
  cont = cont "\240\241\242\243\244\245\246\247\250\251\252\253\254\255\256\257"
  cont = cont "\260\261\262\263\264\265\266\267\270\271\272\273\274\275\276\277"
}

# addtab: register one lead byte with its 64-token list.
function addtab(lead, list,   t, n, i) {
  n = split(list, t, " ")
  if (n != 64) {
    print "fold.awk: table error, " n " tokens instead of 64" > "/dev/stderr"
    exit 2
  }
  nl++
  leads[nl] = lead
  first[nl] = ns + 1
  for (i = 1; i <= 64; i++) {
    if (t[i] == "=") continue
    ns++
    src[ns] = lead substr(cont, i, 1)
    dst[ns] = t[i]
  }
  last[nl] = ns
}

BEGIN {
  build_cont()

  # Lead octal 303: U+00C0 to U+00FF. Token = at U+00D7 and U+00F7.
  a = "a a a a a a ae c e e e e i i i i d n o o o o o = o u u u u y th ss "
  a = a "a a a a a a ae c e e e e i i i i d n o o o o o = o u u u u y th y"
  addtab("\303", a)

  # Lead octal 304: U+0100 to U+013F.
  a = "a a a a a a c c c c c c c c d d d d "
  a = a "e e e e e e e e e e g g g g g g g g h h h h "
  a = a "i i i i i i i i i i ij ij j j k k k l l l l l l l"
  addtab("\304", a)

  # Lead octal 305: U+0140 to U+017F.
  a = "l l l n n n n n n n n n o o o o o o oe oe "
  a = a "r r r r r r s s s s s s s s t t t t t t "
  a = a "u u u u u u u u u u u u w w y y y z z z z z z s"
  addtab("\305", a)
}

{
  s = tolower($0)
  for (k = 1; k <= nl; k++) {
    if (index(s, leads[k]) == 0) continue
    for (j = first[k]; j <= last[k]; j++)
      if (index(s, src[j]) > 0) gsub(src[j], dst[j], s)
  }
  print s
}
