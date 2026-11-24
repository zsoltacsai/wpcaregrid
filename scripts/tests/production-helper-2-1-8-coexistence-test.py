#!/usr/bin/env python3
"""Independent versioned layout and immutable predecessor checks for helper 2.1.8."""
import hashlib, importlib.util, json, subprocess, sys, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];sys.dont_write_bytecode=True

def load(name, rel):
 spec=importlib.util.spec_from_file_location(name,ROOT/rel);mod=importlib.util.module_from_spec(spec);sys.modules[name]=mod;spec.loader.exec_module(mod);return mod
C=load('contract217_coexistence','scripts/deployment_environment_contract_2_1_8.py')
I=load('installer217_coexistence','scripts/install_production_helper_2_1_8.py')
PKG=ROOT/'deploy/php-fpm-confinement/1.0.9'
class Helper217Coexistence(unittest.TestCase):
 def test_versioned_paths_do_not_overlap_immutable_216(self):
  self.assertEqual(I.VERSION,'2.1.8')
  self.assertEqual(str(I.PRODUCTION.helper_dir),'/usr/local/libexec/wpcaregrid-deploy/production/2.1.8')
  self.assertEqual(str(I.PRODUCTION.policy),'/etc/wpcaregrid-deploy/profiles/production-v2.1.8.json')
  self.assertEqual(str(I.PRODUCTION.inbox),'/var/lib/wpcaregrid-deploy/production-helper-inbox/2.1.8')
  for path in (str(I.PRODUCTION.helper_dir),str(I.PRODUCTION.policy),str(I.PRODUCTION.inbox)):
   self.assertNotIn('2.1.6',path)
 def test_confinement_roles_and_package_binding(self):
  self.assertEqual(C.REQUIRED_ROLES,('production','nginx'))
  b=json.loads((PKG/'binding.json').read_text())
  self.assertEqual(set(b['services']),{'production','nginx'})
  self.assertEqual(set(b['installed_files']),set(C.CONFINEMENT_FILES))
  self.assertEqual(C.confinement_pin().package_version,'1.0.9')
  self.assertIn('php8.5-fpm.service',C.INVENTORY_SERVICE_UNITS)
  self.assertNotIn('php8.5-fpm.service',[v.unit for v in C.CONFINEMENT_SERVICES.values()])
 def test_manifest_pins_all_exact_helper_sources(self):
  m=json.loads((ROOT/'deploy/release-manifests/production-deployment-helper-2.1.8.json').read_text())
  files={'entrypoint_sha256':'scripts/wpcaregrid-deploy-production-priv-2_1_8.py','backend_sha256':'scripts/deployment_backend_v2_1_8.py','transaction_module_sha256':'scripts/deployment_transaction_v2.py','environment_contract_sha256':'scripts/deployment_environment_contract_2_1_8.py','release_review_policy_sha256':'scripts/release_review_policy_v3_1.py','controller_sha256':'scripts/production_deploy_controller_2_1_8.py','root_installer_sha256':'scripts/install_production_helper_2_1_8.py'}
  for key,path in files.items():self.assertEqual(m[key],hashlib.sha256((ROOT/path).read_bytes()).hexdigest(),key)
  self.assertEqual(m['php_fpm_confinement_pin']['binding_sha256'],C.confinement_binding_sha256())
  self.assertEqual(m['php_fpm_confinement_pin']['services'],['production','nginx'])
 def test_predecessor_packages_and_helpers_match_their_committed_pins(self):
  for helper,package in (('2.1.6','1.0.7'),('2.1.7','1.0.8')):
   manifest=json.loads((ROOT/f'deploy/release-manifests/production-deployment-helper-{helper}.json').read_text())
   pin=manifest['php_fpm_confinement_pin']
   package_path=f'deploy/php-fpm-confinement/{package}'
   tree=subprocess.run(['git','rev-parse',f'HEAD:{package_path}'],cwd=ROOT,text=True,capture_output=True,check=True).stdout.strip()
   self.assertEqual(tree,pin['package_tree'],package)
   sums=ROOT/package_path/'SHA256SUMS'
   self.assertEqual(hashlib.sha256(sums.read_bytes()).hexdigest(),pin['sha256sums_sha256'],package)
   artifact_paths={'entrypoint_sha256':manifest['entrypoint'],'backend_sha256':manifest['backend'],
                   'transaction_module_sha256':manifest['transaction_module'],
                   'environment_contract_sha256':manifest['environment_contract'],
                   'release_review_policy_sha256':manifest['release_review_policy_module'],
                   'controller_sha256':manifest['controller'],'root_installer_sha256':manifest['root_installer']}
   for key,path in artifact_paths.items():
    self.assertEqual(hashlib.sha256((ROOT/path).read_bytes()).hexdigest(),manifest[key],f'{helper}:{path}')
 def test_docs_pin_table_matches_installer_hash(self):
  doc=(ROOT/'docs/PRODUCTION_HELPER_INSTALL_2_1_8.md').read_text();start=doc.index('<!-- PINS:BEGIN -->') if '<!-- PINS:BEGIN -->' in doc else doc.rfind('| Artifact | SHA-256 |')
  block=doc[start:]
  manifest=json.loads((ROOT/'deploy/release-manifests/production-deployment-helper-2.1.8.json').read_text())
  paths={'entrypoint_sha256':'scripts/wpcaregrid-deploy-production-priv-2_1_8.py',
         'backend_sha256':'scripts/deployment_backend_v2_1_8.py','transaction_module_sha256':'scripts/deployment_transaction_v2.py',
         'environment_contract_sha256':'scripts/deployment_environment_contract_2_1_8.py',
         'release_review_policy_sha256':'scripts/release_review_policy_v3_1.py',
         'controller_sha256':'scripts/production_deploy_controller_2_1_8.py',
         'root_installer_sha256':'scripts/install_production_helper_2_1_8.py'}
  for key,path in paths.items():
   digest=hashlib.sha256((ROOT/path).read_bytes()).hexdigest()
   self.assertEqual(manifest[key],digest,key)
   self.assertIn(f'| `{path}` | `{digest}` |',block)
  self.assertIn(f"| `helper artifact digest` | `{manifest['helper_artifact_sha256']}` |",block)
if __name__=='__main__':unittest.main(verbosity=2)
