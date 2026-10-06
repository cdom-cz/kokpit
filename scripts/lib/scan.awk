# scan.awk - sensitive-content scanner core.
#
# Input (stdin or file): TSV rows "path<TAB>line<TAB>text", produced by diff2tsv.awk or by git grep.
# Output: one "path:line: rule [masked]" line per finding on stdout; exit 1 if any finding, else 0.
# The masked value shows at most the first 2 characters plus the total length, never the full match.
#
# Environment: CS_ALLOWLIST = path of the allowlist file ("rule ;; path-ERE ;; matched-value-ERE", third
# field optional, "*" = any). Set by the driver; a missing file means no exemptions.
#
# mawk constraints (Ubuntu runners use mawk): POSIX ERE only, no anchors inside groups, no quantified groups
# with intervals, short regex literals. Boundary checks live in scan_re(), not in the regex. Run with
# LC_ALL=C so [A-Z] is byte-ASCII.

function mask(s,   n) { n = length(s); if (n <= 4) return "****"; return substr(s, 1, 2) "*** (" n " chars)" }
function allowed(rule, p, val,   i) {
  for (i = 1; i <= na; i++)
    if (ar[i] == rule && (ap[i] == "*" || p ~ ap[i]) && (av[i] == "*" || val ~ av[i])) return 1
  return 0
}
function hit(rule, val) { if (allowed(rule, path, val)) return; printf "%s:%s: %s [%s]\n", path, ln, rule, mask(val); found = 1 }
# E-mail domains that are safe by construction (RFC 2606 / 6761): example.* and reserved TLDs.
function email_ok(addr,   dom) {
  dom = tolower(substr(addr, index(addr, "@") + 1))
  if (dom ~ /(^|\.)example\.(com|org|net)$/) return 1
  if (dom ~ /\.(test|invalid|localhost|example)$/) return 1
  return dom == "localhost"
}
# Leftmost non-overlapping matches of core regex `re`; accept only if the char before does not match `lbad`
# and the char after does not match `rbad` (boundaries in code - mawk panics on anchors inside groups).
function scan_re(rule, re, lbad, rbad, s,   off, rest, tok, st, en, cb, ca, cn, ok) {
  off = 1
  while (off <= length(s)) {
    rest = substr(s, off); if (!match(rest, re)) break
    st = off + RSTART - 1; en = st + RLENGTH; tok = substr(s, st, RLENGTH)
    cb = (st > 1) ? substr(s, st - 1, 1) : ""; ca = (en <= length(s)) ? substr(s, en, 1) : ""
    cn = (en + 1 <= length(s)) ? substr(s, en + 1, 1) : ""
    ok = 1
    if (cb != "" && lbad != "" && cb ~ lbad) ok = 0
    if (ca != "" && rbad != "" && ca ~ rbad) ok = 0
    if (ca == "." && cn ~ /[0-9]/ && rbad != "") ok = 0       # "1.2.3.4.5" / "20260001.2" are version-like
    if (ok) { handle(rule, tok); off = en } else off = st + 1
  }
}
# Rule-specific acceptance checks are added here by later plans; the generic branch reports every match.
function handle(rule, tok) {
  if (rule == "email") { if (!email_ok(tok)) hit(rule, tok) }
  else hit(rule, tok)
}
BEGIN {
  FS = "\t"; found = 0; na = 0; al = ENVIRON["CS_ALLOWLIST"]
  if (al != "") {
    while ((getline line < al) > 0) {
      if (line ~ /^[ \t]*(#|$)/) continue
      n = split(line, f, / ;; /); na++; ar[na] = f[1]; ap[na] = (n >= 2 ? f[2] : "*"); av[na] = (n >= 3 ? f[3] : "*")
    }
    close(al)
  }
}
{
  path = $1; ln = $2; text = $0; sub(/^[^\t]*\t[^\t]*\t/, "", text)
  scan_re("email",        "[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z][A-Za-z]+", "[A-Za-z0-9._%+-]", "", text)
  scan_re("key-prefix",   "(sk|rk|pk)_live_[A-Za-z0-9]{8,}|whsec_[A-Za-z0-9]{8,}", "[A-Za-z0-9_]", "", text)
  scan_re("key-prefix",   "plink_[A-Za-z0-9]{8,}|acct_[A-Za-z0-9]{8,}", "[A-Za-z0-9_]", "", text)
  scan_re("key-prefix",   "gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}", "[A-Za-z0-9_]", "", text)
  scan_re("key-prefix",   "AKIA[0-9A-Z]{16}", "[A-Za-z0-9_]", "", text)
}
END { exit(found ? 1 : 0) }
