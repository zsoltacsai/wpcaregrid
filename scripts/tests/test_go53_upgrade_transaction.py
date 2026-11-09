"""Isolated transaction tests; all paths are temporary and subprocesses are faked."""
import base64, hashlib, importlib.util, json, os, tempfile, types, unittest
from pathlib import Path

SRC=Path(__file__).parents[1]/'production-g1-ops'/'go53-upgrade-helper.py'
spec=importlib.util.spec_from_file_location('go53',SRC); go=importlib.util.module_from_spec(spec); spec.loader.exec_module(go)

class TxTests(unittest.TestCase):
 def setUp(self):
  self.t=tempfile.TemporaryDirectory(); self.root=Path(self.t.name); self.pkg=self.root/'pkg';self.pkg.mkdir()
  self.lib=self.root/'lib';self.lib.mkdir();self.aa=self.root/'aa';self.aa.mkdir();self.data1=self.root/'p';self.data2=self.root/'r';self.data1.mkdir();self.data2.mkdir()
  self.logs=self.root/'logs';self.logs.mkdir()
  self.orig=(go.LIBEXEC,go.HELPER,go.MANIFEST,go.CONTRACT,go.PROFILE_P,go.PROFILE_R,go.DATADIRS,go.LOGS,go.JOURNAL,go.OLD,go.NEW,go.PINS,go.NAMES,go.MODES,go.UNITS)
  self.files=[self.lib/'helper',self.lib/'manifest'];self.paths=dict(zip((go.HELPER,go.MANIFEST),map(str,self.files)))
  self.old={self.paths[self.orig[1]]:b'old helper',self.paths[self.orig[2]]:b'old manifest'};self.new={self.paths[self.orig[1]]:b'new helper',self.paths[self.orig[2]]:b'new manifest'}
  go.LIBEXEC=str(self.lib);go.HELPER=self.paths[go.HELPER];go.MANIFEST=self.paths[go.MANIFEST]
  go.CONTRACT=str(self.lib/'contract');go.PROFILE_P=str(self.aa/'p');go.PROFILE_R=str(self.aa/'r')
  go.DATADIRS=(str(self.data1),str(self.data2));go.LOGS=(str(self.logs/'p'),str(self.logs/'r'));go.JOURNAL=str(self.lib/'journal')
  go.OLD={go.HELPER:go.sha(self.old[go.HELPER]),go.MANIFEST:go.sha(self.old[go.MANIFEST])};go.NEW={go.HELPER:go.sha(self.new[go.HELPER]),go.MANIFEST:go.sha(self.new[go.MANIFEST])}
  go.PINS={go.CONTRACT:go.sha(b'c'),go.PROFILE_P:go.sha(b'ap'),go.PROFILE_R:go.sha(b'ar')}
  go.pwd.getpwnam=lambda name:types.SimpleNamespace(pw_uid=os.getuid())
  go.grp.getgrnam=lambda name:types.SimpleNamespace(gr_gid=os.getgid())
  go.NAMES={go.HELPER:'helper',go.MANIFEST:'manifest'};go.MODES={go.HELPER:0o755,go.MANIFEST:0o644};go.UNITS=('prod','restore')
  for p,b,mode in [(self.files[0],self.old[go.HELPER],0o755),(self.files[1],self.old[go.MANIFEST],0o644),(Path(go.CONTRACT),b'c',0o644),(Path(go.PROFILE_P),b'ap',0o644),(Path(go.PROFILE_R),b'ar',0o644),(Path(go.LOGS[0]),b'logp',0o600),(Path(go.LOGS[1]),b'logr',0o600)]:p.write_bytes(b);p.chmod(mode)
  for d in (self.data1,self.data2): (d/'data').write_bytes(b'data');(d/'data').chmod(0o600)
  for p,b in self.new.items():(self.pkg/go.NAMES[p]).write_bytes(b)
  sums=''.join(f'{go.sha(b)}  {go.NAMES[p]}\n' for p,b in self.new.items());(self.pkg/'SHA256SUMS').write_text(sums);self.pkg.chmod(0o700)
  self.calls=[];self.active_unit=False;self.activate_after_verify=False
 def tearDown(self):
  (go.LIBEXEC,go.HELPER,go.MANIFEST,go.CONTRACT,go.PROFILE_P,go.PROFILE_R,go.DATADIRS,go.LOGS,go.JOURNAL,go.OLD,go.NEW,go.PINS,go.NAMES,go.MODES,go.UNITS)=self.orig
  self.t.cleanup()
 def runner(self,args):
  self.calls.append(args)
  if self.active_unit and args[1:2]==['is-active']:return types.SimpleNamespace(returncode=0,stdout='active')
  if self.activate_after_verify and args[1:2]==['is-active'] and any('STATIC_PREFLIGHT' in c for call in self.calls for c in call):return types.SimpleNamespace(returncode=0,stdout='active')
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
 def test_active_unit_stops_before_journal_recovery_writes(self):
  backup={p:base64.b64encode(b).decode() for p,b in self.old.items()};self.files[0].write_bytes(self.new[go.HELPER])
  go.durable_json(go.JOURNAL,{'schema':1,'phase':'replacing','old':go.OLD,'new':go.NEW,'backup':backup})
  self.active_unit=True
  with self.assertRaisesRegex(go.Stop,'g1_unit_not_inactive'):self.install()
  self.assertEqual(self.files[0].read_bytes(),self.new[go.HELPER]);self.assertTrue(Path(go.JOURNAL).exists())
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

if __name__=='__main__':unittest.main(verbosity=2)
