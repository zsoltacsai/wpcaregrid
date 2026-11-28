#!/usr/bin/env python3
"""WP CareGrid GO-20.39: deterministic successor generator for the frozen pre-script (go20-38-pre-script-v3c.sh).
Allowed changes only: (1) allow-listed constants (BASELINE_BOOT_ID after a reboot, PIN_* hashes) and (2) the optional, fixed replacement of the
package-process block so that the resident unattended-upgrade-shutdown helper (state S) is not counted as package activity.
Everything else stays byte-identical; the exact diff and the new sha256 are printed, a reviewer verifies the diff, the Owner approves the hash.
  rebaseline-prescript.py --in <script> --out <new script> [--set NAME=VALUE ...] [--resident-helper-aware]
"""
import argparse, hashlib, re, sys
ALLOWED = {"BASELINE_BOOT_ID": r"[0-9a-f]{32}"}
for n in ("PIN_PROF_P_NEW", "PIN_PROF_R_NEW", "PIN_DROPIN_P", "PIN_DROPIN_R", "PIN_FRAGMENT_VENDOR", "PIN_FP_META_PROD", "PIN_FP_SHAPE_PROD", "PIN_FP_META_RESTORE", "PIN_FP_SHAPE_RESTORE"):
    ALLOWED[n] = r"[0-9a-f]{64}"
OLD_PKG_BLOCK = """# Package manager processes
PKG_CNT=0
for pkg_proc in apt apt-get dpkg unattended-upgr; do
  p_cnt=$(pgrep -c -x "$pkg_proc" 2>/dev/null || echo "0")
  if is_int "$p_cnt" && [ "$p_cnt" -gt 0 ]; then
    PKG_CNT=$((PKG_CNT + p_cnt))
  fi
done
"""
NEW_PKG_BLOCK = """# Package manager processes (GO-20.39: the resident unattended-upgrade-shutdown helper in state S is not package activity; any other match counts)
PKG_CNT=0
for pkg_proc in apt apt-get dpkg; do
  p_cnt=$(pgrep -c -x "$pkg_proc" 2>/dev/null || echo "0")
  if is_int "$p_cnt" && [ "$p_cnt" -gt 0 ]; then
    PKG_CNT=$((PKG_CNT + p_cnt))
  fi
done
for pkg_pid in $(pgrep -x unattended-upgr 2>/dev/null); do
  if ! is_int "$pkg_pid"; then PKG_CNT=$((PKG_CNT + 1)); continue; fi
  pkg_cmd=$(tr '\\0' ' ' < "/proc/$pkg_pid/cmdline" 2>/dev/null)
  pkg_st=$(awk '{ s=$0; sub(/^[^)]*\\) /, "", s); split(s, a, " "); print a[1] }' "/proc/$pkg_pid/stat" 2>/dev/null)
  case "$pkg_cmd" in
    *unattended-upgrade-shutdown*) [ "$pkg_st" = "S" ] || PKG_CNT=$((PKG_CNT + 1)) ;;
    *) PKG_CNT=$((PKG_CNT + 1)) ;;
  esac
done
"""
def main():
    ap = argparse.ArgumentParser(); ap.add_argument("--in", dest="src", required=True); ap.add_argument("--out", required=True); ap.add_argument("--set", action="append", default=[])
    ap.add_argument("--resident-helper-aware", action="store_true")
    a = ap.parse_args()
    if not a.set and not a.resident_helper_aware: sys.exit("REBASELINE=STOP;CODE=nothing_to_do")
    text = open(a.src, encoding="utf-8").read(); out = text; changes = []
    seen = set()
    for item in a.set:
        name, sep, value = item.partition("=")
        if not sep or name not in ALLOWED: sys.exit("REBASELINE=STOP;CODE=name_not_allowed:%s" % name[:40])
        if name in seen: sys.exit("REBASELINE=STOP;CODE=duplicate_name")
        seen.add(name)
        if not re.fullmatch(ALLOWED[name], value): sys.exit("REBASELINE=STOP;CODE=value_format:%s" % name)
        pat = re.compile(r'(?m)^(%s=")([0-9a-f]+)(")$' % re.escape(name))
        found = pat.findall(out)
        if len(found) != 1: sys.exit("REBASELINE=STOP;CODE=constant_not_unique:%s" % name)
        old = found[0][1]
        if old == value: sys.exit("REBASELINE=STOP;CODE=value_unchanged:%s" % name)
        out = pat.sub(lambda m: m.group(1) + value + m.group(3), out, count=1); changes.append((name, old, value))
    if a.resident_helper_aware:
        if out.count(OLD_PKG_BLOCK) != 1: sys.exit("REBASELINE=STOP;CODE=package_block_not_unique")
        out = out.replace(OLD_PKG_BLOCK, NEW_PKG_BLOCK, 1); changes.append(("PACKAGE_BLOCK", "frozen", "resident-helper-aware"))
    # no other byte may differ: undo the replacements and compare
    back = out
    if a.resident_helper_aware: back = back.replace(NEW_PKG_BLOCK, OLD_PKG_BLOCK, 1)
    for name, old, value in changes:
        if name != "PACKAGE_BLOCK": back = back.replace('%s="%s"' % (name, value), '%s="%s"' % (name, old), 1)
    if back != text: sys.exit("REBASELINE=STOP;CODE=unexpected_difference")
    try: open(a.out, "x", encoding="utf-8").write(out)
    except FileExistsError: sys.exit("REBASELINE=STOP;CODE=output_exists")
    print("REBASELINE=PASS;IN_SHA256=%s;OUT_SHA256=%s;CHANGED=%d" % (hashlib.sha256(text.encode()).hexdigest(), hashlib.sha256(out.encode()).hexdigest(), len(changes)))
    for name, old, value in changes: print("REBASELINE_CHANGE name=%s old=%s new=%s" % (name, old, value))
main()
