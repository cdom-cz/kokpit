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
# True for a routable IPv4 address; private, loopback, link-local, documentation, CGNAT, benchmarking and
# multicast/reserved ranges are not sensitive. An octet above 255 is not an address at all.
function ipv4_public(ip,   o, a, b, c) {
  split(ip, o, "."); a = o[1] + 0; b = o[2] + 0; c = o[3] + 0
  if (o[1] + 0 > 255 || o[2] + 0 > 255 || o[3] + 0 > 255 || o[4] + 0 > 255) return 0
  if (a == 0 || a == 10 || a == 127) return 0                      # this-net, RFC1918, loopback
  if (a == 169 && b == 254) return 0                                # link-local
  if (a == 172 && b >= 16 && b <= 31) return 0                      # RFC1918
  if (a == 192 && b == 168) return 0                                # RFC1918
  if (a == 192 && b == 0 && c == 2) return 0                        # TEST-NET-1
  if (a == 198 && b == 51 && c == 100) return 0                     # TEST-NET-2
  if (a == 203 && b == 0 && c == 113) return 0                      # TEST-NET-3
  if (a == 100 && b >= 64 && b <= 127) return 0                     # CGNAT / Tailscale
  if (a == 198 && (b == 18 || b == 19)) return 0                    # benchmarking
  if (a >= 224) return 0                                            # multicast / reserved / broadcast
  return 1
}
# IBAN length by country (exact), so a random uppercase identifier cannot qualify by checksum luck.
function iban_len_ok(s,   cc) {
  cc = substr(s, 1, 2)
  return index(" CZ24 SK24 DE22 AT20 PL28 HU28 GB22 FR27 IT27 ES24 NL18 BE16 CH21 LU20 DK18 SE24 NO15 FI18 IE22 PT25 RO24 BG22 HR21 SI19 LT20 LV21 EE20 GR27 CY28 MT31 IS26 LI21 ", " " cc length(s) " ") > 0
}
# ISO 13616 mod 97, digit by digit (no big numbers: awk doubles lose precision).
function iban_valid(s,   i, ch, r, rearr, n, pos) {
  rearr = substr(s, 5) substr(s, 1, 4); r = 0; n = length(rearr)
  for (i = 1; i <= n; i++) {
    ch = substr(rearr, i, 1)
    if (ch ~ /[0-9]/) r = (r * 10 + ch) % 97
    else { pos = index("ABCDEFGHIJKLMNOPQRSTUVWXYZ", ch); if (pos == 0) return 0; r = (r * 100 + pos + 9) % 97 }
  }
  return r == 1
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
# Rule-specific acceptance checks; the generic branch reports every match.
function handle(rule, tok,   t, ip, n, w, k, m, q) {
  if (rule == "email") { if (!email_ok(tok)) hit(rule, tok) }
  else if (rule == "company-id") { t = tok; gsub(/[^0-9]/, "", t); hit(rule, t) }
  else if (rule == "public-ip") {
    match(tok, /[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/); ip = substr(tok, RSTART, RLENGTH)
    if (ipv4_public(ip)) hit(rule, ip)
  }
  else if (rule == "cz-account") {        # loose core regex, shape check here: [prefix-]number/bank
    split(tok, w, "/"); m = split(w[1], q, "-")
    if (m == 1 && length(q[1]) >= 6 && length(q[1]) <= 10) hit(rule, tok)
    else if (m == 2 && length(q[1]) >= 2 && length(q[1]) <= 6 && length(q[2]) >= 2 && length(q[2]) <= 10) hit(rule, tok)
  } else if (rule == "iban") {            # run of [A-Z0-9 ]; longest whole-word prefix of exact country length that passes mod 97
    n = split(tok, w, " ")
    for (k = n; k >= 1; k--) { t = ""; for (m = 1; m <= k; m++) t = t w[m]; if (iban_len_ok(t) && iban_valid(t)) { hit(rule, t); break } }
  } else if (rule == "home-path") {       # placeholder account names are not personal
    if (tok !~ /\/(Users|home)\/(example|user|username|name|you|runner|vagrant|ubuntu|www-data|ddev|Shared)\//) hit(rule, tok)
  } else hit(rule, tok)
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
  scan_re("company-id",   "[0-9]{8}", "[0-9A-Za-z_./-]", "[0-9A-Za-z_-]", text)
  scan_re("public-ip",    "[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}", "[0-9A-Za-z_.-]", "[0-9A-Za-z_-]", text)
  scan_re("iban",         "[A-Z][A-Z][0-9][0-9][A-Z0-9 ]+", "[A-Za-z0-9]", "", text)
  scan_re("cz-account",   "[0-9][0-9-]*[0-9]/[0-9]{4}", "[0-9A-Za-z_./-]", "[0-9A-Za-z_-]", text)
  scan_re("hosting-host", "[A-Za-z0-9.-]+\\.(zerops\\.app|amazonaws\\.com|r2\\.cloudflarestorage\\.com)", "", "[A-Za-z0-9_-]", text)
  scan_re("hosting-host", "[A-Za-z0-9.-]+\\.(backblazeb2\\.com|wasabisys\\.com|digitaloceanspaces\\.com|linodeobjects\\.com)", "", "[A-Za-z0-9_-]", text)
  scan_re("key-prefix",   "(sk|rk|pk)_live_[A-Za-z0-9]{8,}|whsec_[A-Za-z0-9]{8,}", "[A-Za-z0-9_]", "", text)
  scan_re("key-prefix",   "plink_[A-Za-z0-9]{8,}|acct_[A-Za-z0-9]{8,}", "[A-Za-z0-9_]", "", text)
  scan_re("key-prefix",   "gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}", "[A-Za-z0-9_]", "", text)
  scan_re("key-prefix",   "AKIA[0-9A-Z]{16}", "[A-Za-z0-9_]", "", text)
  scan_re("home-path",    "/(Users|home)/[A-Za-z0-9._-]+/", "", "", text)
}
END { exit(found ? 1 : 0) }
