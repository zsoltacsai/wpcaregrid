#!/bin/bash
# GO-20.37 Phase B/C evidence collector v2.2. DESIGN PROPOSAL: NOT AUTHORIZED TO RUN ON ANY HOST.
# Intended use: Owner-run on the root console, after Moody's review and the Owner's approval of this exact file hash.
# v2.2 = v2.1 + remediation for sort syntax (-t '=') and empty AACACHE guard; see CHANGES: PID 1 journal text is classified by anchored
# templates and never printed; optional sections 7 (backup metadata) and 8 (sessions/sudo) are REMOVED from this file.
# Read-only: no writes, no service/unit/AppArmor/audit/journald/MySQL changes, no reset-failed, no daemon-reload,
# no restore, no initialize, no database or datadir FILE CONTENT access (metadata only: stat/find), the MySQL error
# logs are NOT opened, no backup content is read. Side effects: read-time atime updates (journal files, unit files,
# profile files, directory listings) and, for `systemctl show`, a possible in-memory unit load by PID 1 (nothing is
# started or enabled). Output: counts, states, timestamps (UTC), fixed tokens, relative names for datadir entries that
# match a MySQL-internal name whitelist (nested/odd names are printed as <nested>/<other>), template class tokens for
# systemd-authored lines (never raw text). Raw denial names/paths, IPs, user names, command lines, backup names and
# file contents are never printed. The final sed mask is only a BACKSTOP for paths, IPv4/IPv6 and the hostname; the
# defence is that no source prints raw text (skim before pasting).
# SCRIPT_SHA256 below is SELF-ATTESTED by the script (a modified script could print any value). The real binding between
# the approved hash and what ran is the root-side pre-run hash check of the root-owned copy plus the printed out_sha256
# of the output file (see go20-37-collector-v2-CHANGES.md delivery block).
# Privilege markers: [ROOT] needs root; [DEPLOY-OK] would also work as 'deploy' if its group membership allows.
# This file is root-only by guard; no deploy form is provided.
# Run it ONLY as: env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C bash --noprofile --norc <file>
# (BASH_ENV/ENV are read by bash BEFORE the first line of this file runs, so the unsets below are defence in depth only).
set +x +v +e; umask 077
unset BASH_XTRACEFD PS4 BASH_ENV ENV
export LANG=C LC_ALL=C TZ=UTC PATH=/usr/sbin:/usr/bin:/sbin:/bin
if [ "$(id -u)" != 0 ]; then echo "GO2037_COLLECTOR=STOP;CODE=not_root"; exit 3; fi
# bash >= 5.1 guard preserved (all programs loaded via in-memory variable assignment; zero heredocs, zero temp files).
BV="${BASH_VERSINFO[0]:-0}.${BASH_VERSINFO[1]:-0}"
if [ "${BASH_VERSINFO[0]:-0}" -lt 5 ] || { [ "${BASH_VERSINFO[0]:-0}" -eq 5 ] && [ "${BASH_VERSINFO[1]:-0}" -lt 1 ]; }; then
  echo "GO2037_COLLECTOR=STOP;CODE=bash_lt_5_1;BASH=$BV"; exit 3
fi
SELF="$0"
# ---- fixed constants (all paths fixed; no argument, no environment input) ----
P=mysql@wpcaregrid-production.service
R=mysql@wpcaregrid-production-restore.service
PD=/var/lib/mysql-wpcaregrid-production
RD=/var/lib/mysql-wpcaregrid-production-restore
PROFDIR=/etc/apparmor.d
AAPROFILES=/sys/kernel/security/apparmor/profiles
AAPARAMS=/sys/module/apparmor/parameters
AACACHE=/var/cache/apparmor
PROCSYS=/proc/sys
UNITRUN=/run/systemd/units
BOOTIDFILE=/proc/sys/kernel/random/boot_id
PEND_ISO=2026-11-09T20:34:16Z; PEND_FRAC=.882991     # production init end marker 2026-11-09T20:34:16.882991Z
REND_ISO=2026-11-12T15:41:18Z; REND_FRAC=.109566     # restore init end marker   2026-11-12T15:41:18.109566Z
# journal look-back: the current boot started 2026-11-05 21:52Z (v3 looked back only to 2026-11-09). Counts "pre_1109" are
# reported separately so they stay comparable with the v3 numbers (which cover 2026-11-09 onward).
SINCE=2026-11-05
PAR=2026-11-09
# denial windows (UTC, compared as 19-char ISO strings, inclusive)
W1a=2026-11-09T00:00:00; W1b=2026-11-09T20:35:16     # 11-09 init attempts (up to init end marker + 60 s)
W2a=2026-11-12T15:30:00; W2b=2026-11-12T15:42:18     # 11-12 restore init (up to its init end marker + 60 s)
W3a=2026-11-12T15:53:07; W3b=2026-11-12T15:53:10     # 11-12 failed production start (second-inclusive)
# values observed by GO-20.36 evidence v3 (continuity checks only; a mismatch is an anomaly marker, never a pass)
V3_HOST_ID=71eaa186367e
V3_BOOT_ID=cf8eed31d9b2464cb33be129ec40df1d
V3_DENIED_WPCG=8; V3_DENIED_MYSQLD=94; V3_DENIED_ALL=146; V3_STATUS=8; V3_ENTRIES=177
# pinned hashes from docs/PRODUCTION_G1_MYSQL_GO54A_APPARMOR_HOTFIX.md and scripts/production-g1-mysql-manifest.json
PROF_P_OLD=9a40053158880f0e6baf0db06431587651701487910634015e712e878dcfe13d
PROF_P_NEW=64f1bd60ba0b8626978d79951179bb9c2484df1ce79981e567a7a41e057ea73a
PROF_R_OLD=ce56847e98f23b45590aaf55ad069df89f97dc12b672c2a289bb0fa7fb923b3e
PROF_R_NEW=b612b2d913d4d8a178707516d8581724f2c003c5e8076f12e7b6930930c4fc32
DROPIN_P=644e98059d68462b66a575034b5fdfbb4dbc037569f2970ab7dfa7bdccecc017
DROPIN_R=d5df1ab7132248defe4c529afedb7517d002da73295b98ad87aa50a6ffc9e8ad
# v3 "15 newest datadir entries" lines (mtime ctime type name; '/' shown as '>') for the continuity check
V3NEW15='2026-11-12+15:53:09.0062332050 2026-11-12+15:53:09.0062332050 f #innodb_redo>#ib_redo33_tmp
2026-11-12+15:53:09.0182332470 2026-11-12+15:53:09.0182332470 f #innodb_redo>#ib_redo34_tmp
2026-11-12+15:53:09.0312332910 2026-11-12+15:53:09.0312332910 f #innodb_redo>#ib_redo35_tmp
2026-11-12+15:53:09.0442333360 2026-11-12+15:53:09.0442333360 d #innodb_redo
2026-11-12+15:53:09.0452333390 2026-11-12+15:53:09.0452333390 f #innodb_redo>#ib_redo36_tmp
2026-11-12+15:53:09.8972362600 2026-11-12+15:53:09.8972362600 f binlog.000001
2026-11-12+15:53:09.9017980160 2026-11-12+15:53:09.9017980160 f ib_buffer_pool
2026-11-12+15:53:09.9042362840 2026-11-12+15:53:09.9042362840 f #ib_16384_0.dblwr
2026-11-12+15:53:09.9062362910 2026-11-12+15:53:09.9062362910 f mysql.ibd
2026-11-12+15:53:09.9062362910 2026-11-12+15:53:09.9062362910 f undo_001
2026-11-12+15:53:09.9062362910 2026-11-12+15:53:09.9062362910 f undo_002
2026-11-12+15:53:09.9252363560 2026-11-12+15:53:09.9252363560 f #innodb_redo>#ib_redo5
2026-11-12+15:53:09.9282363670 2026-11-12+15:53:09.9282363670 f ibdata1
2026-11-12+15:53:10.4382381160 2026-11-12+15:53:10.4382381160 d #innodb_temp
2026-11-12+15:53:10.4542381710 2026-11-12+15:53:10.4542381710 d'

