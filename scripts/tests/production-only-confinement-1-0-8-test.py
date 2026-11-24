#!/usr/bin/env python3
"""Offline assertions for immutable production-only confinement 1.0.8."""
import hashlib, json, subprocess, unittest, importlib.util, sys, tempfile, os
from unittest.mock import patch
sys.dont_write_bytecode=True
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
PKG=ROOT/'deploy/php-fpm-confinement/1.0.8'
class ProductionOnlyPackage(unittest.TestCase):
 def test_exact_installed_files_and_hash_manifest(self):
  binding=json.loads((PKG/'binding.json').read_text())
  sums={}
  for line in (PKG/'SHA256SUMS').read_text().splitlines():
   digest,name=line.split('  ',1); sums[name]=digest
  actual={p.relative_to(PKG).as_posix() for p in PKG.rglob('*') if p.is_file() and p.name!='SHA256SUMS'}
  self.assertEqual(set(sums),actual)
  for name,digest in sums.items(): self.assertEqual(hashlib.sha256((PKG/name).read_bytes()).hexdigest(),digest,name)
  self.assertEqual(set(binding['installed_files']),{
   '/etc/apparmor.d/abstractions/wpcaregrid-php-fpm-common','/etc/apparmor.d/wpcaregrid-nginx',
   '/etc/apparmor.d/wpcaregrid-php-cli-production-build','/etc/apparmor.d/wpcaregrid-php-cli-production-deploy',
   '/etc/apparmor.d/wpcaregrid-php-fpm-production','/etc/php/8.5/fpm-wpcaregrid-production/php-fpm.conf',
   '/etc/php/8.5/fpm-wpcaregrid-production/pool.d/wpcaregrid-production.conf',
   '/etc/systemd/system/nginx.service.d/90-wpcaregrid-confinement.conf',
   '/etc/systemd/system/wpcaregrid-production-php8.5-fpm.service'})
 def test_role_matrix_is_production_only_and_fail_closed(self):
  m=json.loads((PKG/'acceptance/matrix.json').read_text())
  self.assertEqual(set(m['role_units']),{'production','nginx'})
  self.assertEqual(set(m['role_profiles']),{'production','nginx'})
  self.assertEqual(set(m['role_required']),{'production','nginx'})
  self.assertEqual(set(m['role_runtime']['roles']),{'production'})
  self.assertEqual(set(m['inventory_units']),{'nginx.service','wpcaregrid-production-php8.5-fpm.service','php8.5-fpm.service'})
  self.assertNotIn('php8.5-fpm.service',m['role_units'].values())
  self.assertFalse(m['permitted_group33_holders'])
  self.assertTrue(all(set(s.get('peer_roles',[])) <= {'production','nginx'} for s in m['profiles'].values()))
  src=(PKG/'acceptance/verify-acceptance.py').read_text()
  self.assertIn('inv.get("complete") is not True',src)
  self.assertIn('maps to no permitted class',src)
  self.assertIn('unknown',src.lower())
  collector=(PKG/'acceptance/collect-peers.py').read_text()
  self.assertIn('uid not in gids and uid not in groups',collector)
  self.assertIn('inventory_units',collector)
  rollback=(PKG/'acceptance/rollback-order.py').read_text()
  self.assertIn('PEERS_NEWEST_FIRST = ("nginx", "production")',rollback)
  self.assertNotIn('staging-next',rollback)
 def test_nginx_is_narrow_and_has_tls_canaries(self):
  profile=(PKG/'apparmor/wpcaregrid-nginx').read_text()
  self.assertIn('/run/php/wpcaregrid-production-php8.5-fpm.sock rw,',profile)
  self.assertIn('/etc/letsencrypt/live/wpcaregrid.com/{fullchain.pem,privkey.pem} r,',profile)
  self.assertIn('/etc/letsencrypt/archive/wpcaregrid.com/{fullchain[0-9]*.pem,privkey[0-9]*.pem} r,',profile)
  for broad in ('#include <abstractions/ssl_certs>','/etc/nginx/{,**} r,','/etc/ssl/private/{,**} r,','/var/www/*/public/{,**} r,','/run/php/php8.5-fpm.sock rw,'):
   self.assertNotIn(broad,profile)
  self.assertIn('/run/php/php8.4-fpm.{sock,pid}',profile)
  self.assertIn('/etc/nginx/sites-enabled/ r,',profile)
  self.assertIn('/etc/nginx/sites-enabled/ x,',profile)
  self.assertIn('/etc/nginx/sites-available/ x,',profile)
  self.assertNotIn('/var/lib/wpcaregrid-deploy/production/state/maintenance r,',profile)
  m=json.loads((PKG/'acceptance/matrix.json').read_text())
  checks={c['id']:c for c in m['profiles']['wpcaregrid-nginx']['checks']}
  self.assertEqual(checks['no-production-state-read']['expect'],'deny')
  self.assertEqual(checks['production-tls-certificate']['expect'],'allow')
  self.assertEqual(checks['production-tls-private-key']['expect'],'allow')
  self.assertEqual(checks['other-domain-tls-denied']['expect'],'deny')
  self.assertNotIn('staging-next-pool-config-read', {c['id'] for c in m['profiles']['wpcaregrid-php-fpm-production']['checks']})

 def test_nginx_descriptor_policy_uses_narrow_component_boundaries(self):
  path=PKG/'acceptance/verify-acceptance.py';spec=importlib.util.spec_from_file_location('verify108_fd',path);v=importlib.util.module_from_spec(spec);sys.modules[spec.name]=v;spec.loader.exec_module(v)
  for allowed in ('/etc/nginx/sites-enabled/wpcaregrid-production','/etc/nginx/sites-available/wpcaregrid-production',
                  '/var/www/wpcaregrid-production/releases/r1/public/index.php','/run/php/wpcaregrid-production-php8.5-fpm.sock',
                  'socket:[123]','/etc/letsencrypt/archive/wpcaregrid.com/privkey12.pem'):
   self.assertTrue(v.fd_ok('nginx',allowed),allowed)
  for denied in ('/etc/nginx/sites-enabled/wpcaregrid-production-evil','/var/www/other/public/index.php',
                 '/usr/local/etc/secret','/var/www/wpcaregrid-production/releases/r1/publicity/index.php',
                 '/run/php/php8.4-fpm.sock','/tmp/secret','/var/www/wpcaregrid-production/releases/r1/public/../.env'):
   self.assertFalse(v.fd_ok('nginx',denied),denied)

 def test_production_surface_walk_errors_and_symlinks_are_incomplete_or_real(self):
  p=PKG/'acceptance/collect-peers.py';spec=importlib.util.spec_from_file_location('collect108_surface',p);c=importlib.util.module_from_spec(spec);sys.modules[spec.name]=c;spec.loader.exec_module(c)
  with tempfile.TemporaryDirectory(prefix='wpcg-prod-surface-') as tmp:
   root=Path(tmp)/'surface'; root.mkdir(); (root/'private').mkdir(); (root/'private'/'data').write_text('x')
   (root/'private'/'escape').symlink_to('/tmp')
   scan=c.production_surface([str(root)],'CANARY','/never/public')
   self.assertTrue(scan['complete']); self.assertEqual(set(scan['real_files']),{str(root/'private'/'data'),str(root/'private'/'escape')})
   scan=c.production_surface([str(root/'private'/'escape')],'CANARY','/never/public')
   self.assertFalse(scan['complete']); self.assertTrue(scan['real_files'])
   with patch.object(c.os,'scandir',side_effect=PermissionError('injected')):
    scan=c.production_surface([str(root)],'CANARY','/never/public')
   self.assertFalse(scan['complete']); self.assertTrue(scan['reasons'])

 def test_fixture_receipt_cannot_escape_or_follow_symlink_ancestors(self):
  p=PKG/'acceptance/fixtures.py';spec=importlib.util.spec_from_file_location('fixtures108_safe',p);f=importlib.util.module_from_spec(spec);sys.modules[spec.name]=f;spec.loader.exec_module(f)
  with tempfile.TemporaryDirectory(prefix='wpcg-fixtures-safe-') as tmp:
   root=Path(tmp); (root/'etc').mkdir(); outside=root/'victim'; outside.write_text('do not delete')
   fixtures={'mkdirs':[],'files':[{'path':'/etc/canary'}],'symlinks':[]}
   receipt=root/'receipt.json'; receipt.write_text(json.dumps({'dirs':[],'files':['/../../victim'],'symlinks':[]}))
   with self.assertRaises(f.Refused): f.remove(fixtures,str(root),str(receipt))
   self.assertEqual(outside.read_text(),'do not delete')
   (root/'etc').rmdir(); (root/'etc').symlink_to(root,target_is_directory=True)
   with self.assertRaises(f.Refused): f.preflight(fixtures,str(root))
   self.assertEqual(outside.read_text(),'do not delete')
 def test_fixture_create_recovery_and_remove_are_idempotent(self):
  p=PKG/'acceptance/fixtures.py';spec=importlib.util.spec_from_file_location('fixtures108_recovery',p);f=importlib.util.module_from_spec(spec);sys.modules[spec.name]=f;spec.loader.exec_module(f)
  with tempfile.TemporaryDirectory(prefix='wpcg-fixtures-recovery-') as tmp:
   root=Path(tmp); (root/'var/lib/wpcg').mkdir(parents=True)
   fixtures={'mkdirs':[{'path':'/var/lib/wpcg','uid':os.getuid(),'gid':os.getgid(),'mode':'0700'}],
             'files':[{'path':'/var/lib/wpcg/canary','uid':os.getuid(),'gid':os.getgid(),'mode':'0600'}],
             'symlinks':[{'path':'/var/lib/wpcg/current','target':'canary'}]}
   canary=root/'var/lib/wpcg/canary';canary.write_text(f.CANARY)
   (root/'var/lib/wpcg/current').symlink_to('canary')
   receipt=root/'receipt.json'
   recovered=f.create(fixtures,str(root),str(receipt),False)
   self.assertEqual(recovered['files'],['/var/lib/wpcg/canary'])
   self.assertEqual(recovered['symlinks'],['/var/lib/wpcg/current'])
   recovered_again=f.create(fixtures,str(root),str(receipt),False)
   self.assertEqual(recovered_again,recovered)
   result=f.remove(fixtures,str(root),str(receipt))
   self.assertEqual(set(result['removed']),{'/var/lib/wpcg/canary','/var/lib/wpcg/current'})
   self.assertEqual(f.status(fixtures,str(root)),[])
   self.assertTrue((root/'var/lib/wpcg').is_dir())
 def test_acceptance_rejects_incomplete_unknown_and_unconfined_peers(self):
  path=PKG/'acceptance/verify-acceptance.py';spec=importlib.util.spec_from_file_location('verify108',path);vmod=importlib.util.module_from_spec(spec);sys.modules[spec.name]=vmod;spec.loader.exec_module(vmod)
  matrix=json.loads((PKG/'acceptance/matrix.json').read_text());digest=hashlib.sha256((PKG/'acceptance/matrix.json').read_bytes()).hexdigest()
  units={u:{'present':True,'pids':[]} for u in matrix['inventory_units']}
  base={'schema':2,'complete':True,'scans':2,'matrix_sha256':digest,'boot_id':'a'*32,'units':units,'processes':[]}
  verdict=vmod.Verdict();vmod.check_inventory(verdict,matrix,dict(base,complete=False), 'pre',list(matrix['role_units']),'pre',digest,'a'*32)
  self.assertTrue(any('inventory incomplete' in x for x in verdict.failures))
  for proc in ({'pid':991,'label':'unconfined','unit':None,'comm':'unknown-peer','uids':[1000]*4,'gids':[1000]*4,'groups':[33],'fds':[]},
               {'pid':992,'label':'unconfined','unit':'php8.5-fpm.service','comm':'php-fpm8.5','uids':[0]*4,'gids':[0]*4,'groups':[],'fds':[]}):
   inv=dict(base,processes=[proc]);verdict=vmod.Verdict();vmod.check_inventory(verdict,matrix,inv,'pre',list(matrix['role_units']),'pre',digest,'a'*32)
   self.assertTrue(any('maps to no permitted class' in x or 'service cgroup' in x for x in verdict.failures),verdict.failures)
 def test_separate_secret_db_and_route_gates(self):
  m=json.loads((PKG/'acceptance/matrix.json').read_text())['production_fence']
  self.assertEqual(m['secret_creation_requires'],['peer_live_acceptance_pass'])
  self.assertEqual(m['route_opening_requires'],['peer_live_acceptance_pass','secret_gate_pass','database_application_gate_pass','separate_owner_route_go'])
  self.assertEqual(m['production_jobs'],'fenced_until_separate_approved_plan')
 def test_old_release_bytes_untouched_on_feature_branch(self):
  changed=subprocess.run(['git','diff','--name-only','HEAD','--','deploy/php-fpm-confinement/1.0.7','scripts/*2_1_6*','scripts/tests/*2-1-6*'],cwd=ROOT,text=True,capture_output=True,check=True).stdout
  self.assertEqual(changed,'')
if __name__=='__main__': unittest.main(verbosity=2)
