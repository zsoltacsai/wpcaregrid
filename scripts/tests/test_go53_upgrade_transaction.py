"""Isolated transaction tests; all paths are temporary and subprocesses are faked."""
import base64, contextlib, fcntl, hashlib, importlib.util, io, json, os, re, signal, sys, tempfile, threading, time, types, unittest
from pathlib import Path

SRC=Path(__file__).parents[1]/'production-g1-ops'/'go53-upgrade-helper.py'
spec=importlib.util.spec_from_file_location('go53',SRC); go=importlib.util.module_from_spec(spec); spec.loader.exec_module(go)

class TxTests(unittest.TestCase):
 def setUp(self):
  self.t=tempfile.TemporaryDirectory(); self.root=Path(self.t.name); self.pkg=self.root/'pkg';self.pkg.mkdir()
  self.lib=self.root/'lib';self.lib.mkdir();self.aa=self.root/'aa';self.aa.mkdir();self.data1=self.root/'p';self.data2=self.root/'r';self.data1.mkdir();self.data2.mkdir()
  self.logs=self.root/'logs';self.logs.mkdir()
  self.orig=(go.LIBEXEC,go.HELPER,go.MANIFEST,go.CONTRACT,go.PROFILE_P,go.PROFILE_R,go.DATADIRS,go.LOGS,go.JOURNAL,go.OLD,go.NEW,go.PINS,go.NAMES,go.MODES,go.UNITS,go.LOCK_PATH)
  self.files=[self.lib/'helper',self.lib/'manifest'];self.paths=dict(zip((go.HELPER,go.MANIFEST),map(str,self.files)))
  self.old={self.paths[self.orig[1]]:b'old helper',self.paths[self.orig[2]]:b'old manifest'};self.new={self.paths[self.orig[1]]:b'new helper',self.paths[self.orig[2]]:b'new manifest'}
  go.LIBEXEC=str(self.lib);go.HELPER=self.paths[go.HELPER];go.MANIFEST=self.paths[go.MANIFEST]
  go.CONTRACT=str(self.lib/'contract');go.PROFILE_P=str(self.aa/'p');go.PROFILE_R=str(self.aa/'r')
  go.DATADIRS=(str(self.data1),str(self.data2));go.LOGS=(str(self.logs/'p'),str(self.logs/'r'));go.JOURNAL=str(self.lib/'journal');go.LOCK_PATH=str(self.root/'run'/'upgrade.lock');Path(go.LOCK_PATH).parent.mkdir()
  go.OLD={go.HELPER:go.sha(self.old[go.HELPER]),go.MANIFEST:go.sha(self.old[go.MANIFEST])};go.NEW={go.HELPER:go.sha(self.new[go.HELPER]),go.MANIFEST:go.sha(self.new[go.MANIFEST])}
  go.PINS={go.CONTRACT:go.sha(b'c'),go.PROFILE_P:go.sha(b'ap'),go.PROFILE_R:go.sha(b'ar')}
  go.pwd.getpwnam=lambda name:types.SimpleNamespace(pw_uid=os.getuid())
  go.grp.getgrnam=lambda name:types.SimpleNamespace(gr_gid=os.getgid())
  go.NAMES={go.HELPER:'helper',go.MANIFEST:'manifest'};go.MODES={go.HELPER:0o755,go.MANIFEST:0o644};go.UNITS=('prod','restore')
  for p,b,mode in [(self.files[0],self.old[go.HELPER],0o755),(self.files[1],self.old[go.MANIFEST],0o644),(Path(go.CONTRACT),b'c',0o644),(Path(go.PROFILE_P),b'ap',0o644),(Path(go.PROFILE_R),b'ar',0o644),(Path(go.LOGS[0]),b'logp',0o600),(Path(go.LOGS[1]),b'logr',0o600)]:p.write_bytes(b);p.chmod(mode)
  for d in (self.data1,self.data2): (d/'data').write_bytes(b'data');(d/'data').chmod(0o600)
  for p,b in self.new.items():
   package_file=self.pkg/go.NAMES[p];package_file.write_bytes(b);package_file.chmod(0o600)
  sums=''.join(f'{go.sha(b)}  {go.NAMES[p]}\n' for p,b in self.new.items())+f'{go.sha(b"c")}  {go.PACKAGE_CONTRACT}\n';(self.pkg/go.PACKAGE_CONTRACT).write_bytes(b'c');(self.pkg/go.PACKAGE_CONTRACT).chmod(0o600);(self.pkg/'SHA256SUMS').write_text(sums);(self.pkg/'SHA256SUMS').chmod(0o600);self.pkg.chmod(0o700)
  self.calls=[];self.active_unit=False;self.activate_after_verify=False
 def tearDown(self):
  (go.LIBEXEC,go.HELPER,go.MANIFEST,go.CONTRACT,go.PROFILE_P,go.PROFILE_R,go.DATADIRS,go.LOGS,go.JOURNAL,go.OLD,go.NEW,go.PINS,go.NAMES,go.MODES,go.UNITS,go.LOCK_PATH)=self.orig
  self.t.cleanup()
 def runner(self,args):
  self.calls.append(args)
  if self.active_unit and args[1:2]==['is-active']:return types.SimpleNamespace(returncode=0,stdout='active')
  if self.activate_after_verify and args[1:2]==['is-active'] and any('STATIC_INSTALL_VERIFY' in c for call in self.calls for c in call):return types.SimpleNamespace(returncode=0,stdout='active')
  if args[1:2]==['show']:return types.SimpleNamespace(returncode=0,stdout='1131\n')
  if args[1:2]==['is-active']:return types.SimpleNamespace(returncode=3,stdout='inactive')
  if args[0].endswith('python3') or args[0].endswith('python'):
   out='STATIC_PREFLIGHT=PASS' if any('STATIC_PREFLIGHT' in x for x in args) else 'STATIC_INSTALL_VERIFY=PASS BASE_CONFIG_CHECK=PASS'
   return types.SimpleNamespace(returncode=0,stdout=out)
  return types.SimpleNamespace(returncode=0,stdout='')
 def install(self,**kw):return go.main(str(self.pkg),runfn=self.runner,euid=0,**kw)
 def test_success(self):self.assertEqual(self.install(),'G1_GO53_HELPER_UPGRADE=PASS');self.assertEqual([Path(p).read_bytes() for p in self.paths.values()],list(self.new.values()))
 def test_idempotent(self):self.install();self.assertIn('already_upgraded',self.install())
 def test_bad_baseline_stops(self):
  self.files[0].write_bytes(b'other')
  with self.assertRaisesRegex(go.Stop,'installed_baseline_mismatch'):self.install()
 def test_package_requires_pinned_unchanged_contract(self):
  (self.pkg/go.PACKAGE_CONTRACT).unlink()
  with self.assertRaisesRegex(go.Stop,'package_contents_unexpected'):self.install()
 def test_package_directory_requires_root_group_and_exact_private_mode(self):
  self.pkg.chmod(0o750)
  with self.assertRaisesRegex(go.Stop,'package_directory_owner_or_mode_invalid'):self.install()
  self.pkg.chmod(0o700)
  os.chown(self.pkg,0,1)
  with self.assertRaisesRegex(go.Stop,'package_directory_owner_or_mode_invalid'):self.install()
 def test_package_files_require_private_mode(self):
  paths=[self.pkg/go.NAMES[go.HELPER],self.pkg/go.NAMES[go.MANIFEST],self.pkg/go.PACKAGE_CONTRACT,self.pkg/'SHA256SUMS']
  for source in paths:
   with self.subTest(name=source.name):
    source.chmod(0o644)
    with self.assertRaisesRegex(go.Stop,'mode_invalid:'+source.name):self.install()
    source.chmod(0o600)
 def test_package_files_require_root_group(self):
  source=self.pkg/go.NAMES[go.HELPER];os.chown(source,0,1)
  expected=r'^owner_invalid:'+re.escape(go.NAMES[go.HELPER])+r'$'
  with self.assertRaisesRegex(go.Stop,expected):self.install()
 def test_package_files_require_root_owner(self):
  source=self.pkg/go.NAMES[go.HELPER];os.chown(source,1,0)
  expected=r'^owner_invalid:'+re.escape(go.NAMES[go.HELPER])+r'$'
  with self.assertRaisesRegex(go.Stop,expected):self.install()
 def test_package_symlink_is_rejected_before_any_host_read(self):
  source=self.pkg/go.NAMES[go.HELPER];source.unlink();source.symlink_to(self.files[0])
  with self.assertRaisesRegex(go.Stop,'package_entry_invalid'):self.install()
 def test_large_mysql_log_is_hashed_in_bounded_chunks(self):
  path=Path(go.LOGS[0]);path.write_bytes(b'x'*(2*1024*1024+17));path.chmod(0o600)
  original=go.os.fdopen;reads=[]
  class Reader:
   def __init__(self,f):self.f=f
   def __enter__(self):return self
   def __exit__(self,*a):self.f.close()
   def read(self,n=-1):reads.append(n);return self.f.read(n)
  go.os.fdopen=lambda *a,**kw:Reader(original(*a,**kw))
  try:self.assertEqual(go.read_mysql_log(str(path)),hashlib.sha256(path.read_bytes()).hexdigest())
  finally:go.os.fdopen=original
  self.assertTrue(reads);self.assertTrue(all(n==1024*1024 for n in reads))
 def test_datadir_files_are_hashed_in_bounded_chunks(self):
  path=self.data1/'data';path.write_bytes(b'x'*(2*1024*1024+17));path.chmod(0o600)
  original=go.os.fdopen;reads=[]
  class Reader:
   def __init__(self,f):self.f=f
   def __enter__(self):return self
   def __exit__(self,*a):self.f.close()
   def read(self,n=-1):reads.append(n);return self.f.read(n)
  go.os.fdopen=lambda *a,**kw:Reader(original(*a,**kw))
  try:self.assertEqual(go.tree_fingerprint(str(self.data1)),go.tree_fingerprint(str(self.data1)))
  finally:go.os.fdopen=original
  self.assertGreaterEqual(len(reads),2);self.assertTrue(all(n==1024*1024 for n in reads))
 def test_failure_after_first_swap_rolls_back(self):
  def repl(path,data,mode,hook=None):
   if path==go.MANIFEST:raise OSError('injected')
   return go.atomic(path,data,mode)
  with self.assertRaises(go.Stop) as e:self.install(replace=repl)
  self.assertEqual(e.exception.rollback,'yes');self.assertEqual([p.read_bytes() for p in self.files],list(self.old.values()))
 def test_failure_after_second_swap_rolls_back(self):
  n=[0]
  def repl(path,data,mode,hook=None):
   n[0]+=1;go.atomic(path,data,mode)
   if n[0]==2:raise OSError('after second rename')
  with self.assertRaises(go.Stop) as e:self.install(replace=repl)
  self.assertEqual(e.exception.rollback,'yes');self.assertEqual([p.read_bytes() for p in self.files],list(self.old.values()))
 def test_sigkill_journal_recovery(self):
  backup={p:base64.b64encode(b).decode() for p,b in self.old.items()};(self.files[0]).write_bytes(self.new[go.HELPER])
  go.durable_json(go.JOURNAL,{'schema':1,'phase':'replacing','old':go.OLD,'new':go.NEW,'backup':backup})
  self.install();self.assertEqual(self.files[0].read_bytes(),self.new[go.HELPER]);self.assertFalse(Path(go.JOURNAL).exists())
 def test_sigkill_after_both_renames_with_replacing_journal_recovers_then_reinstalls_new_pair(self):
  backup={p:base64.b64encode(b).decode() for p,b in self.old.items()}
  for p,b in self.new.items():Path(p).write_bytes(b)
  go.durable_json(go.JOURNAL,{'schema':1,'phase':'replacing','old':go.OLD,'new':go.NEW,'backup':backup})
  self.install();self.assertEqual([p.read_bytes() for p in self.files],list(self.new.values()))
 def test_real_sigkill_during_second_atomic_write_recovers_leftover_temp_and_mixed_pair(self):
  pid=os.fork()
  if pid==0:
   def kill_before_manifest_rename(path):
    if path==go.MANIFEST:os.kill(os.getpid(),signal.SIGKILL)
   go.main(str(self.pkg),runfn=self.runner,euid=0,hook=kill_before_manifest_rename)
   os._exit(99)
  _,status=os.waitpid(pid,0)
  self.assertTrue(os.WIFSIGNALED(status));self.assertEqual(os.WTERMSIG(status),signal.SIGKILL)
  self.assertEqual(self.files[0].read_bytes(),self.new[go.HELPER])
  self.assertEqual(self.files[1].read_bytes(),self.old[go.MANIFEST])
  self.assertTrue(Path(go.MANIFEST+'.g1-upgrade-tmp').is_file())
  self.assertEqual(self.install(),'G1_GO53_HELPER_UPGRADE=PASS')
  self.assertEqual([p.read_bytes() for p in self.files],list(self.new.values()))
  self.assertFalse(Path(go.MANIFEST+'.g1-upgrade-tmp').exists())
 def test_symlink_temporary_file_is_not_removed_even_with_valid_replacing_journal(self):
  backup={p:base64.b64encode(b).decode() for p,b in self.old.items()};self.files[0].write_bytes(self.new[go.HELPER])
  go.durable_json(go.JOURNAL,{'schema':1,'phase':'replacing','old':go.OLD,'new':go.NEW,'backup':backup})
  target=self.root/'outside';target.write_text('untouched')
  Path(go.HELPER+'.g1-upgrade-tmp').symlink_to(target)
  with self.assertRaisesRegex(go.Stop,'leftover_temporary_file_unrecognized'):self.install()
  self.assertEqual(target.read_text(),'untouched');self.assertTrue(Path(go.JOURNAL).exists())
 def test_active_unit_stops_before_journal_recovery_writes(self):
  backup={p:base64.b64encode(b).decode() for p,b in self.old.items()};self.files[0].write_bytes(self.new[go.HELPER])
  go.durable_json(go.JOURNAL,{'schema':1,'phase':'replacing','old':go.OLD,'new':go.NEW,'backup':backup})
  self.active_unit=True
  with self.assertRaisesRegex(go.Stop,'g1_unit_not_inactive'):self.install()
  self.assertEqual(self.files[0].read_bytes(),self.new[go.HELPER]);self.assertTrue(Path(go.JOURNAL).exists())
 def test_unsafe_target_directories_stop_before_any_write(self):
  for d,code in ((self.lib,'libexec_directory_unsafe'),(self.aa,'apparmor_directory_unsafe')):
   with self.subTest(code=code):
    d.chmod(0o775)
    try:
     with self.assertRaisesRegex(go.Stop,code):self.install()
    finally:d.chmod(0o755)
    self.assertEqual([Path(p).read_bytes() for p in self.paths.values()],list(self.old.values()));self.assertFalse(Path(go.JOURNAL).exists())
  real=self.root/'real-lib';real.mkdir();link=self.root/'lib-link';link.symlink_to(real);go.LIBEXEC=str(link)
  with self.assertRaisesRegex(go.Stop,'libexec_directory_unsafe'):self.install()
 def test_read_takes_type_owner_and_mode_from_the_opened_descriptor_and_refuses_symlinks(self):
  target=self.root/'t';target.write_bytes(b'x');target.chmod(0o644);link=self.root/'l';link.symlink_to(target)
  with self.assertRaisesRegex(go.Stop,'not_regular:l'):go.read(str(link))
  fifo=self.root/'f';os.mkfifo(fifo)
  with self.assertRaisesRegex(go.Stop,'not_regular:f'):go.read(str(fifo))
  with self.assertRaisesRegex(go.Stop,'mode_invalid:t'):go.read(str(target),0o600)
  self.assertEqual(go.read(str(target),0o644),b'x')
 def test_journal_write_failure_leaves_no_temporary_file(self):
  real=os.replace
  def fail(*a,**k):raise OSError('injected')
  os.replace=fail
  try:
   with self.assertRaises(OSError):go.durable_json(go.JOURNAL,{'schema':1})
  finally:os.replace=real
  self.assertFalse(Path(go.JOURNAL+'.tmp').exists());self.assertFalse(Path(go.JOURNAL).exists())
 def test_corrupt_journal_fails_closed(self):
  Path(go.JOURNAL).write_text('{bad');Path(go.JOURNAL).chmod(0o600)
  with self.assertRaisesRegex(go.Stop,'journal_corrupt'):self.install()
 def test_no_journal_mixed_hash_fails_closed(self):
  self.files[0].write_bytes(self.new[go.HELPER])
  with self.assertRaisesRegex(go.Stop,'installed_baseline_mismatch'):self.install()
 def test_service_commands_never_include_start_or_initialize(self):
  self.install()
  flat=' '.join(' '.join(c) for c in self.calls);self.assertNotIn(' start ',flat);self.assertNotIn(' initialize ',flat)
 def test_symlink_in_datadir_is_rejected(self):
  target=self.root/'outside';target.write_text('secret');(self.data1/'link').symlink_to(target)
  with self.assertRaisesRegex(go.Stop,'datadir_symlink'):self.install()
 def test_traversal_error_fails_closed(self):
  original=go.os.fwalk
  def denied(*args,**kwargs):
   kwargs['onerror'](PermissionError('injected'))
   return iter(())
  go.os.fwalk=denied
  try:
   with self.assertRaisesRegex(go.Stop,'datadir_traversal_failed'):self.install()
  finally:go.os.fwalk=original
 def test_unit_activation_during_verification_blocks_success(self):
  self.activate_after_verify=True
  with self.assertRaisesRegex(go.Stop,'g1_unit_not_inactive'):self.install()
  self.assertEqual([Path(p).read_bytes() for p in self.paths.values()],list(self.old.values()))
 def test_parallel_transactions_are_serialized_by_directory_flock(self):
  entered=[]; first=threading.Event(); release=threading.Event(); original=go._main_locked
  def body(*args,**kwargs):
   entered.append(threading.get_ident())
   if len(entered)==1:first.set();release.wait(3)
   return 'G1_GO53_HELPER_UPGRADE=PASS'
  go._main_locked=body
  result=[]
  try:
   a=threading.Thread(target=lambda:result.append(go.main(str(self.pkg),euid=0)))
   b=threading.Thread(target=lambda:result.append(go.main(str(self.pkg),euid=0)))
   a.start();self.assertTrue(first.wait(2));b.start();time.sleep(.15)
   self.assertEqual(len(entered),1,'second transaction entered before first released lock')
   release.set();a.join(2);b.join(2)
   self.assertEqual(len(entered),2);self.assertEqual(result,['G1_GO53_HELPER_UPGRADE=PASS']*2)
  finally:release.set();go._main_locked=original
 def test_lock_wait_is_bounded_and_does_not_enter_transaction(self):
  fd=os.open(go.LOCK_PATH,os.O_RDWR|os.O_CREAT,0o600);fcntl.flock(fd,fcntl.LOCK_EX)
  old=go.LOCK_WAIT_SECONDS;go.LOCK_WAIT_SECONDS=0
  try:
   with self.assertRaisesRegex(go.Stop,'transaction_lock_timeout'):go.main(str(self.pkg),runfn=self.runner,euid=0)
   self.assertEqual(self.calls,[])
  finally:go.LOCK_WAIT_SECONDS=old;fcntl.flock(fd,fcntl.LOCK_UN);os.close(fd)
 def test_cli_success_returns_zero_without_converting_system_exit_into_failure(self):
  output=io.StringIO()
  with contextlib.redirect_stdout(output):rc=go.cli(['package'],execute=lambda _: 'G1_GO53_HELPER_UPGRADE=PASS')
  self.assertEqual(rc,0);self.assertEqual(output.getvalue().strip(),'G1_GO53_HELPER_UPGRADE=PASS')
 def test_pinned_log_paths_match_the_contract(self):
  contract_path=Path(__file__).parents[1]/'production_g1_mysql_contract.py'
  cs=importlib.util.spec_from_file_location('go53_contract_for_test',contract_path);contract=importlib.util.module_from_spec(cs);sys.modules[cs.name]=contract;cs.loader.exec_module(contract)
  self.assertEqual(self.orig[7],tuple(instance.log_file for instance in contract.INSTANCES.values()))
 def test_structural_verification_never_calls_dynamic_path_or_mysql_checks(self):
  source=SRC.read_text(encoding='utf-8')
  verify=source.split('def verify():',1)[1].split('if already:',1)[0]
  self.assertNotIn('verify_paths',verify);self.assertNotIn('verify_preflight',verify);self.assertNotIn('verify-install',verify)

if __name__=='__main__':unittest.main(verbosity=2)
