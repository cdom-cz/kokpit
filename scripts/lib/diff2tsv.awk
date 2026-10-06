# diff2tsv.awk - unified diff (git diff -U0 / git log -p -U0) -> "path<TAB>line<TAB>text" for added lines.
#
# Output contract: one row per added line; "path" is the post-image path (b/ prefix removed), "line" is the
# 1-based line number in the new file, "text" is the line without the leading "+". Deleted files and binary
# files produce no rows.
#
# Why hunk counts are tracked: inside a hunk an added line whose own text starts with "++" appears in the
# diff as "+++ ..." and would be mistaken for a file header (and could redirect later findings to a decoy
# path). The "@@ -a,b +c,d @@" counts tell the parser exactly how many body lines to read before the next
# header is allowed.
#
# Run with LC_ALL=C. POSIX awk only (BWK awk on macOS, mawk on Ubuntu).
BEGIN { OFS = "\t"; ro = 0; rn = 0; ln = 0; path = "" }
{
  if (ro > 0 || rn > 0) {
    c = substr($0, 1, 1)
    if (c == "+") { if (path != "") print path, ln, substr($0, 2); ln++; rn--; next }
    if (c == "-") { ro--; next }
    if (c == "\\") next
    if (c == " ") { ro--; rn--; ln++; next }
    ro = 0; rn = 0
  }
  if ($0 ~ /^@@ /) {
    match($0, /\+[0-9]+(,[0-9]+)?/); spec = substr($0, RSTART + 1, RLENGTH - 1)
    n = split(spec, a, ","); ln = a[1] + 0; rn = (n == 2) ? a[2] + 0 : 1
    match($0, /-[0-9]+(,[0-9]+)?/); spec = substr($0, RSTART + 1, RLENGTH - 1)
    n = split(spec, a, ","); ro = (n == 2) ? a[2] + 0 : 1
    next
  }
  if ($0 ~ /^\+\+\+ /) { p = substr($0, 5); sub(/\t.*$/, "", p); if (p == "/dev/null") path = ""; else { sub(/^b\//, "", p); path = p }; next }
  if ($0 ~ /^diff --git /) { path = ""; next }
}
