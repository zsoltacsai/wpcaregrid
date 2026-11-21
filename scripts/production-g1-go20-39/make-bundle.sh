#!/usr/bin/env bash
# WP CareGrid GO-20.39: build the flat toolkit bundle (workstation, read-only on the repo). Output: <out>.tar and <out>.tar.sha256.
#   make-bundle.sh <output-prefix>      (refuses to overwrite)
set -u; set -o noclobber
HERE="$(cd "$(dirname "$0")" && pwd)"
out="${1:?output prefix}"
[ ! -e "$out.tar" ] && [ ! -e "$out.tar.sha256" ] || { echo "BUNDLE=STOP;CODE=output_exists"; exit 1; }
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
for f in g1-cold-archive.sh g1-restore-datadir.sh t8-timers.sh pkg-state.sh g1-start-console-a.sh install-toolkit.sh rebaseline-prescript.py; do install -m 0500 "$HERE/$f" "$tmp/$f" || { echo "BUNDLE=STOP;CODE=missing_$f"; exit 1; }; done
install -m 0500 "$HERE/s7b/s7b-containment.sh" "$tmp/s7b-containment.sh"
for f in go20-38-pre-script-v3c.sh go20-38-pre-gate-v4a.sh go20-38-pre-thresholds-v1.txt go20-38-post-script-v2.py go20-38-post-gate-v2.sh go20-37-collector-v2.2.sh; do install -m 0500 "$HERE/frozen/$f" "$tmp/$f"; done
( cd "$tmp" && sha256sum -- * > MANIFEST.sha256 )
tar --sort=name --mtime='2026-01-01 00:00Z' --owner=0 --group=0 --numeric-owner -C "$tmp" -cf "$out.tar" . || { echo "BUNDLE=STOP;CODE=tar_failed"; exit 1; }
sha256sum "$out.tar" | cut -d' ' -f1 > "$out.tar.sha256"
echo "BUNDLE=PASS;TAR=$out.tar;SHA256=$(cat "$out.tar.sha256");FILES=$(wc -l < "$tmp/MANIFEST.sha256")"
