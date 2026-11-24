#!/usr/bin/env python3
"""Independent versioned layout and immutable predecessor checks for helper 2.1.7."""
import hashlib, importlib.util, json, subprocess, sys, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];sys.dont_write_bytecode=True

def load(name, rel):
 spec=importlib.util.spec_from_file_location(name,ROOT/rel);mod=importlib.util.module_from_spec(spec);sys.modules[name]=mod;spec.loader.exec_module(mod);return mod
C=load('contract217_coexistence','scripts/deployment_environment_contract_2_1_7.py')
I=load('installer217_coexistence','scripts/install_production_helper_2_1_7.py')
PKG=ROOT/'deploy/php-fpm-confinement/1.0.8'
class Helper217Coexistence(unittest.TestCase):
 def test_versioned_paths_do_not_overlap_immutable_216(self):
  self.assertEqual(I.VERSION,'2.1.7')
  self.assertEqual(str(I.PRODUCTION.helper_dir),'/usr/local/libexec/wpcaregrid-deploy/production/2.1.7')
  self.assertEqual(str(I.PRODUCTION.policy),'/etc/wpcaregrid-deploy/profiles/production-v2.1.7.json')
  self.assertEqual(str(I.PRODUCTION.inbox),'/var/lib/wpcaregrid-deploy/production-helper-inbox/2.1.7')
  for path in (str(I.PRODUCTION.helper_dir),str(I.PRODUCTION.policy),str(I.PRODUCTION.inbox)):
   self.assertNotIn('2.1.6',path)
 def test_confinement_roles_and_package_binding(self):
  self.assertEqual(C.REQUIRED_ROLES,('production','nginx'))
  b=json.loads((PKG/'binding.json').read_text())
  self.assertEqual(set(b['services']),{'production','nginx'})
  self.assertEqual(set(b['installed_files']),set(C.CONFINEMENT_FILES))
  self.assertEqual(C.confinement_pin().package_version,'1.0.8')
  self.assertIn('php8.5-fpm.service',C.INVENTORY_SERVICE_UNITS)
  self.assertNotIn('php8.5-fpm.service',[v.unit for v in C.CONFINEMENT_SERVICES.values()])
 def test_manifest_pins_all_exact_helper_sources(self):
  m=json.loads((ROOT/'deploy/release-manifests/production-deployment-helper-2.1.7.json').read_text())
  files={'entrypoint_sha256':'scripts/wpcaregrid-deploy-production-priv-2_1_7.py','backend_sha256':'scripts/deployment_backend_v2_1_7.py','transaction_module_sha256':'scripts/deployment_transaction_v2.py','environment_contract_sha256':'scripts/deployment_environment_contract_2_1_7.py','release_review_policy_sha256':'scripts/release_review_policy_v3_1.py','controller_sha256':'scripts/production_deploy_controller_2_1_7.py','root_installer_sha256':'scripts/install_production_helper_2_1_7.py'}
  for key,path in files.items():self.assertEqual(m[key],hashlib.sha256((ROOT/path).read_bytes()).hexdigest(),key)
  self.assertEqual(m['php_fpm_confinement_pin']['binding_sha256'],C.confinement_binding_sha256())
  self.assertEqual(m['php_fpm_confinement_pin']['services'],['production','nginx'])
 def test_old_release_trees_are_unchanged(self):
  changed=subprocess.run(['git','diff','--name-only','HEAD','--','deploy/php-fpm-confinement/1.0.7','scripts/deployment_environment_contract_2_1_6.py','scripts/deployment_backend_v2_1_6.py','scripts/wpcaregrid-deploy-production-priv-2_1_6.py','scripts/production_deploy_controller_2_1_6.py','scripts/install_production_helper_2_1_6.py'],cwd=ROOT,text=True,capture_output=True,check=True).stdout
  self.assertEqual(changed,'')
 def test_docs_pin_table_matches_installer_hash(self):
  doc=(ROOT/'docs/PRODUCTION_HELPER_INSTALL_2_1_7.md').read_text();start=doc.index('<!-- PINS:BEGIN -->') if '<!-- PINS:BEGIN -->' in doc else doc.rfind('| Artifact | SHA-256 |')
  block=doc[start:]
  manifest=json.loads((ROOT/'deploy/release-manifests/production-deployment-helper-2.1.7.json').read_text())
  paths={'entrypoint_sha256':'scripts/wpcaregrid-deploy-production-priv-2_1_7.py',
         'backend_sha256':'scripts/deployment_backend_v2_1_7.py','transaction_module_sha256':'scripts/deployment_transaction_v2.py',
         'environment_contract_sha256':'scripts/deployment_environment_contract_2_1_7.py',
         'release_review_policy_sha256':'scripts/release_review_policy_v3_1.py',
         'controller_sha256':'scripts/production_deploy_controller_2_1_7.py',
         'root_installer_sha256':'scripts/install_production_helper_2_1_7.py'}
  for key,path in paths.items():
   digest=hashlib.sha256((ROOT/path).read_bytes()).hexdigest()
   self.assertEqual(manifest[key],digest,key)
   self.assertIn(f'| `{path}` | `{digest}` |',block)
if __name__=='__main__':unittest.main(verbosity=2)
