set -uo pipefail
# GO-5.2c confined read-only probe: runs ONLY /usr/bin/my_print_defaults under each instance's enforcing AppArmor
# profile (it reads the option files and prints values; it starts no server and writes nothing). Option values are
# never printed: the output is captured and only a yes/no for the exact datadir line is reported.
# PASS only if, for both instances, the exit code is 0 and the resolved output contains exactly the instance datadir.
[ "$(id -u)" = 0 ] || { echo "G1_GO52C_CONFINED_PROBE=STOP;CODE=not_root"; exit 3; }
RESULT=PASS
for I in wpcaregrid-production wpcaregrid-production-restore; do
  OUT=$(/usr/bin/aa-exec -p "wpcaregrid-mysql-${I#wpcaregrid-}" -- /usr/bin/my_print_defaults \
        --defaults-file=/etc/mysql/my.cnf --defaults-group-suffix=@"$I" mysqld 2>/dev/null); RC=$?
  if printf '%s\n' "$OUT" | grep -qxF -- "--datadir=/var/lib/mysql-$I"; then HAS=yes; else HAS=no; fi
  echo "G1_GO52C_CONFINED_PROBE;INSTANCE=$I;RC=$RC;DATADIR_LINE=$HAS"
  { [ "$RC" = 0 ] && [ "$HAS" = yes ]; } || RESULT=STOP
done
if [ "$RESULT" = PASS ]; then echo "G1_GO52C_CONFINED_PROBE=PASS"; else echo "G1_GO52C_CONFINED_PROBE=STOP;CODE=confined_read_failed"; exit 3; fi
