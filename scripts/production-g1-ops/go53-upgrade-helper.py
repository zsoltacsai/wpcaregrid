#!/usr/bin/env python3
"""GO-5.3 helper-only upgrade. Never starts MySQL or changes policy/data."""
import base64, errno, fcntl, grp, hashlib, json, os, pwd, re, stat, subprocess, sys, time

LIBEXEC='/usr/local/libexec'
HELPER=LIBEXEC+'/wpcaregrid-g1-mysql.py'
MANIFEST=LIBEXEC+'/production_g1_mysql_manifest.json'
CONTRACT=LIBEXEC+'/production_g1_mysql_contract.py'
PROFILE_P='/etc/apparmor.d/wpcaregrid-mysql-production'
PROFILE_R='/etc/apparmor.d/wpcaregrid-mysql-production-restore'
DATADIRS=('/var/lib/mysql-wpcaregrid-production','/var/lib/mysql-wpcaregrid-production-restore')
LOGS=('/var/log/mysql/wpcaregrid-production.log','/var/log/mysql/wpcaregrid-production-restore.log')
OLD={HELPER:'32da9353289e97f1c9974d544185f51b2fea45e0c926efaaf523753bfbfb528e',MANIFEST:'70490acf887f3222ffaa0087c215826dc70a8b8cda7016d0f8807b7099cab6cb'}
NEW={HELPER:'30422ed4475b7b94b53b279054daeaa1aca0a8206889d6de9d688ec4f96f4428',MANIFEST:'abd987e20a459e9ec6ebef4a791b376592e162396a8ae8a62d415ff021749dd9'}
PINS={CONTRACT:'270a7f2ddc93a38bbabc94197e11fa078602004b39c18a2c86a34bf4c6f05f8f',PROFILE_P:'9a40053158880f0e6baf0db06431587651701487910634015e712e878dcfe13d',PROFILE_R:'ce56847e98f23b45590aaf55ad069df89f97dc12b672c2a289bb0fa7fb923b3e'}
UNITS=('mysql@wpcaregrid-production.service','mysql@wpcaregrid-production-restore.service')
NAMES={HELPER:'production-g1-mysql.py',MANIFEST:'production-g1-mysql-manifest.json'}
PACKAGE_CONTRACT='production_g1_mysql_contract.py'
MODES={HELPER:0o755,MANIFEST:0o644}
ENV={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','HOME':'/nonexistent','LANG':'C','LC_ALL':'C'}
JOURNAL=LIBEXEC+'/.go53-helper-upgrade.json'
LOCK_PATH='/run/wpcaregrid-go53-helper-upgrade.lock'
LOCK_WAIT_SECONDS=30
class Stop(Exception):
 def __init__(self,code,rollback=None): super().__init__(code); self.code,self.rollback=code,rollback
def sha(b): return hashlib.sha256(b).hexdigest()
def read(path,mode=None):
 name=os.path.basename(path)
 try:fd=os.open(path,os.O_RDONLY|getattr(os,'O_NOFOLLOW',0)|getattr(os,'O_NONBLOCK',0))
 except OSError as e:
  if e.errno==errno.ELOOP:raise Stop('not_regular:'+name)
  raise
 try:
  # Type, owner and mode are taken from the opened descriptor, so a swap between check and read cannot be used.
  s=os.fstat(fd)
  if not stat.S_ISREG(s.st_mode): raise Stop('not_regular:'+name)
  if (s.st_uid,s.st_gid)!=(0,0): raise Stop('owner_invalid:'+name)
  if mode is not None and stat.S_IMODE(s.st_mode)!=mode: raise Stop('mode_invalid:'+name)
  with os.fdopen(fd,'rb',closefd=False) as f:return f.read()
 finally:os.close(fd)
def check_dir(path,code):
 # A directory in which files are created and renamed must be a real root-owned directory nobody else can write to.
 s=os.lstat(path)
 if not stat.S_ISDIR(s.st_mode) or s.st_uid!=0 or stat.S_IMODE(s.st_mode)&0o022:raise Stop(code)
def check_package_dir(path):
 try:s=os.lstat(path)
 except OSError:raise Stop('package_directory_invalid')
 if not stat.S_ISDIR(s.st_mode) or (s.st_uid,s.st_gid)!=(0,0) or stat.S_IMODE(s.st_mode)!=0o700:
  raise Stop('package_directory_owner_or_mode_invalid')
def hash_mysql_file(path,exact_mode=None):
 flags=os.O_RDONLY|getattr(os,'O_NOFOLLOW',0)|getattr(os,'O_NONBLOCK',0)
 fd=os.open(path,flags)
 try:
  s=os.fstat(fd)
  if not stat.S_ISREG(s.st_mode) or (s.st_uid,s.st_gid)!=(pwd.getpwnam('mysql').pw_uid,grp.getgrnam('mysql').gr_gid): raise Stop('datadir_file_type_or_owner_invalid')
  if stat.S_IMODE(s.st_mode)&0o022: raise Stop('datadir_file_mode_invalid')
  if exact_mode is not None and stat.S_IMODE(s.st_mode)!=exact_mode:raise Stop('mysql_log_mode_invalid')
  digest=hashlib.sha256()
  with os.fdopen(fd,'rb',closefd=False) as f:
   for chunk in iter(lambda:f.read(1024*1024),b''):digest.update(chunk)
  return digest.hexdigest()
 finally:os.close(fd)
def read_mysql_log(path):
 return hash_mysql_file(path,0o600)
def run(args):return subprocess.run(args,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True,timeout=120,close_fds=True,env=ENV,check=False)
def atomic(path,data,mode,hook=None):
 tmp=path+'.g1-upgrade-tmp'; fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|getattr(os,'O_NOFOLLOW',0),0o600)
 try:
  with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
  os.chown(tmp,0,0);os.chmod(tmp,mode)
  if hook:hook(path)
  os.replace(tmp,path)
  d=os.open(os.path.dirname(path),os.O_RDONLY|os.O_DIRECTORY)
  try:os.fsync(d)
  finally:os.close(d)
 except BaseException:
  if os.path.lexists(tmp):os.unlink(tmp)
  raise
