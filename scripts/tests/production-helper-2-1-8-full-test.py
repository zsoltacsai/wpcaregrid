#!/usr/bin/env python3
"""Focused 2.1.8 production-only confinement and integrity regression tests."""
import hashlib, importlib.util, json, re, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def load(name,path):
 spec=importlib.util.spec_from_file_location(name,ROOT/path); mod=importlib.util.module_from_spec(spec); import sys;sys.modules[name]=mod;spec.loader.exec_module(mod);return mod
C=load('contract217','scripts/deployment_environment_contract_2_1_8.py')
PKG=ROOT/'deploy/php-fpm-confinement/1.0.9'
class ConfinementContract(unittest.TestCase):
 def test_package_pin_is_exact_and_immutable(self):
  pin=C.confinement_pin();self.assertEqual(pin.package_version,'1.0.9');self.assertEqual(len(pin.file_sha256),9)
  sums={line.split('  ',1)[1]:line.split('  ',1)[0] for line in (PKG/'SHA256SUMS').read_text().splitlines()}
  self.assertEqual(hashlib.sha256((PKG/'SHA256SUMS').read_bytes()).hexdigest(),pin.sha256sums_sha256)
  for target,digest in pin.file_sha256.items():
   local=json.loads((PKG/'binding.json').read_text())['installed_files'][target]
   self.assertEqual(sums[local],digest)
 def test_required_roles_and_forbidden_sentinel(self):
  self.assertEqual(C.REQUIRED_ROLES,('production','nginx'))
  self.assertIn('php8.5-fpm.service',C.INVENTORY_SERVICE_UNITS)
  self.assertNotIn('php8.5-fpm.service',[x.unit for x in C.CONFINEMENT_SERVICES.values()])
  self.assertEqual(C.SERVING_ROLES,('production','nginx'))
  self.assertFalse(C.JOB_ROLES)
 def test_unknown_service_observation_fails_closed(self):
  with self.assertRaises(C.EnvironmentRefused): C._verify_services({'production':{},'nginx':{},'staging-next':{}})
 def test_package_binding_digest_is_carried_by_helper_artifacts(self):
  digest=C.confinement_binding_sha256()
  for path in ('scripts/install_production_helper_2_1_8.py','scripts/production_deploy_controller_2_1_8.py'):
   self.assertIn(f'CONFINEMENT_BINDING_SHA256 = "{digest}"',(ROOT/path).read_text())
  manifest=json.loads((ROOT/'deploy/release-manifests/production-deployment-helper-2.1.8.json').read_text())
  self.assertEqual(manifest['php_fpm_confinement_pin']['binding_sha256'],digest)
 def test_secret_and_route_gates_are_separate(self):
  m=json.loads((PKG/'acceptance/matrix.json').read_text())['production_fence']
  self.assertEqual(m['secret_creation_requires'],['peer_live_acceptance_pass'])
  self.assertIn('database_application_gate_pass',m['route_opening_requires'])
  self.assertIn('separate_owner_route_go',m['route_opening_requires'])
  self.assertEqual(m['production_jobs'],'fenced_until_separate_approved_plan')
 def test_collector_scans_unknown_default_fpm_cgroup(self):
  src=(ROOT/'scripts/deployment_backend_v2_1_8.py').read_text()
  self.assertIn('INVENTORY_SERVICE_UNITS',src)
  self.assertIn('php8.5-fpm.service',C.INVENTORY_SERVICE_UNITS)
  self.assertIn('service_members',src)
 def test_staging_1_0_7_and_helper_2_1_6_are_unchanged(self):
  changed=subprocess.run(['git','diff','--name-only','HEAD','--','deploy/php-fpm-confinement/1.0.7','scripts/*2_1_6*','scripts/tests/*2-1-6*'],cwd=ROOT,text=True,capture_output=True,check=True).stdout
  self.assertEqual(changed,'')
 def test_helper_sources_and_manifest_hashes(self):
  m=json.loads((ROOT/'deploy/release-manifests/production-deployment-helper-2.1.8.json').read_text())
  paths={'entrypoint_sha256':'scripts/wpcaregrid-deploy-production-priv-2_1_8.py','backend_sha256':'scripts/deployment_backend_v2_1_8.py','transaction_module_sha256':'scripts/deployment_transaction_v2.py','environment_contract_sha256':'scripts/deployment_environment_contract_2_1_8.py','release_review_policy_sha256':'scripts/release_review_policy_v3_1.py','controller_sha256':'scripts/production_deploy_controller_2_1_8.py','root_installer_sha256':'scripts/install_production_helper_2_1_8.py'}
  for k,p in paths.items():self.assertEqual(m[k],hashlib.sha256((ROOT/p).read_bytes()).hexdigest(),k)
if __name__=='__main__':unittest.main(verbosity=2)
