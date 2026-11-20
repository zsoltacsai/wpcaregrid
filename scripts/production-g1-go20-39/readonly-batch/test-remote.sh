#!/bin/bash
# Local test of remote-readonly.sh wrapper loop with a stub wrapper (no host, no real sudo): long output, non-zero rc, tr-noise filtering.
set -u; D=$(cd "$(dirname "$0")" && pwd); T=$(mktemp -d); trap 'rm -rf "$T"' EXIT; fail=0
cat > "$T/wrapper" <<'W'
#!/bin/sh
case "$1" in
  archive-verify) i=1; while [ $i -le 150 ]; do echo "AV_LINE_$i"; i=$((i+1)); done; echo "AV_LAST" ;;
  pkg-state) echo "PKG_STATE=CLEAR_RESIDENT_ONLY"; exit 7 ;;
  timers-status) echo "tr: range-endpoints of '+-\\012' are in reverse collating sequence order" >&2; echo "T8_PAUSED_MARKER=absent" ;;
  *) echo "OK_$1" ;;
esac
W
chmod +x "$T/wrapper"
printf '#!/bin/sh\nshift\nexec "$@"\n' > "$T/sudo"; chmod +x "$T/sudo"
out=$(WPCG_SUDO="$T/sudo" WPCG_WRAPPER="$T/wrapper" bash "$D/remote-readonly.sh" 2>&1)
chk() { if printf '%s\n' "$out" | grep -qxF "$2"; then echo "PASS $1"; else echo "FAIL $1"; fail=1; fi; }
chk "T1 long output not truncated (line 150)" "AV_LINE_150"
chk "T2 last line of long output kept" "AV_LAST"
chk "T3 long-output wrapper rc recorded 0" "RC_archive-verify=0"
chk "T4 non-zero wrapper rc recorded" "RC_pkg-state=7"
chk "T5 output of failing wrapper still printed" "PKG_STATE=CLEAR_RESIDENT_ONLY"
chk "T6 other actions unaffected" "RC_status=0"
printf '%s\n' "$out" | grep -q "^tr: range-endpoints" && { echo "FAIL T7 tr noise not filtered"; fail=1; } || echo "PASS T7 tr noise filtered"
chk "T8 batch completes" "BATCH_END"
exit $fail