def tree_fingerprint(path):
 root=os.lstat(path)
 if not stat.S_ISDIR(root.st_mode):raise Stop('datadir_not_directory')
 out=[]
 mysql_owner=(pwd.getpwnam('mysql').pw_uid,grp.getgrnam('mysql').gr_gid)
 if (root.st_uid,root.st_gid)!=mysql_owner or stat.S_IMODE(root.st_mode)&0o022:raise Stop('datadir_owner_or_mode_invalid')
 def walk_error(exc):raise Stop('datadir_traversal_failed')
 for base,dirs,files,dirfd in os.fwalk(path,topdown=True,follow_symlinks=False,onerror=walk_error):
  dirs.sort();files.sort()
  for n in dirs+files:
   s=os.stat(n,dir_fd=dirfd,follow_symlinks=False);rel=os.path.relpath(os.path.join(base,n),path)
   if stat.S_ISLNK(s.st_mode):raise Stop('datadir_symlink')
   if not (stat.S_ISDIR(s.st_mode) or stat.S_ISREG(s.st_mode)):raise Stop('datadir_special_file')
   if (s.st_uid,s.st_gid)!=mysql_owner:raise Stop('datadir_owner_invalid')
   if stat.S_IMODE(s.st_mode)&0o022:raise Stop('datadir_mode_invalid')
   h='-'
   if stat.S_ISREG(s.st_mode):
    fd=os.open(n,os.O_RDONLY|getattr(os,'O_NOFOLLOW',0)|getattr(os,'O_NONBLOCK',0),dir_fd=dirfd)
    try:
     opened=os.fstat(fd)
     if not stat.S_ISREG(opened.st_mode) or (opened.st_dev,opened.st_ino)!=(s.st_dev,s.st_ino) or (opened.st_uid,opened.st_gid)!=mysql_owner:raise Stop('datadir_changed_during_read')
     digest=hashlib.sha256()
     with os.fdopen(fd,'rb',closefd=False) as f:
      for chunk in iter(lambda:f.read(1024*1024),b''):digest.update(chunk)
     h=digest.hexdigest()
    finally:os.close(fd)
   out.append((rel,s.st_mode,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns,h))
 return sha(repr((root.st_mode,root.st_uid,root.st_gid,root.st_mtime_ns,out)).encode())
