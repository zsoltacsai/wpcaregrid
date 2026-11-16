#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
workflow = (ROOT / ".github/workflows/marketing-browser-e2e.yml").read_text()
router = (ROOT / "tests/e2e/fixtures/marketing-enforced-test-router.php").read_text()

required = (
    "APP_URL=http://localhost:8001 MARKETING_RELEASE_ENFORCEMENT_MODE=enforced",
    '"$E2E_PHP_BIN" -S 127.0.0.1:8001 -t public tests/e2e/fixtures/marketing-enforced-test-router.php',
    "curl --fail --silent --connect-timeout 2 --max-time 5",
    "--resolve localhost:8001:127.0.0.1 http://localhost:8001/en/",
    "NODE_OPTIONS=--dns-result-order=ipv4first",
    "E2E_BASE_URL=http://localhost:8001",
    "marketing-enforced-test-router.php",
)
for value in required:
    if value not in workflow:
        raise SystemExit(f"Marketing acceptance server policy is missing: {value}")

if "define('WPCG_TEST_RUNTIME'" in router:
    raise SystemExit("Enforced acceptance router must never define WPCG_TEST_RUNTIME.")

acceptance_config = (ROOT / "playwright.marketing-acceptance.config.ts").read_text()
for value in (
    "const configuredUrl = process.env.E2E_BASE_URL;",
    "const target = configuredUrl ? new URL(configuredUrl) : null;",
    "--host-resolver-rules=MAP localhost 127.0.0.1",
):
    if value not in acceptance_config:
        raise SystemExit(f"Marketing acceptance Playwright config is missing: {value}")

if "playwright test --list -c playwright.marketing-acceptance.config.ts" not in workflow:
    raise SystemExit("Marketing acceptance workflow must load its Playwright config before sandbox startup.")

for value in (
    "PHP_SAPI !== 'cli-server'",
    "getenv('APP_ENV') !== 'testing'",
    "getenv('CAREGRID_DEPLOYMENT_ENV') !== 'testing'",
    "getenv('MARKETING_RELEASE_ENFORCEMENT_MODE') !== 'enforced'",
    "defined('WPCG_TEST_RUNTIME')",
    "str_starts_with($staticFile, $publicRoot.DIRECTORY_SEPARATOR)",
):
    if value not in router:
        raise SystemExit(f"Enforced acceptance router is missing guard: {value}")

print("MARKETING_ACCEPTANCE_WORKFLOW_POLICY=PASS")
