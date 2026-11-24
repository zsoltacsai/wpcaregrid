#!/usr/bin/env python3
"""Offline assertions for immutable production-only confinement 1.0.8."""
import hashlib, json, subprocess, unittest, importlib.util, sys
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
 def test_nginx_is_narrow_and_has_tls_canaries(self):
  profile=(PKG/'apparmor/wpcaregrid-nginx').read_text()
  self.assertIn('/run/php/wpcaregrid-production-php8.5-fpm.sock rw,',profile)
  self.assertIn('/etc/letsencrypt/live/wpcaregrid.com/{fullchain.pem,privkey.pem} r,',profile)
  self.assertIn('/etc/letsencrypt/archive/wpcaregrid.com/{fullchain[0-9]*.pem,privkey[0-9]*.pem} r,',profile)
  for broad in ('#include <abstractions/ssl_certs>','/etc/nginx/{,**} r,','/etc/ssl/private/{,**} r,','/var/www/*/public/{,**} r,','/run/php/php8.5-fpm.sock rw,'):
   self.assertNotIn(broad,profile)
  self.assertIn('/run/php/php8.4-fpm.{sock,pid}',profile)
  m=json.loads((PKG/'acceptance/matrix.json').read_text())
  checks={c['id']:c for c in m['profiles']['wpcaregrid-nginx']['checks']}
  self.assertEqual(checks['production-tls-certificate']['expect'],'allow')
  self.assertEqual(checks['production-tls-private-key']['expect'],'allow')
  self.assertEqual(checks['other-domain-tls-denied']['expect'],'deny')
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