def durable_json(path,obj):
 tmp=path+'.tmp'; b=(json.dumps(obj,sort_keys=True,separators=(',',':'))+'\n').encode()
 fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|getattr(os,'O_NOFOLLOW',0),0o600)
 try:
  with os.fdopen(fd,'wb') as f:f.write(b);f.flush();os.fsync(f.fileno())
  os.chown(tmp,0,0);os.chmod(tmp,0o600);os.replace(tmp,path)
 except BaseException:
  if os.path.lexists(tmp):os.unlink(tmp)
  raise
 d=os.open(os.path.dirname(path),os.O_RDONLY|os.O_DIRECTORY)
 try:os.fsync(d)
 finally:os.close(d)
def journal_state():
 if not os.path.lexists(JOURNAL):return None
 b=read(JOURNAL,0o600)
 try:j=json.loads(b)
 except Exception:raise Stop('journal_corrupt_manual_recovery_required')
 if set(j)!={'schema','phase','old','new','backup'} or j['schema']!=1 or j['phase'] not in ('prepared','replacing','committed') or j['old']!=OLD or j['new']!=NEW or set(j['backup'])!=set(OLD):raise Stop('journal_unrecognized_manual_recovery_required')
 try:
  if any(sha(base64.b64decode(j['backup'][p],validate=True))!=OLD[p] for p in OLD):raise ValueError()
 except Exception:raise Stop('journal_backup_invalid_manual_recovery_required')
 return j