# ---- helpers (v3 conventions) ----
T() { local s=$1; shift; timeout -k 2 "$s" "$@" </dev/null; }
F=0; SF=0
fail() { echo "READ_FAILED:$1 rc=$2"; F=$((F+1)); }
Rc() { local n=$1 s=$2 o rc; shift 2; o=$(T "$s" "$@" 2>&1); rc=$?; printf '%s\n' "$o" | cut -c1-170 | head -n 60; [ $rc -eq 0 ] || fail "$n" $rc; }
sb() { SF=$F; echo "SEC $1 BEGIN $2"; }
se() { echo "SEC $1 END failed=$((F-SF))"; }
isnum() { case "$1" in ''|*[!0-9]*) return 1;; esac; return 0; }
# journal stream: records, then a @@RC=<rc> marker line (rc of journalctl itself)
JR() { local s=$1; shift; T "$s" journalctl "$@" --no-pager -o short-iso-precise 2>&1; echo "@@RC=$?"; }
# metadata hash line for a unit/profile definition file; never prints content
fmeta() { # role path pinvar(optional list of "label=hash")
  local role=$1 p=$2 h rc pin lab st base; shift 2
  base=$(basename -- "$p" 2>/dev/null)
  case "$base" in ''|*[!A-Za-z0-9_.@+-]*) base="<odd>";; esac
  case "$p" in
    *..*) echo "deffile role=$role base=<dotdot_path> NOT_READ"; F=$((F+1)); return;;
    /etc/systemd/system/*|/usr/lib/systemd/system/*|/lib/systemd/system/*|/run/systemd/system/*|/etc/apparmor.d/*) ;;
    *) echo "deffile role=$role base=<unexpected_location> NOT_READ"; F=$((F+1)); return;;
  esac
  if [ -L "$p" ]; then echo "deffile role=$role base=$base kind=symlink NOT_FOLLOWED"; return; fi
  if [ ! -f "$p" ]; then echo "deffile role=$role base=$base kind=missing"; return; fi
  h=$(T 10 sha256sum -- "$p" 2>&1); rc=$?
  [ $rc -eq 0 ] || { fail "sha256_deffile" $rc; echo "deffile role=$role base=$base sha256=VOID"; return; }
  h=${h%% *}
  case "$h" in *[!0-9a-f]*|'') fail "sha256_format" 0; echo "deffile role=$role base=$base sha256=VOID"; return;; esac
  [ ${#h} -eq 64 ] || { fail "sha256_length" 0; echo "deffile role=$role base=$base sha256=VOID"; return; }
  pin=unpinned
  for lab in "$@"; do [ "${h}" = "${lab#*=}" ] && pin=${lab%%=*}; done
  [ $# -eq 0 ] || { [ "$pin" != unpinned ] || pin=NO_PIN_MATCH; }
  st=$(stat -c 'mode=%a owner=%U:%G size=%s mtime=%y ctime=%z birth=%w' "$p" 2>&1 | cut -c1-190)
  echo "deffile role=$role base=$base sha256=$h pin=$pin $st"
}
# datadir metadata summary (stat/find only; no file is opened). full=1 also prints top-15 and binlog rows.
ddsum() { # label dir thr_epoch full
  local lab=$1 dir=$2 thr=$3 full=$4 DL rc bp FM birth MYU MYG cnt FPF FPS CUR CC crc NC
  echo "-- datadir $lab"
  if [ ! -d "$dir" ]; then echo "DATADIR_$lab=ABSENT"; F=$((F+1)); return; fi
  echo "root_dir $(stat -c 'mode=%a owner=%U:%G mtime=%y ctime=%z birth=%w' "$dir" 2>&1 | cut -c1-190)"
  MYU=$(id -u mysql 2>/dev/null); MYG=$(id -g mysql 2>/dev/null)
  if ! isnum "$MYU" || ! isnum "$MYG"; then echo "MYSQL_USER_UNRESOLVED"; F=$((F+1)); MYU=-1; MYG=-1; fi
  bp=$(T 10 find "$dir" -maxdepth 0 -printf '%B@' 2>/dev/null)
  case "$bp" in [1-9][0-9]*) birth=1;; *) birth=0;; esac
  FM='%T@|%T+|%C@|%C+|'
  if [ $birth -eq 1 ]; then FM="${FM}%B@|"; else FM="${FM}0|"; fi
  FM="${FM}%y|%m|%U|%G|%n|%s|%P\n"
  DL=$(T 60 find "$dir" -xdev -printf "$FM" 2>&1); rc=$?
  [ $rc -eq 0 ] || fail "find_datadir_$lab" $rc
  # names containing control characters (e.g. newline) could split find records; any hit is a gate STOP
  CC=$(T 60 find "$dir" -xdev -name '*[[:cntrl:]]*' -printf x 2>/dev/null; echo "@$?")
  crc=${CC##*@}; CC=${CC%@*}; NC=${#CC}
  [ "$crc" = 0 ] || fail "find_ctrlnames_$lab" "$crc"
  echo "ctrl_char_names=$NC"
  cnt=$(printf '%s\n' "$DL" | grep -c .)
  echo "entries_total=$cnt birth_support=$birth (v3 entries_total=$V3_ENTRIES)"
  printf '%s\n' "$DL" | awk -F'|' -v thr="$thr" -v myu="$MYU" -v myg="$MYG" -v full="$full" "$AWK_DD"
  FPF=$(printf '%s\n' "$DL" | awk -F'|' 'NF>=12{n=$12; for(i=13;i<=NF;i++) n=n "|" $i; print n "|" $6 "|" $7 "|" $8 "|" $9 "|" $11 "|" $2 "|" $4 "|" $5}' | sort | sha256sum | cut -c1-64)
  FPS=$(printf '%s\n' "$DL" | awk -F'|' 'NF>=12{n=$12; for(i=13;i<=NF;i++) n=n "|" $i; print n "|" $6}' | sort | sha256sum | cut -c1-64)
  echo "FINGERPRINT_META_SHA256=$FPF   (name,type,mode,uid,gid,size,mtime,ctime,birth; atime excluded)"
  echo "FINGERPRINT_SHAPE_SHA256=$FPS  (name,type only)"
  if [ "$full" = 1 ]; then
    echo "-- 15 newest by ctime (ctime mtime birth_epoch type mode own size name)"
    printf '%s\n' "$DL" | awk -F'|' 'NF>=12' | sort -t'|' -k4,4 -k12,12 | tail -n 15 | awk -F'|' -v myu="$MYU" -v myg="$MYG" "$AWK_TOP"
    CUR=$(printf '%s\n' "$DL" | awk -F'|' 'NF>=12{n=$12; for(i=13;i<=NF;i++) n=n "|" $i; gsub(/\//,">",n); print $2, $4, $6, n}' | sort | tail -n 15 | awk '{print $1,$2,$3,$4}')
    if [ "$CUR" = "$(printf '%s\n' "$V3NEW15" | awk '{print $1,$2,$3,$4}')" ]; then echo "V3_NEWEST15_CONTINUITY=IDENTICAL"
    else echo "V3_NEWEST15_CONTINUITY=DIFFERS"; fi
  fi
}

# ---- awk programs (POSIX-awk only: no gawk extensions, no regex intervals; must work with mawk) ----
AWK_KA='function kv(s, k,   re) { re = " " k "=\"[^\"]*\""; if (match(s, re)) return substr(s, RSTART + length(k) + 3, RLENGTH - length(k) - 4); return "" }
function kw(s, k,   re) { re = " " k "=[A-Za-z0-9_]+"; if (match(s, re)) return substr(s, RSTART + length(k) + 2, RLENGTH - length(k) - 2); return "" }
function tok(v) { if (v == "") return "-"; if (length(v) <= 24 && v ~ /^[A-Za-z0-9_ ,.:-]+$/) { gsub(/ /, "+", v); return v } return "<odd>" }
function pcls(p) {
  if (p == "") return "-"
  if (p == "wpcaregrid-mysql-production") return "wpcg_prod"
  if (p == "wpcaregrid-mysql-production-restore") return "wpcg_restore"
  if (p ~ /^wpcaregrid-/) return "wpcg_other"
  if (p == "unconfined") return "unconfined"
  if (p == "mysqld" || p == "usr.sbin.mysqld" || p ~ /^\/usr\/sbin\/mysqld(\/\/.*)?$/) return "mysqld_default"
  return "other_profile"
}
function ccls(c) { if (c == "") return "-"; if (c == "mysqld" || c == "mysqld_safe" || c == "systemd" || c == "apparmor_parser" || c == "mysql" || c == "sh" || c == "bash") return c; return "<other>" }
function icls(i) { if (i == "") return "-"; if (i ~ /same as current/) return "same_as_current"; if (i ~ /fail/) return "failed"; return "other" }
function ncls(n) {
  if (n == "") return "-"
  if (substr(n, 1, 1) == "@") return "<abstract_unix>"
  if (n ~ /^\/run\/mysqld\/wpcaregrid-[A-Za-z0-9._-]+\.sock\.lock$/) return "<g1_sock_lock>"
  if (n ~ /^\/run\/mysqld\/wpcaregrid-[A-Za-z0-9._-]+\.sock$/) return "<g1_sock>"
  if (n ~ /^\/run\/mysqld\/wpcaregrid-[A-Za-z0-9._-]+\.pid$/) return "<g1_pid>"
  if (n ~ /^\/run\/mysqld\/wpcaregrid-/) return "<g1_run_other>"
  if (n ~ /^\/run\/mysqld(\/.*)?$/) return "<run_mysqld_other>"
  if (n == "/run/systemd/notify") return "<run_systemd_notify>"
  if (n ~ /^\/run\/systemd(\/.*)?$/) return "<run_systemd_other>"
  if (n ~ /^\/var\/lib\/mysql-wpcaregrid-production(-restore)?(\/.*)?$/) return "<g1_datadir>"
  if (n ~ /^\/var\/tmp\/wpcaregrid-production(-restore)?(\/.*)?$/) return "<g1_tmpdir>"
  if (n ~ /^\/var\/log\/mysql\/wpcaregrid-/) return "<g1_log>"
  if (n ~ /^\/var\/lib\/mysql(\/.*)?$/) return "<legacy_datadir>"
  if (n ~ /^\/var\/log\/mysql(\/.*)?$/) return "<legacy_log>"
  if (n ~ /^\/etc\/mysql(\/.*)?$/) return "<etc_mysql>"
  if (n ~ /^\/sys\/devices\/system\/node(\/.*)?$/) return "<sysfs_node>"
  if (n ~ /^\/sys(\/.*)?$/) return "<sysfs>"
  if (n ~ /^\/proc(\/.*)?$/) return "<proc>"
  if (n ~ /^\/(usr|lib|lib64|bin|sbin)(\/.*)?$/) return "<system_bin_lib>"
  if (n ~ /^\/(tmp|var\/tmp)(\/.*)?$/) return "<tmp>"
  if (n ~ /^\/dev(\/.*)?$/) return "<dev>"
  if (n ~ /^\/etc(\/.*)?$/) return "<etc_other>"
  if (index(n, "wpcaregrid") > 0) return "<other_wpcg>"
  return "<other>"
}
function win(t) {
  if (t >= W1a && t <= W1b) return "W1_init_1109"
  if (t >= W2a && t <= W2b) return "W2_restore_init_1112"
  if (t >= W3a && t <= W3b) return "W3_start_155307_10"
  return "other"
}
/^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T/ {
  ts = $1; t19 = substr(ts, 1, 19); day = substr(ts, 1, 10)
  if (ts !~ /\+00:00$/) nonutc++
  n++; if (!first) first = ts; last = ts
  tr = ($0 ~ / kernel: /) ? "kernel" : "audit"
  tt[tr]++; rec[day SUBSEP tr]++
  if ($0 ~ /audit_lost=[1-9]|backlog limit exceeded|rate limit exceeded/) {
    lost++
    if (lostrows < 10) { lostrows++; kind = ($0 ~ /backlog limit exceeded/) ? "backlog_exceeded" : (($0 ~ /rate limit exceeded/) ? "rate_limit_exceeded" : "audit_lost"); print "LOST t=" substr(ts, 1, 26) " src=" tr " kind=" kind }
  }
  if (tr == "kernel" && match($0, /[0-9]+ (callbacks|messages) suppressed/)) {
    s = substr($0, RSTART, RLENGTH); split(s, sp, " "); lbl = "-"
    if (match($0, /kernel: [A-Za-z_]+:/)) lbl = tok(substr($0, RSTART + 8, RLENGTH - 9))
    ksn[day SUBSEP lbl]++; kss[day SUBSEP lbl] += sp[1]
    if (ksrows < 30) { ksrows++; print "KSUP t=" substr(ts, 1, 26) " label=" lbl " n=" sp[1] }
  }
  atype = ""
  if ($0 ~ / kernel: audit: type=/ || tr == "audit") {
    if (match($0, /type=[A-Za-z_0-9]+/)) atype = substr($0, RSTART + 5, RLENGTH - 5)
    else if (match($0, /audit\[[0-9]+\]: [A-Z_]+/)) { s = substr($0, RSTART, RLENGTH); sub(/^audit\[[0-9]+\]: /, "", s); atype = s }
    if (atype == "") atype = "UNPARSED"
    if (length(atype) > 24 || atype !~ /^[A-Za-z0-9_]+$/) atype = "<odd>"
    aty[tr SUBSEP atype]++
    if (atype == "SERVICE_START" || atype == "SERVICE_STOP" || atype == "1130" || atype == "1131") {
      u = ""; if (match($0, /unit=[^ '\''"]+/)) u = substr($0, RSTART + 5, RLENGTH - 5); sub(/\.service$/, "", u)
      uc = (u == "mysql@wpcaregrid-production") ? "g1_prod" : ((u == "mysql@wpcaregrid-production-restore") ? "g1_restore" : ((u == "mysql") ? "mysql_legacy" : "other"))
      rs = "-"; if (match($0, /res=[a-z]+/)) rs = tok(substr($0, RSTART + 4, RLENGTH - 4))
      if (uc == "other") svcother++; else svc[day SUBSEP atype SUBSEP uc SUBSEP rs]++
    }
  }
  if ($0 ~ /apparmor="DENIED"/) {
    pre = (day < PAR)
    if (pre) dapre++; else da++
    prof = kv($0, "profile"); comm = kv($0, "comm"); name = kv($0, "name"); w = win(t19); wp = pcls(prof)
    allc[day SUBSEP wp]++
    mysq = ($0 ~ /comm="mysqld"/)
    if (mysq) { if (pre) dmpre++; else dm++; mys[day SUBSEP wp]++ }
    if (w == "W3_start_155307_10") { w3a++; w3p[wp]++; if (mysq) w3m++ }
    pidv = kw($0, "pid"); if (pidv == "") pidv = "none"; if (!(pidv in pg)) pg[pidv] = ++npg
    hasw = ($0 ~ /wpcaregrid/)
    isg = (prof ~ /^wpcaregrid-mysql/)
    if (isg) {
      if (pre) dwpre++; else dw++
      dwt[tr]++; if (!fd) fd = ts; ld = ts; dseq++
      if (dseq <= 200) print "DENIAL n=" dseq " t=" substr(ts, 1, 26) " win=" w " src=" tr " pg=" pg[pidv] " prof=" pcls(prof) " op=" tok(kv($0, "operation")) " class=" tok(kv($0, "class")) " req=" tok(kv($0, "requested_mask")) " den=" tok(kv($0, "denied_mask")) " comm=" ccls(comm) " nm=" ncls(name) " ad=" ncls(kv($0, "addr")) " pa=" ncls(kv($0, "peer_addr")) " peer=" pcls(kv($0, "peer")) " fam=" tok(kv($0, "family")) " sty=" tok(kv($0, "sock_type")) " sig=" tok(kw($0, "signal"))
    } else if (hasw) {
      xw++
      if (xw <= 20) print "XDENIAL n=" xw " t=" substr(ts, 1, 26) " win=" w " src=" tr " pg=" pg[pidv] " prof=" pcls(prof) " op=" tok(kv($0, "operation")) " req=" tok(kv($0, "requested_mask")) " den=" tok(kv($0, "denied_mask")) " comm=" ccls(comm) " nm=" ncls(name) " pa=" ncls(kv($0, "peer_addr")) " peer=" pcls(kv($0, "peer"))
    }
    if (hasw) wcarry++
  }
  if ($0 ~ /apparmor="ALLOWED"/ && $0 ~ /profile="wpcaregrid-mysql/) aw++
  if ($0 ~ /apparmor="STATUS"/) {
    if (pre0 = (day < PAR)) stpre++; else st++
    stday[day]++
    if (strows < 60) { strows++; print "STATUS t=" substr(ts, 1, 26) " src=" tr " op=" tok(kv($0, "operation")) " name=" pcls(kv($0, "name")) " info=" icls(kv($0, "info")) " comm=" ccls(kv($0, "comm")) }
  }
}
/^@@RC=/ { rc = substr($0, 6) }
END {
  print "KA_RC=" rc
  print "KA_TOTAL n=" n+0 " kernel=" tt["kernel"]+0 " audit=" tt["audit"]+0 " first=" first " last=" last " nonutc=" nonutc+0
  for (k in rec) { split(k, p, SUBSEP); print "KA_DAY day=" p[1] " src=" p[2] " n=" rec[k] }
  print "DENIED_wpcg_profiles=" dw+0 " pre_1109=" dwpre+0 " via_kernel=" dwt["kernel"]+0 " via_audit=" dwt["audit"]+0 " first=" substr(fd, 1, 26) " last=" substr(ld, 1, 26)
  print "DENIED_comm_mysqld_any_profile=" dm+0 " pre_1109=" dmpre+0
  print "DENIED_all_profiles=" da+0 " pre_1109=" dapre+0
  print "DENIED_other_profile_carrying_wpcaregrid_string=" xw+0
  print "DENIED_any_profile_carrying_wpcaregrid_string=" wcarry+0
  print "ALLOWED_wpcg_profiles=" aw+0
  print "STATUS_records=" st+0 " pre_1109=" stpre+0
  print "W3_ALL=" w3a+0 " W3_MYSQLD=" w3m+0
  for (k in w3p) print "W3_PROF prof=" k " n=" w3p[k]
  for (k in allc) { split(k, p, SUBSEP); print "AGG day=" p[1] " prof=" p[2] " all=" allc[k] " mysqld=" mys[k]+0 }
  for (k in stday) print "STATUS_DAY day=" k " n=" stday[k]
  print "AUDIT_LOST_MARKERS=" lost+0
  for (k in ksn) { split(k, p, SUBSEP); print "KSUPD day=" p[1] " label=" p[2] " events=" ksn[k] " sum=" kss[k] }
  for (k in aty) { split(k, p, SUBSEP); print "ATYPE src=" p[1] " type=" p[2] " n=" aty[k] }
  for (k in svc) { split(k, p, SUBSEP); print "SVC day=" p[1] " type=" p[2] " unit=" p[3] " res=" p[4] " n=" svc[k] }
  print "SVC_OTHER_UNITS_TOTAL=" svcother+0
}'
AWK_JD='function tok(v) { if (v == "") return "-"; if (length(v) <= 24 && v ~ /^[A-Za-z0-9_ ,.:-]+$/) return v; return "<odd>" }
function fcls(x) {
  if (x == "mysql@wpcaregrid-production.service") return "g1_prod"
  if (x == "mysql@wpcaregrid-production-restore.service") return "g1_restore"
  if (x == "mysql.service") return "mysql_legacy"
  if (x ~ /^session-[0-9a-z]+\.scope$/) return "session_scope"
  if (x ~ /^user@[0-9]+\.service$/ || x ~ /^user-[0-9]+\.slice$/) return "user_slice"
  if (x ~ /wpcaregrid/) return "other_wpcg"
  return "other"
}
/^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T/ {
  ts = $1; day = substr(ts, 1, 10); n++; dn[day]++; if (!first) first = ts; last = ts
  if (match($0, /Suppressed [0-9]+ messages? from [^ ]+/)) {
    s = substr($0, RSTART, RLENGTH); split(s, a, " "); c = fcls(a[5])
    se[day SUBSEP c]++; ss[day SUBSEP c] += a[2]; sup++
    if (sup <= 40) print "JSUP t=" substr(ts, 1, 26) " n=" a[2] " from=" c
  }
  lbl = ""
  if ($0 ~ /Kernel messages have been lost/) lbl = "kmsg_lost"
  else if ($0 ~ /Forwarding to syslog missed|Missed [0-9]+ .*messages/) lbl = "missed_messages"
  else if ($0 ~ /Journal stopped/) lbl = "journal_stopped"
  else if ($0 ~ /Journal started/) lbl = "journal_started"
  else if ($0 ~ /Received SIGTERM|Received SIGHUP|Received client request to (rotate|flush|sync)/) lbl = "journal_signal_or_request"
  else if ($0 ~ /Vacuuming done|Deleted empty archived journal|Deleted archived journal/) lbl = "vacuum"
  else if ($0 ~ /Rotating|rotated/) lbl = "rotate"
  if (lbl != "") {
    ev[day SUBSEP lbl]++
    if (lbl != "vacuum" && lbl != "rotate" && evrows < 40) { evrows++; print "JROW t=" substr(ts, 1, 26) " label=" lbl }
  }
}
/^@@RC=/ { rc = substr($0, 6) }
END {
  print "JD_RC=" rc
  print "JD_TOTAL n=" n+0 " first=" first " last=" last " suppressed_events=" sup+0
  for (k in dn) print "JD_DAY day=" k " n=" dn[k]
  for (k in se) { split(k, p, SUBSEP); print "JSUPD day=" p[1] " from=" p[2] " events=" se[k] " sum=" ss[k] }
  for (k in ev) { split(k, p, SUBSEP); print "JEVT day=" p[1] " label=" p[2] " n=" ev[k] }
}'
AWK_P1='# Template classifier for PID 1 journal lines. NO raw message text is ever printed: every printed token is a validated
# timestamp, a fixed unit token (only the two G1 units get rows), a fixed event class, or a regex-restricted detail
# (exit code/status, result keyword, restart counter, reload client from a 4-name set, session scope name).
function vts(t) { return (t ~ /^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]\.[0-9][0-9][0-9][0-9][0-9][0-9]\+00:00$/) }
function subjev(r) {
  if (r ~ /^Main process exited, code=[a-z]+, status=[0-9]+\/[A-Za-z0-9]+$/) return "main_exited"
  if (r ~ /^Control process exited, code=[a-z]+,? status=[0-9]+\/[A-Za-z0-9]+$/) return "control_exited"
  if (r ~ /^Failed with result '\''[a-z-]+'\''\.$/) return "failed_result"
  if (r ~ /^Deactivated successfully\.$/) return "deactivated_ok"
  if (r ~ /^Succeeded\.$/) return "succeeded"
  if (r ~ /^Scheduled restart job, restart counter is at [0-9]+\.$/) return "restart_scheduled"
  if (r ~ /^Start request repeated too quickly\.$/) return "start_limit"
  if (r ~ /^Consumed [0-9]/) return "consumed_resources"
  if (r ~ /[Rr]eset/) return "reset_class"
  return "other"
}
function sdetail(e, r,   s) {
  s = ""
  if ((e == "main_exited" || e == "control_exited") && match(r, /code=[a-z]+,? status=[0-9]+\/[A-Za-z0-9]+$/)) { s = substr(r, RSTART); gsub(/,/, "", s); s = " " s }
  else if (e == "failed_result" && match(r, /'\''[a-z-]+'\''/)) s = " result=" substr(r, RSTART + 1, RLENGTH - 2)
  else if (e == "restart_scheduled" && match(r, /[0-9]+\.$/)) s = " counter=" substr(r, RSTART, RLENGTH - 1)
  return s
}
function vmap(v) {
  if (v == "Starting") return "starting"
  if (v == "Started") return "started"
  if (v == "Stopping") return "stopping"
  if (v == "Stopped") return "stopped"
  if (v == "Finished") return "finished"
  if (v == "Failed to start") return "failed_to_start"
  if (v == "Failed to stop") return "failed_to_stop"
  if (v == "Reloading") return "reloading_unit"
  return "reloaded_unit"
}
function vunit(r,   x) {
  if (index(r, GP) == 1) { x = substr(r, length(GP) + 1, 1); if (x == "" || x == " " || x == ".") return "g1_prod" }
  if (index(r, GR) == 1) { x = substr(r, length(GR) + 1, 1); if (x == "" || x == " " || x == ".") return "g1_restore" }
  return ""
}
function mkev(m) {
  if (m ~ /^Reloading requested from client PID [0-9]+ /) return "reload_requested"
  if (m ~ /^Reloading([. ]|$)/) return "reloading"
  if (m ~ /^Re-?executing/) return "reexec"
  if (m ~ /^Deserializ/) return "deserialize"
  if (m ~ /^Shutting down/) return "shutting_down"
  if (m ~ /^systemd [0-9][0-9.]*.* running in system mode/) return "manager_start"
  if (m ~ /daemon-re(load|exec)/) return "daemon_re"
  return ""
}
function rdetail(m,   c, sc, u) {
  c = "other"; sc = "other"
  if (match(m, /^Reloading requested from client PID [0-9]+ \('\''[A-Za-z0-9_.-]+'\''\)/)) {
    u = m; sub(/^Reloading requested from client PID [0-9]+ \('\''/, "", u); sub(/'\''\).*$/, "", u)
    if (u == "systemctl" || u == "systemd" || u == "apt" || u == "dpkg") c = u
  }
  if (match(m, /\(unit session-[0-9a-z]+\.scope\)/) && RLENGTH <= 32) sc = substr(m, RSTART + 6, RLENGTH - 7)
  return " client=" c " scope=" sc
}
function classify(m,   su, r, v, x, e) {
  U = "-"; E = "other"; D = ""
  if (match(m, /^[A-Za-z0-9_.@-]+\.(service|scope|slice|socket|timer|mount|target|path|device|swap|automount): /)) {
    su = substr(m, 1, RLENGTH - 2); r = substr(m, RLENGTH + 1)
    U = (su == GP) ? "g1_prod" : ((su == GR) ? "g1_restore" : "other_unit")
    E = subjev(r)
    if (U != "other_unit") D = sdetail(E, r)
    return
  }
  v = ""; r = ""
  if (match(m, /^(Starting|Started|Stopping|Stopped|Finished|Failed to start|Failed to stop|Reloading|Reloaded) /)) {
    v = substr(m, 1, RLENGTH - 1); r = substr(m, RLENGTH + 1)
    x = vunit(r)
    if (x != "") { U = x; E = vmap(v); return }
  }
  e = mkev(m)
  if (e != "") { E = e; if (e == "reload_requested") D = rdetail(m); return }
  if (v != "") { U = "other_unit"; E = vmap(v); return }
  if (m ~ /[Rr]eset/) E = "reset_class"
}
/^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T/ {
  if (!vts($1)) { badts++; next }
  ts = $1; day = substr(ts, 1, 10); t = substr(ts, 1, 26); n++; dn[day]++
  m = ""
  if ($3 == "systemd[1]:") { m = $0; sub(/^[^ ]+ [^ ]+ [^ ]+ ?/, "", m) }
  classify(m)
  hasw = (index($0, "wpcaregrid") > 0)
  if (U == "g1_prod" || U == "g1_restore") {
    g++; cc[E]++
    if (g <= 80) print "P1G t=" t " unit=" U " ev=" E D
  } else if (hasw) {
    wo++; cc["other"]++
    if (index($0, GP) > 0 || index($0, GR) > 0) um++
  } else {
    cc[E]++
    if (E == "reset_class") { rs++; if (rs <= 20) print "P1R t=" t " ev=reset_class" }
    else if (U == "-" && (E == "reload_requested" || E == "reloading" || E == "reexec" || E == "deserialize" || E == "shutting_down" || E == "manager_start" || E == "daemon_re")) { mk++; if (mk <= 40) print "P1M t=" t " ev=" E D }
  }
}
/^@@RC=/ { rc = substr($0, 6) }
END {
  print "P1_RC=" rc
  print "P1_TOTAL n=" n+0 " g1_lines=" g+0 " other_wpcg_lines=" wo+0 " wpcg_lines=" g+wo " g1_unanchored_mentions=" um+0 " reset_class_lines=" rs+0 " manager_marker_lines=" mk+0 " badts=" badts+0
  for (k in dn) print "P1_DAY day=" k " n=" dn[k]
  for (k in cc) print "P1_CLASS ev=" k " n=" cc[k]
}'
AWK_BA='/^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T/ {
  v = ""
  if (match($0, /audit_enabled=[0-9]+/)) v = v " " substr($0, RSTART, RLENGTH)
  if (match($0, /audit: initializing netlink subsys \((enabled|disabled)\)/)) { s = substr($0, RSTART, RLENGTH); sub(/^.*\(/, "(", s); v = v " netlink_subsys" s }
  if (match($0, /AppArmor: AppArmor initialized|apparmor: AppArmor initialized/)) v = v " apparmor_initialized"
  if (v != "" && c < 10) { c++; print "BOOTAUDIT t=" substr($1, 1, 26) v }
}
/^@@RC=/ { rc = substr($0, 6) }
END { print "BA_RC=" rc " matched=" c+0 }'
AWK_DD='function tcls(nm,   t) {
  if (nm == "") return "root_dir"
  t = nm; sub(/\/.*$/, "", t)
  if (t == "#innodb_redo") return "innodb_redo"
  if (t == "#innodb_temp") return "innodb_temp"
  if (t ~ /^#ib_.*\.dblwr$/) return "innodb_dblwr"
  if (t ~ /^ibdata[0-9]+$/) return "ibdata"
  if (t ~ /^ibtmp[0-9]+$/) return "ibtmp"
  if (t ~ /^undo_[0-9]+$/) return "undo"
  if (t == "mysql.ibd") return "mysql_ibd"
  if (t == "ib_buffer_pool") return "ib_buffer_pool"
  if (t ~ /^ib_logfile[0-9]+$/) return "ib_logfile"
  if (t ~ /^binlog\.[0-9]+$/) return "binlog"
  if (t == "binlog.index") return "binlog_index"
  if (t == "auto.cnf") return "auto_cnf"
  if (t ~ /\.pem$/) return "pem"
  if (t == "mysql" || t == "sys" || t == "performance_schema") return "system_schema_dir"
  if (t ~ /^#/) return "hash_other"
  return "other"
}
function iso(e,   d, s, z, era, doe, yoe, y, doy, mp, m, dd) {
  e = int(e); d = int(e / 86400); s = e - d * 86400
  z = d + 719468; era = int(z / 146097); doe = z - era * 146097
  yoe = int((doe - int(doe / 1460) + int(doe / 36524) - int(doe / 146096)) / 365)
  y = yoe + era * 400; doy = doe - (365 * yoe + int(yoe / 4) - int(yoe / 100))
  mp = int((5 * doy + 2) / 153); dd = doy - int((153 * mp + 2) / 5) + 1; m = (mp < 10) ? mp + 3 : mp - 9
  if (m <= 2) y++
  return sprintf("%04d-%02d-%02dT%02d:%02d:%02d", y, m, dd, int(s / 3600), int((s % 3600) / 60), s % 60)
}
function ownc(u, g,   a, b) { a = (u == myu) ? "mysql" : ((u == 0) ? "root" : "other"); b = (g == myg) ? "mysql" : ((g == 0) ? "root" : "other"); return a ":" b }
function bq(q) { if (index(q, "/") > 0) return "<nested>"; if (!(q ~ /^binlog\.[0-9]+$/ || q == "binlog.index") || length(q) > 64) return "<other>"; return q }
NF < 12 || $1 !~ /^[0-9]+(\.[0-9]+)?$/ || $3 !~ /^[0-9]+(\.[0-9]+)?$/ || $5 !~ /^-?[0-9]+(\.-?[0-9]+)?$/ { bad++; next }
{
  nm = $12; for (i = 13; i <= NF; i++) nm = nm "|" $i
  tot++; mt = $1 + 0; ct = $3 + 0; bt = $5 + 0; ty = $6; mode = $7; sz = $11 + 0; nl = $10 + 0
  cl = tcls(nm); ctot[cl]++
  if (ty == "f") fl++; else if (ty == "d") dr++; else if (ty == "l") sl++; else ot++
  if (ty == "f" && nl > 1) hl++
  own[ownc($8, $9) " " mode " " ty]++
  if (mt > thr) { mn[cl]++; mtn++ }
  if (ct > thr) { cn[cl]++; ctn++ }
  if (bt > 0) { bav++; if (bt > thr) { bn[cl]++; btn++ } }
  if (ct > thr && bt > 0 && bt <= thr) { ip[cl]++; ipn++ }
  if (mt > nmt) nmt = mt
  if (ct > nct) nct = ct
  if (cl == "other") oth++
  if (full == 1 && (cl == "binlog" || cl == "binlog_index")) print "BINLOG name=" bq(nm) " type=" ty " size=" $11 " mode=" mode " own=" ownc($8, $9) " mtime=" substr($2, 1, 26) " ctime=" substr($4, 1, 26) " birth=" ((bt > 0) ? iso(bt) : "-")
}
END {
  print "TYPES files=" fl+0 " dirs=" dr+0 " symlinks=" sl+0 " other_types=" ot+0 " hardlinked_files=" hl+0 " malformed_lines=" bad+0 " unclassified_names=" oth+0
  print "NEWER_THAN_INIT_END_PLUS_60S threshold=" iso(thr) " mtime_new=" mtn+0 " ctime_new=" ctn+0 " birth_new=" btn+0 " of_entries_with_birth=" bav+0 " ctime_new_but_birth_old=" ipn+0 " total=" tot+0
  print "NEWEST mtime=" ((nmt > 0) ? iso(nmt) : "-") " ctime=" ((nct > 0) ? iso(nct) : "-")
  for (c in ctot) print "CLASS " c " total=" ctot[c] " mtime_new=" mn[c]+0 " ctime_new=" cn[c]+0 " birth_new=" bn[c]+0 " ctime_new_birth_old=" ip[c]+0
  for (k in own) print "OWNMODE " k " n=" own[k]
}'
AWK_TOP='function tcls(nm,   t) {
  if (nm == "") return "root_dir"
  t = nm; sub(/\/.*$/, "", t)
  if (t == "#innodb_redo" || t == "#innodb_temp" || t ~ /^#ib_.*\.dblwr$/ || t ~ /^ibdata[0-9]+$/ || t ~ /^ibtmp[0-9]+$/ || t ~ /^undo_[0-9]+$/ || t == "mysql.ibd" || t == "ib_buffer_pool" || t ~ /^ib_logfile[0-9]+$/ || t ~ /^binlog\.[0-9]+$/ || t == "binlog.index" || t == "auto.cnf" || t ~ /\.pem$/ || t == "mysql" || t == "sys" || t == "performance_schema") return "known"
  return "other"
}
function iso(e,   d, s, z, era, doe, yoe, y, doy, mp, m, dd) {
  e = int(e); d = int(e / 86400); s = e - d * 86400
  z = d + 719468; era = int(z / 146097); doe = z - era * 146097
  yoe = int((doe - int(doe / 1460) + int(doe / 36524) - int(doe / 146096)) / 365)
  y = yoe + era * 400; doy = doe - (365 * yoe + int(yoe / 4) - int(yoe / 100))
  mp = int((5 * doy + 2) / 153); dd = doy - int((153 * mp + 2) / 5) + 1; m = (mp < 10) ? mp + 3 : mp - 9
  if (m <= 2) y++
  return sprintf("%04d-%02d-%02dT%02d:%02d:%02d", y, m, dd, int(s / 3600), int((s % 3600) / 60), s % 60)
}
function ownc(u, g,   a, b) { a = (u == myu) ? "mysql" : ((u == 0) ? "root" : "other"); b = (g == myg) ? "mysql" : ((g == 0) ? "root" : "other"); return a ":" b }
{
  nm = $12; for (i = 13; i <= NF; i++) nm = nm "|" $i
  q = nm; gsub(/\//, ">", q)
  if (nm == "") q = "."
  else if (index(nm, "/") > 0) {
    # nested names are printed only for the two InnoDB internal directories with their fixed child shapes
    if (!(nm ~ /^#innodb_redo\/#ib_redo[0-9]+(_tmp)?$/ || nm ~ /^#innodb_temp\/temp_[0-9]+\.ibt$/)) q = "<nested>"
  }
  else if (tcls(nm) != "known" || length(q) > 64 || q !~ /^[#A-Za-z0-9_.-]+$/) q = "<other>"
  bt = $5 + 0
  print "TOP ctime=" substr($4, 1, 26) " mtime=" substr($2, 1, 26) " birth=" ((bt > 0) ? iso(bt) : "-") " " $6 " " $7 " " ownc($8, $9) " " $11 " " q
}'

# hostname mask (backstop only): short and full name, case-insensitive, names with [A-Za-z0-9_.-] only
HSED='s/^$//'
for HN in "$(hostname -s 2>/dev/null)" "$(hostname 2>/dev/null)"; do
  case "$HN" in ''|*[!A-Za-z0-9_.-]*) continue;; esac
  [ ${#HN} -ge 2 ] || continue
  HE=${HN//./\\.}
  HSED="$HSED;s/(^|[^A-Za-z0-9_.-])${HE}([^A-Za-z0-9_.-]|\$)/\1<host>\2/gI"
done

{
echo "GO2037_COLLECTOR=BEGIN;VERSION=2"

# ================= 0 identity =================
sb 0 "identity (all times UTC)"
date -u +%Y-%m-%dT%H:%M:%SZ
HID=$(hostname -s 2>/dev/null | sha256sum | cut -c1-12)
echo "host_id=$HID uptime_since=$(uptime -s 2>/dev/null)"
[ "$HID" = "$V3_HOST_ID" ] && echo "HOST_ID_SAME_AS_V3=yes" || echo "HOST_ID_SAME_AS_V3=NO"
BID=$(tr -d '-' < "$BOOTIDFILE" 2>/dev/null)
case "$BID" in *[!0-9a-f]*|'') echo "BOOT_ID=UNREADABLE"; F=$((F+1));; *) [ "$BID" = "$V3_BOOT_ID" ] && echo "BOOT_SAME_AS_V3=yes" || echo "BOOT_SAME_AS_V3=NO";; esac
echo "bash_version=$BV (guard: >= 5.1 required)"
echo "kernel_release=$(uname -r 2>&1)"
echo "systemd=$(T 10 systemctl --version 2>&1 | head -n1 | cut -c1-60)"
SH=$(T 10 sha256sum -- "$SELF" 2>&1); rc=$?
[ $rc -eq 0 ] && echo "SCRIPT_SHA256=${SH%% *}" || { fail script_hash $rc; echo "SCRIPT_SHA256=VOID"; }
se 0

# ================= single kernel+audit-transport journal pass (feeds sections 1-4) =================
# [ROOT or systemd-journal/adm group] journalctl _TRANSPORT=kernel _TRANSPORT=audit since $SINCE (one pass, awk, counts/fixed classes only)
KA=$(JR 150 _TRANSPORT=kernel _TRANSPORT=audit --since "$SINCE" | awk -v PAR=$PAR -v W1a=$W1a -v W1b=$W1b -v W2a=$W2a -v W2b=$W2b -v W3a=$W3a -v W3b=$W3b "$AWK_KA")
KARC=$(printf '%s\n' "$KA" | sed -n 's/^KA_RC=//p')
if [ "$KARC" != 0 ]; then fail journalctl_kernel_audit "${KARC:-NA}"; fi

# ================= 1 per-denial detail (wpcaregrid profiles) =================
sb 1 "wpcaregrid AppArmor denials, per record (names as fixed classes only; windows W1=11-09 init, W2=11-12 15:41 restore init, W3=11-12 15:53:07-10 start)"
printf '%s\n' "$KA" | grep -E '^DENIAL ' | cut -c1-260
printf '%s\n' "$KA" | grep -E '^DENIED_wpcg_profiles='
printf '%s\n' "$KA" | grep -E '^ALLOWED_wpcg_profiles='
echo "v3_expected_DENIED_wpcg=$V3_DENIED_WPCG"
printf '%s\n' "$KA" | grep -E '^XDENIAL ' | cut -c1-260
printf '%s\n' "$KA" | grep -E '^DENIED_other_profile_carrying_wpcaregrid_string=|^DENIED_any_profile_carrying_wpcaregrid_string='
se 1

# ================= 2 aggregates =================
sb 2 "all-profile and comm=mysqld denials by day and profile (counts only)"
printf '%s\n' "$KA" | grep -E '^DENIED_comm_mysqld_any_profile=|^DENIED_all_profiles='
echo "v3_expected_DENIED_comm_mysqld=$V3_DENIED_MYSQLD v3_expected_DENIED_all=$V3_DENIED_ALL"
printf '%s\n' "$KA" | grep -E '^AGG ' | sort | head -n 120
printf '%s\n' "$KA" | grep -E '^W3_ALL='
printf '%s\n' "$KA" | grep -E '^W3_PROF ' | sort
se 2

# ================= 3 AppArmor STATUS records and profile state =================
sb 3 "AppArmor STATUS records (profile load, replace, remove), profile files and loaded profiles"
printf '%s\n' "$KA" | grep -E '^STATUS_records='
echo "v3_expected_STATUS_records=$V3_STATUS"
printf '%s\n' "$KA" | grep -E '^STATUS ' | head -n 60
printf '%s\n' "$KA" | grep -E '^STATUS_DAY ' | sort
# [DEPLOY-OK] profile definition files (0644): hash vs pinned OLD/NEW, mtime/ctime
fmeta profile_prod "$PROFDIR/wpcaregrid-mysql-production" "OLD=$PROF_P_OLD" "NEW=$PROF_P_NEW"
fmeta profile_restore "$PROFDIR/wpcaregrid-mysql-production-restore" "OLD=$PROF_R_OLD" "NEW=$PROF_R_NEW"
echo "-- profile file neighbours (metadata only: type mode size mtime ctime name)"
FN=$(T 15 find "$PROFDIR" -maxdepth 1 -name 'wpcaregrid-mysql*' -printf '%y %m %s %T+ %C+ %f\n' 2>&1); rc=$?
[ $rc -eq 0 ] || fail find_profiledir $rc
printf '%s\n' "$FN" | sort | cut -c1-150 | head -n 20
echo "-- AppArmor cache entries (metadata only)"
if [ -d "$AACACHE" ]; then
  FC=$(T 15 find "$AACACHE" -maxdepth 3 -name 'wpcaregrid-mysql*' -printf '%y %s %T+ %C+ %f\n' 2>&1); rc=$?
  [ $rc -eq 0 ] || fail find_aacache $rc
  [ -n "$FC" ] && printf '%s\n' "$FC" | sort | cut -c1-150 | head -n 20
else echo "AACACHE=ABSENT"; fi
# [ROOT] loaded profiles (names + mode only; as v3; securityfs read)
if [ -r "$AAPROFILES" ]; then
  PRF=$(T 10 cat "$AAPROFILES" 2>&1); rc=$?
  [ $rc -eq 0 ] || fail read_aa_profiles $rc
  echo "loaded_profiles_total=$(printf '%s\n' "$PRF" | grep -c .) enforce=$(printf '%s\n' "$PRF" | grep -c '(enforce)$') complain=$(printf '%s\n' "$PRF" | grep -c '(complain)$')"
  printf '%s\n' "$PRF" | grep '^wpcaregrid-mysql' | cut -c1-80
else echo "AAPROFILES=UNREADABLE"; F=$((F+1)); fi
se 3

# ================= 4 journald suppression and audit coverage =================
sb 4 "journald suppression, rate limits, audit coverage"
echo "KA_RC=$KARC"
printf '%s\n' "$KA" | grep -E '^KA_TOTAL ' | cut -c1-200
printf '%s\n' "$KA" | grep -E '^KA_DAY ' | sort
echo "audit_lost_markers: $(printf '%s\n' "$KA" | sed -n 's/^AUDIT_LOST_MARKERS=//p')"
printf '%s\n' "$KA" | grep -E '^LOST '
printf '%s\n' "$KA" | grep -E '^KSUPD ' | sort
printf '%s\n' "$KA" | grep -E '^KSUP ' | head -n 30
echo "-- audit/kernel-audit record types (counts only)"
printf '%s\n' "$KA" | grep -E '^ATYPE ' | sort -t '=' -k4,4nr | head -n 25
echo "-- SERVICE_START/STOP audit records per day for G1 units and legacy mysql (counts only)"
printf '%s\n' "$KA" | grep -E '^SVC ' | sort
printf '%s\n' "$KA" | grep -E '^SVC_OTHER_UNITS_TOTAL='
# journald own messages (Suppressed N messages, vacuum, restart, kernel-message loss)
JD=$(JR 90 _SYSTEMD_UNIT=systemd-journald.service --since "$SINCE" | awk "$AWK_JD")
JDRC=$(printf '%s\n' "$JD" | sed -n 's/^JD_RC=//p')
[ "$JDRC" = 0 ] || fail journalctl_journald "${JDRC:-NA}"
echo "JD_RC=$JDRC"
printf '%s\n' "$JD" | grep -E '^JD_TOTAL ' | cut -c1-200
printf '%s\n' "$JD" | grep -E '^JD_DAY ' | sort
printf '%s\n' "$JD" | grep -E '^JSUPD ' | sort
printf '%s\n' "$JD" | grep -E '^JSUP ' | head -n 40
printf '%s\n' "$JD" | grep -E '^JEVT ' | sort
printf '%s\n' "$JD" | grep -E '^JROW ' | head -n 40
# [DEPLOY-OK] journald effective configuration: fixed file list, selected keys only
echo "-- journald configuration (selected keys; unlisted key = compiled-in default)"
for f in /etc/systemd/journald.conf /etc/systemd/journald.conf.d/*.conf /usr/lib/systemd/journald.conf.d/*.conf /run/systemd/journald.conf.d/*.conf; do
  [ -f "$f" ] || continue
  b=$(basename -- "$f"); case "$b" in ''|*[!A-Za-z0-9_.@+-]*) b="<odd>";; esac
  grep -hE '^[[:space:]]*(RateLimitIntervalSec|RateLimitInterval|RateLimitBurst|Storage|Audit|SystemMaxUse|SystemKeepFree|SystemMaxFileSize|MaxRetentionSec|MaxLevelStore|Compress|ForwardToSyslog|ForwardToKMsg)[[:space:]]*=' "$f" 2>/dev/null | sed -e 's/[[:space:]]//g' | cut -c1-80 | awk -v b="$b" '{print "journald_conf " b " " $0}'
done
[ -d /var/log/journal ] && echo "journal_persistent=yes" || echo "journal_persistent=no"
Rc journal_disk_usage 15 journalctl --disk-usage
BT=$(T 30 journalctl --list-boots --no-pager 2>&1); rc=$?; [ $rc -eq 0 ] || fail journalctl_list_boots $rc
echo "boots_total=$(printf '%s\n' "$BT" | grep -c .)"
printf '%s\n' "$BT" | sed -n '$p' | cut -c1-110
# [DEPLOY-OK] kernel rate-limit and AppArmor audit parameters (single fixed files)
for k in kernel/printk kernel/printk_ratelimit kernel/printk_ratelimit_burst kernel/printk_devkmsg kernel/dmesg_restrict net/core/message_cost net/core/message_burst; do
  kn=${k//\//.}   # dotted key name: a/b/c would be hit by the output mask
  if [ -r "$PROCSYS/$k" ]; then echo "sysctl $kn=$(tr '\t' ' ' < "$PROCSYS/$k" 2>&1 | head -n1 | cut -c1-40)"
  elif [ -e "$PROCSYS/$k" ]; then echo "sysctl $kn=UNREADABLE"; F=$((F+1))
  else echo "sysctl $kn=ABSENT"; fi
done
for k in audit mode enabled logsyscall audit_header debug; do
  if [ -r "$AAPARAMS/$k" ]; then echo "apparmor_param $k=$(head -n1 "$AAPARAMS/$k" 2>&1 | cut -c1-40)"
  elif [ -e "$AAPARAMS/$k" ]; then echo "apparmor_param $k=UNREADABLE"; F=$((F+1))
  else echo "apparmor_param $k=ABSENT"; fi
done
echo "proc_cmdline_audit=$(grep -oE 'audit=[0-9]' /proc/cmdline 2>/dev/null | head -n1)"
echo "-- kernel audit initialisation lines of the current boot (fixed values only)"
BA=$(JR 60 -b 0 -k --since "2026-11-05 21:52:00" --until "2026-11-05 21:54:00" | awk "$AWK_BA")
BARC=$(printf '%s\n' "$BA" | sed -n 's/^BA_RC=\([0-9]*\).*/\1/p')
[ "$BARC" = 0 ] || fail journalctl_boot_audit "${BARC:-NA}"
printf '%s\n' "$BA" | cut -c1-160
if T 10 dmesg >/dev/null 2>&1; then echo "dmesg_lines=$(T 10 dmesg | wc -l) oldest=$(T 10 dmesg --time-format iso 2>/dev/null | sed -n 1p | cut -c1-30)"; else echo "dmesg=UNREADABLE"; F=$((F+1)); fi
echo "auditd_service: $(T 15 systemctl show auditd.service -p LoadState,ActiveState 2>&1 | tr '\n' ' ' | cut -c1-80)"
echo "audit_log_dir=$([ -d /var/log/audit ] && echo PRESENT || echo ABSENT)"
se 4

