#!/usr/bin/env bash
# WP CareGrid GO-20.39: install the verified toolkit bundle into a NEW root-only directory. Root console, Owner GO required.
#   install-toolkit.sh --bundle <tar> --sha <64 hex of the tar>
# Verifies the bundle hash, rejects unsafe member names, extracts into /root/wpcg-g1-start-toolkit (must not exist), checks MANIFEST.sha256
# and the frozen approved hashes, then makes the directory read-only. No overwrite, no deletion, nothing executed from the bundle.
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C
unset BASH_ENV ENV CDPATH IFS 2>/dev/null
set -u; set -o noclobber
ROOT_UID_EXPECT="0"
TOOLKIT="/root/wpcg-g1-start-toolkit"
stop() { echo "TOOLKIT_INSTALL=STOP;CODE=$1"; exit 1; }
[ "$(id -u)" = "$ROOT_UID_EXPECT" ] || stop not_root
[ "$#" -eq 4 ] && [ "$1" = "--bundle" ] && [ "$3" = "--sha" ] || stop usage
bundle="$2"; want="$4"
[[ "$want" =~ ^[0-9a-f]{64}$ ]] || stop sha_format
case "$bundle" in /*) ;; *) stop bundle_not_absolute ;; esac
[ -f "$bundle" ] && [ ! -L "$bundle" ] || stop bundle_invalid
[ "$(sha256sum -- "$bundle" | cut -d' ' -f1)" = "$want" ] || stop bundle_hash
[ ! -e "$TOOLKIT" ] && [ ! -L "$TOOLKIT" ] || stop toolkit_exists
names=$(tar -tf "$bundle" 2>/dev/null) || stop tar_unreadable
bad=$(printf '%s\n' "$names" | grep -vE '^(\./|\./[A-Za-z0-9._-]+)$' | head -n 1); [ -z "$bad" ] || stop unsafe_member_name
! tar -tvf "$bundle" 2>/dev/null | grep -qE '^[^-d]' || stop non_regular_member
mkdir -m 0700 -- "$TOOLKIT" || stop mkdir_failed
tar -C "$TOOLKIT" -xf "$bundle" --no-same-owner --no-same-permissions 2>/dev/null || stop extract_failed
( cd "$TOOLKIT" && sha256sum -c --quiet --strict MANIFEST.sha256 >/dev/null 2>&1 ) || stop manifest_mismatch
chk() { [ "$(sha256sum -- "$TOOLKIT/$1" | cut -d' ' -f1)" = "$2" ] || stop "frozen_hash_$1"; }
chk go20-38-pre-script-v3c.sh 4da0c13117320b946d6cad4a4df905310e9a111119fb5519ae312985cc9e8282
chk go20-38-pre-gate-v4a.sh 1780fd1e30800b6ca6a0799aa3e94b1b1f105a549e293d84c858a30afa9ef326
chk go20-38-pre-thresholds-v1.txt d5a029bda6f51c97d73f99180a864307b8a95ddbd8a4ffd4780b10565bf57260
chk go20-38-post-script-v2.py 14c875db52a4cc910ad04f32a5b7033bfedc6f9ce301d89de7f381609fa122a4
chk go20-38-post-gate-v2.sh 7be4b22aa01da7f5bbae6ba41854c85a4db5dbdd6a264d8113be43150e3910fd
chk go20-37-collector-v2.2.sh 25c985276f1016eabe8a0338caaecc1134c38999d84180170308a76ea9f54956
chk s7b-containment.sh 924b2f9634625629e757231fda2ebaed32f1a203485662116e5ca09e4cfd60a6
chmod 0500 "$TOOLKIT"/*.sh "$TOOLKIT"/*.py 2>/dev/null; chmod 0400 "$TOOLKIT"/MANIFEST.sha256 "$TOOLKIT"/*.txt 2>/dev/null; chmod 0500 "$TOOLKIT"
echo "TOOLKIT_INSTALL=PASS;DIR=$TOOLKIT;BUNDLE_SHA256=$want;MANIFEST_SHA256=$(sha256sum -- "$TOOLKIT/MANIFEST.sha256" | cut -d' ' -f1)"