def _main_locked(pkg,runfn=run,euid=None,replace=atomic,hook=None):
 if (os.geteuid() if euid is None else euid)!=0:raise Stop('not_root')
 if os.path.islink(pkg) or not os.path.isdir(pkg):raise Stop('package_directory_invalid')
 check_package_dir(pkg)
 # Accept the repository's authenticated four-file G1 package. The pinned
 # contract is verified as package input but is never installed or rewritten.
 expected=set(NAMES.values())|{PACKAGE_CONTRACT,'SHA256SUMS'}
 check_dir(LIBEXEC,'libexec_directory_unsafe');check_dir(os.path.dirname(PROFILE_P),'apparmor_directory_unsafe')
 if set(os.listdir(pkg))!=expected:raise Stop('package_contents_unexpected')
 for name in expected:
  p=os.path.join(pkg,name)
  if stat.S_ISLNK(os.lstat(p).st_mode) or not stat.S_ISREG(os.lstat(p).st_mode):raise Stop('package_entry_invalid')
 sums=read(os.path.join(pkg,'SHA256SUMS'),0o600).decode('ascii')
 parsed={}
 for line in sums.splitlines():
  m=re.fullmatch(r'([0-9a-f]{64})  ([A-Za-z0-9_.-]+)',line)
  if not m or m.group(2) in parsed:raise Stop('sha256sums_invalid')
  parsed[m.group(2)]=m.group(1)
 expected_hashes={n:NEW[p] for p,n in NAMES.items()}
 expected_hashes[PACKAGE_CONTRACT]=PINS[CONTRACT]
 if parsed!=expected_hashes:raise Stop('sha256sums_pin_mismatch')
 package={p:read(os.path.join(pkg,n),0o600) for p,n in NAMES.items()}
 if any(sha(package[p])!=NEW[p] for p in package):raise Stop('package_hash_mismatch')
 if sha(read(os.path.join(pkg,PACKAGE_CONTRACT),0o600))!=PINS[CONTRACT]:raise Stop('package_contract_hash_mismatch')
 if sha(read(CONTRACT,0o644))!=PINS[CONTRACT]:raise Stop('contract_changed')
 for p in (PROFILE_P,PROFILE_R):
  if sha(read(p,0o644))!=PINS[p]:raise Stop('apparmor_profile_changed')
 if os.path.lexists(JOURNAL+'.tmp'):raise Stop('leftover_journal_temporary_file_manual_recovery_required')
 before_pid=read_pid(runfn)
 datadirs=[tree_fingerprint(p) for p in DATADIRS]
 logs=[read_mysql_log(p) for p in LOGS]
 current={p:read(p,MODES[p]) for p in (HELPER,MANIFEST)}; state={p:sha(b) for p,b in current.items()}
 already=all(state[p]==NEW[p] for p in NEW)
 assert_units_inactive(runfn)
 j=journal_state()
 leftovers=[p for p in (HELPER,MANIFEST) if os.path.lexists(p+'.g1-upgrade-tmp')]
 if leftovers and (j is None or j['phase']!='replacing'):
  raise Stop('leftover_temporary_file_manual_recovery_required')
 if j:
  if any(state[p] not in (OLD[p],NEW[p]) for p in OLD):raise Stop('journal_file_state_unknown_manual_recovery_required')
  if leftovers:
   # A kill during atomic replacement can leave that transaction's partial
   # temp file beside a valid replacing journal. Remove only a regular,
   # root-owned, single-link temp in a mode atomic() itself can create.
   for p in leftovers:
    tmp=p+'.g1-upgrade-tmp';ts=os.lstat(tmp)
    if not stat.S_ISREG(ts.st_mode) or (ts.st_uid,ts.st_gid)!=(0,0) or ts.st_nlink!=1 or stat.S_IMODE(ts.st_mode) not in (0o600,MODES[p]):
     raise Stop('leftover_temporary_file_unrecognized_manual_recovery_required')
    os.unlink(tmp)
    d=os.open(os.path.dirname(p),os.O_RDONLY|os.O_DIRECTORY)
    try:os.fsync(d)
    finally:os.close(d)
  # A journal proves this exact transaction owns a known OLD/NEW mixture. Recover to OLD, never guess.
  if j['phase']=='committed' and all(state[p]==NEW[p] for p in NEW):
   os.unlink(JOURNAL)
   already=True
  else:
   for p in (MANIFEST,HELPER):
    if state[p]!=OLD[p]:atomic(p,base64.b64decode(j['backup'][p],validate=True),MODES[p])
   if any(sha(read(p,MODES[p]))!=OLD[p] for p in OLD):raise Stop('journal_recovery_failed_manual_recovery_required')
   os.unlink(JOURNAL)
   already=False
  d=os.open(LIBEXEC,os.O_RDONLY|os.O_DIRECTORY);os.fsync(d);os.close(d)
  current={p:read(p,MODES[p]) for p in OLD};state={p:sha(b) for p,b in current.items()}
 if not already and any(state[p]!=OLD[p] for p in OLD):raise Stop('installed_baseline_mismatch')
 def invariants():
  assert_units_inactive(runfn)
  if read_pid(runfn)!=before_pid:raise Stop('mysql_mainpid_changed')
  if [tree_fingerprint(p) for p in DATADIRS]!=datadirs:raise Stop('datadir_changed')
  if [read_mysql_log(p) for p in LOGS]!=logs:raise Stop('mysql_logs_changed')
  for p in (CONTRACT,PROFILE_P,PROFILE_R):
   if sha(read(p,0o644))!=PINS[p]:raise Stop('protected_file_changed')
 def verify():
  code="import runpy; d=runpy.run_path(%r); d['verify_static_install'](); print('STATIC_INSTALL_VERIFY=PASS'); d['check_base_config'](); print('BASE_CONFIG_CHECK=PASS')"%HELPER
  done=runfn(['/usr/bin/python3','-I','-B','-c',code])
  if done.returncode or 'STATIC_INSTALL_VERIFY=PASS' not in done.stdout or 'BASE_CONFIG_CHECK=PASS' not in done.stdout:raise Stop('helper_static_verification_failed')
  # verify_static_install checks the structural systemd/AppArmor contract for both instances.
 if already:
  verify();invariants()
  if any(sha(read(p,MODES[p]))!=NEW[p] for p in NEW):raise Stop('target_hash_missing_before_pass')
  return 'G1_GO53_HELPER_UPGRADE=PASS;NOTE=already_upgraded'
 try:
  journal={'schema':1,'phase':'prepared','old':OLD,'new':NEW,'backup':{p:base64.b64encode(current[p]).decode('ascii') for p in OLD}}
  durable_json(JOURNAL,journal)
  journal['phase']='replacing';durable_json(JOURNAL,journal)
  replace(HELPER,package[HELPER],MODES[HELPER],hook)
  replace(MANIFEST,package[MANIFEST],MODES[MANIFEST],hook)
  if any(sha(read(p,MODES[p]))!=NEW[p] for p in NEW):raise Stop('post_write_hash_mismatch')
  verify();invariants()
  if any(sha(read(p,MODES[p]))!=NEW[p] for p in NEW):raise Stop('target_hash_missing_before_pass')
  journal['phase']='committed';durable_json(JOURNAL,journal)
  os.unlink(JOURNAL); d=os.open(LIBEXEC,os.O_RDONLY|os.O_DIRECTORY);os.fsync(d);os.close(d)
 except BaseException as exc:
  restored=True
  for p in (MANIFEST,HELPER):
   try:
    if sha(read(p,MODES[p]))!=OLD[p]:replace(p,current[p],MODES[p])
    restored &= sha(read(p,MODES[p]))==OLD[p]
   except BaseException:restored=False
  code=exc.code if isinstance(exc,Stop) else 'internal_error:'+exc.__class__.__name__
  if restored:
   try:
    if os.path.lexists(JOURNAL):os.unlink(JOURNAL)
    d=os.open(LIBEXEC,os.O_RDONLY|os.O_DIRECTORY);os.fsync(d);os.close(d)
   except BaseException:restored=False
  raise Stop(code,'yes' if restored else 'NO')
 return 'G1_GO53_HELPER_UPGRADE=PASS'