# ================= 5 production datadir metadata baseline (after the 11-12 failed start) =================
sb 5 "datadir metadata (find/stat only; no file is opened; names printed only if on the MySQL-internal whitelist)"
PT=$(date -u -d "$PEND_ISO" +%s 2>/dev/null); RT=$(date -u -d "$REND_ISO" +%s 2>/dev/null)
if isnum "$PT" && isnum "$RT"; then
  ddsum production "$PD" "$((PT+60))$PEND_FRAC" 1
  # EXTENSION (Moody/Owner may drop): same metadata summary for the restore datadir, summary only
  ddsum restore "$RD" "$((RT+60))$REND_FRAC" 0
else echo "THRESHOLD_EPOCH=VOID"; F=$((F+1)); fi
se 5

# ================= 6 systemd unit state and lifecycle history =================
sb 6 "systemd units: state, identity, surviving fields, systemd-authored journal lines"
echo "-- manager"
Rc systemctl_show_manager 15 systemctl show -p Version,Tainted,UserspaceTimestamp,FinishTimestamp,NFailedUnits,NNames,NJobs
# invocation state files are listed BEFORE any per-unit 'systemctl show' so that the listing is not order-dependent on PID 1
# loading the unit; InvocationID_present=yes below is weak evidence only (see CHANGES: N8).
echo "-- invocation state files that survive until the unit is freed (metadata only)"
FI=$(T 15 find "$UNITRUN" -maxdepth 1 -name 'invocation:mysql@wpcaregrid-*' -printf '%y %T+ %C+ %f\n' 2>&1); rc=$?
[ $rc -eq 0 ] || fail find_unitrun $rc
printf '%s\n' "$FI" | grep . | sort | cut -c1-150 | head -n 10
echo "invocation_files=$(printf '%s\n' "$FI" | grep -c invocation)"
for u in $P $R; do echo "-- $u"
  # [DEPLOY-OK] property reads (PID 1 may load the unit definition into memory if not loaded; nothing is started)
  Rc systemctl_show_unit 15 systemctl show "$u" -p LoadState,ActiveState,SubState,Result,NRestarts,ExecMainStatus,ExecMainCode,ExecMainStartTimestamp,ExecMainExitTimestamp,StateChangeTimestamp,InactiveEnterTimestamp,InactiveExitTimestamp,ActiveEnterTimestamp,ActiveExitTimestamp,ConditionTimestamp,AssertTimestamp,NeedDaemonReload,UnitFileState,UnitFilePreset,Type,NotifyAccess,Restart,TimeoutStartUSec,LogRateLimitIntervalUSec,LogRateLimitBurst,AppArmorProfile
  IV=$(T 15 systemctl show "$u" -p InvocationID --value 2>&1 | head -n1); echo "InvocationID_present=$([ -n "$IV" ] && [ "$IV" != "-" ] && echo yes || echo no)"
  echo "enabled=$(T 15 systemctl is-enabled "$u" 2>&1 | head -n1)"
  FP=$(T 15 systemctl show "$u" -p FragmentPath --value 2>&1 | head -n1); DI=$(T 15 systemctl show "$u" -p DropInPaths --value 2>&1 | head -n1)
  fmeta fragment "$FP"
  set -f   # no pathname expansion while word-splitting the DropInPaths value
  for d in $DI; do
    case "$u:$(basename -- "$d")" in
      "$P:90-wpcaregrid-g1.conf") fmeta dropin "$d" "MANIFEST=$DROPIN_P";;
      "$R:90-wpcaregrid-g1.conf") fmeta dropin "$d" "MANIFEST=$DROPIN_R";;
      *) fmeta dropin "$d";;
    esac
  done
  set +f