def main(pkg,runfn=run,euid=None,replace=atomic,hook=None):
 # /run is root-owned/non-writable by unprivileged users; lock file is root-only and ephemeral.
 try:
  dfd=os.open(os.path.dirname(LOCK_PATH),os.O_RDONLY|os.O_DIRECTORY|getattr(os,'O_NOFOLLOW',0))
  ds=os.fstat(dfd)
  if not stat.S_ISDIR(ds.st_mode) or (ds.st_uid,ds.st_gid)!=(0,0) or stat.S_IMODE(ds.st_mode)&0o022:raise Stop('transaction_lock_directory_unsafe')
 finally:
  if 'dfd' in locals():os.close(dfd)
 flags=os.O_RDWR|os.O_CREAT|getattr(os,'O_NOFOLLOW',0)|getattr(os,'O_CLOEXEC',0)
 try:fd=os.open(LOCK_PATH,flags,0o600)
 except OSError:raise Stop('transaction_lock_unavailable')
 try:
  s=os.fstat(fd)
  if not stat.S_ISREG(s.st_mode) or (s.st_uid,s.st_gid)!=(0,0) or stat.S_IMODE(s.st_mode)!=0o600:raise Stop('transaction_lock_unsafe')
  deadline=time.monotonic()+LOCK_WAIT_SECONDS
  while True:
   try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB);break
   except BlockingIOError:
    if time.monotonic()>=deadline:raise Stop('transaction_lock_timeout')
    time.sleep(0.1)
  return _main_locked(pkg,runfn=runfn,euid=euid,replace=replace,hook=hook)
 finally:
  try:fcntl.flock(fd,fcntl.LOCK_UN)
  finally:os.close(fd)
def cli(argv=None,execute=main):
 argv=sys.argv[1:] if argv is None else argv
 try:
  if len(argv)!=1:raise Stop('usage')
  print(execute(argv[0]));return 0
 except Stop as e:
  print('G1_GO53_HELPER_UPGRADE=STOP;CODE='+e.code+(';ROLLED_BACK='+e.rollback if e.rollback else ''));return 3
 except Exception as e:
  print('G1_GO53_HELPER_UPGRADE=STOP;CODE=internal_error:'+type(e).__name__);return 4
def read_pid(runfn):
 x=runfn(['/usr/bin/systemctl','show','--property=MainPID','--value','mysql.service'])
 if x.returncode or not x.stdout.strip().isdigit():raise Stop('mysql_mainpid_unavailable')
 return x.stdout.strip()
def assert_units_inactive(runfn):
 for u in UNITS:
  x=runfn(['/usr/bin/systemctl','is-active',u])
  if x.returncode!=3 or x.stdout.strip()!='inactive':raise Stop('g1_unit_not_inactive')
if __name__=='__main__':
 sys.exit(cli())