done
echo "-- unit definition neighbours (metadata only: type mode mtime ctime name; names without 'wpcaregrid' are printed as <other>)"
UD=""; for d in /etc/systemd/system /run/systemd/system; do [ -d "$d" ] && UD="$UD $d"; done
if [ -n "$UD" ]; then
  set -f
  FU=$(T 15 find $UD -maxdepth 2 \( -name 'mysql@*' -o -name '90-wpcaregrid*' \) -printf '%y %m %T+ %C+ %f\n' 2>&1); rc=$?
  set +f
  [ $rc -eq 0 ] || fail find_unitdirs $rc
  printf '%s\n' "$FU" | awk '{ n = $5; for (i = 6; i <= NF; i++) n = n " " $i; if (NF < 5 || length(n) > 64 || n !~ /^[A-Za-z0-9_.@+-]+$/ || n !~ /wpcaregrid/) n = "<other>"; print $1, $2, $3, $4, n }' | sort | cut -c1-150 | head -n 30
else echo "UNIT_DIRS=NONE_PRESENT"; F=$((F+1)); fi
# [ROOT or journal group] systemd (PID 1) authored messages since $SINCE, one pass; template classifier, no raw text printed
P1=$(JR 150 _PID=1 --since "$SINCE" | awk -v GP="$P" -v GR="$R" "$AWK_P1")
P1RC=$(printf '%s\n' "$P1" | sed -n 's/^P1_RC=//p')
[ "$P1RC" = 0 ] || fail journalctl_pid1 "${P1RC:-NA}"
echo "P1_RC=$P1RC"
printf '%s\n' "$P1" | grep -E '^P1_TOTAL '
printf '%s\n' "$P1" | grep -E '^P1_DAY ' | sort
printf '%s\n' "$P1" | grep -E '^P1_CLASS ' | sort
echo "-- PID 1 lifecycle rows for the two G1 units only (timestamp, unit tag, event class; other units and unmatched lines are counted, never printed)"
printf '%s\n' "$P1" | grep -E '^P1G ' | cut -c1-120
echo "-- PID 1 reset-class lines (class only; expected few or none; absence does not prove no reset-failed was run)"
printf '%s\n' "$P1" | grep -E '^P1R ' | cut -c1-120
echo "-- PID 1 manager markers (reload, reexec, deserialize, shutdown; class and fixed-set detail only)"
printf '%s\n' "$P1" | grep -E '^P1M ' | cut -c1-120
se 6


echo "GO2037_COLLECTOR=END;FAILED_READS=$F;READ_ONLY=yes"
} 2>&1 | sed -E -e 's%(^|[^A-Za-z0-9])/[^[:space:]]+%\1<path>%g' -e 's%[A-Za-z0-9_.@#+-]+(/[A-Za-z0-9_.@#+-]+){2,}%<relpath>%g' -e 's/[0-9]{1,3}(\.[0-9]{1,3}){3}/<ip>/g' -e 's/([0-9A-Fa-f]{1,4}:){4,7}[0-9A-Fa-f]{1,4}/<ip6>/g' -e 's/[0-9A-Fa-f:]*::[0-9A-Fa-f:]*/<ip6>/g' -e "$HSED"
