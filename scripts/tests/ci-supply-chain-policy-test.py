#!/usr/bin/env python3
"""Small repository-owned guard for the public CI trust boundary."""
import json
import re
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/ci.yml"


def validate_action_refs(content, label="workflow"):
    for line_number, line in enumerate(content.splitlines(), 1):
        match = re.match(r"\s*uses:\s*([^\s#]+)", line)
        if not match:
            continue
        reference = match.group(1)
        if reference.startswith("./"):
            continue
        assert re.fullmatch(r"[^@]+@[0-9a-fA-F]{40}", reference), f"mutable external action at {label}:{line_number}: {reference}"


def validate_run_expressions(content, label="workflow"):
    lines = content.splitlines()
    index = 0
    while index < len(lines):
        line = lines[index]
        match = re.match(r"^(\s*)run:\s*(.*)$", line)
        if not match:
            index += 1
            continue
        indent = len(match.group(1))
        block = [match.group(2)]
        index += 1
        while index < len(lines):
            candidate = lines[index]
            if candidate.strip() and len(candidate) - len(candidate.lstrip()) <= indent:
                break
            block.append(candidate)
            index += 1
        joined = "\n".join(block)
        assert not re.search(r"\$\{\{\s*github\.event\.", joined), f"untrusted event context interpolated into shell in {label}"


def assert_no_deployment(content):
    assert not re.search(r"(?i)\b(?:scripts/deploy-staging\.sh|deploy-production|deploy-staging)\b", content), "deployment command introduced into CI workflow"


def validate_workflow_controls(workflow):
    assert "pull_request_target" not in workflow, "pull_request_target is prohibited"
    assert re.search(r"(?m)^\s*pull_request:\s*$", workflow), "public PR CI trigger missing"
    assert "self-hosted" not in workflow, "untrusted PRs must not execute on persistent self-hosted runners"
    assert "secrets." not in workflow, "CI must not access repository or environment secrets"
    assert not re.search(r"(?i)actions/(?:upload|download)-artifact", workflow), "PR artifacts must not become release inputs"
    assert re.search(r"(?m)^permissions:\s*\n\s+contents:\s*read\s*$", workflow), "workflow token must remain contents: read"
    assert not re.search(r"(?m)^\s+(?:write-all|contents|actions|checks|pull-requests|deployments|id-token):\s*(?:write|write-all)\s*$", workflow), "unexpected token write permission"
    assert "name: Quality (lint, style, dependencies, build, secrets)" in workflow
    assert "name: Server tests (PHP ${{ matrix.php }}, mysql)" in workflow
    assert "php: ['8.4', '8.5']" in workflow, "server PHP matrix must retain 8.4 and 8.5"
    server = workflow.split("  server:" + chr(10), 1)[1].split("  sqlite_regressions:" + chr(10), 1)[0]
    assert "database:" not in server and "matrix.database" not in server, "obsolete Laravel matrix remains"
    assert "image: mysql:8.4" in server
    assert "CAREGRID_EXPECTED_DATABASE_DRIVER=mysql" in server and "CAREGRID_EXPECTED_DATABASE_VERSION=8.4" in server
    assert "scripts/tests/assert-ci-mysql-driver.php" in server
    assert "vendor/bin/phpunit -c phpunit.xml --fail-on-skipped" in server
    assert "Required MySQL process-concurrency gate" in server and "if: matrix.database" not in server
    for retained in ("S3-compatible test service (moto)", "SFTP test service", "migrate:rollback", "Production caches build", "HTTP smoke test"):
        assert retained in server, f"required integration coverage missing: {retained}"
    assert "php: ['8.2', '8.3', '8.4', '8.5']" in workflow, "customer PHP compatibility matrix incomplete"
    assert "wordpress: ['6.8', '7.1']" in workflow, "Connector WordPress compatibility fixtures incomplete"
    assert "database: [mysql57, mysql80, mysql, mariadb]" in workflow, "Connector SQL engine matrix incomplete"
    for fixture in ("'mysql:5.7'", "'mysql:8.0'", "'mysql:8.4'", "'mariadb:10.11'"):
        assert fixture in workflow, f"Connector SQL fixture image missing: {fixture}"
    for legacy_context in (
        "Connector tests in WordPress (PHP 8.4, sqlite)",
        "Connector tests in WordPress (PHP 8.4, mysql)",
        "Connector tests in WordPress (PHP 8.4, mariadb)",
    ):
        assert legacy_context in workflow, f"required protected-branch check context missing: {legacy_context}"
    for expected_fact in (
        'WP_CAREGRID_EXPECTED_PHP_BRANCH: ${{ matrix.php }}',
        'WP_CAREGRID_EXPECTED_WORDPRESS_BRANCH: ${{ matrix.wordpress }}',
        "WP_CAREGRID_EXPECTED_DATABASE: ${{ (matrix.database == 'mysql57' || matrix.database == 'mysql80') && 'mysql' || matrix.database }}",
        "WP_CAREGRID_EXPECTED_DATABASE_BRANCH: ${{ matrix.database == 'mysql57' && '5.7' || matrix.database == 'mysql80' && '8.0' || matrix.database == 'mysql' && '8.4' || matrix.database == 'mariadb' && '10.11' || 'sqlite' }}",
        'composer update phpunit/phpunit --with',
        'composer update johnpbloch/wordpress-core --with',
    ):
        assert expected_fact in workflow, f"Connector matrix assertion/setup missing: {expected_fact}"
    assert "Required MySQL process-concurrency gate" in workflow, "MySQL process gate missing"
    for variable in ("DB_CONNECTION", "DB_DATABASE"):
        declarations = [node for node in ET.parse(ROOT / "phpunit.xml").iter("env") if node.attrib.get("name") == variable]
        assert declarations, f"PHPUnit database default missing: {variable}"
        assert all(node.attrib.get("force", "false").lower() != "true" for node in declarations), f"PHPUnit must not override CI database: {variable}"

    assert re.search(r"DISPATCH_BASE.*inputs\.connector_release_base", workflow)
    assert "^[0-9a-fA-F]{40}$" in workflow


def validate_sqlite_regression_gate(workflow):
    lines = workflow.splitlines()
    start = next((index for index, line in enumerate(lines) if line == "  sqlite_regressions:"), None)
    assert start is not None, "dedicated fast SQLite regression job missing"
    end = next((index for index in range(start + 1, len(lines)) if lines[index].startswith("  ") and not lines[index].startswith("    ") and lines[index].endswith(":")), len(lines))
    job = "\n".join(lines[start:end])
    assert "name: Server SQLite regression gate (PHP 8.4)" in job, "stable SQLite check name changed"
    assert "php-version: '8.4'" in job, "SQLite regression gate PHP version changed"
    assert re.search(r"(?m)^      DB_CONNECTION: sqlite$", job), "SQLite job must select the SQLite connection"
    assert re.search(r"(?m)^      DB_DATABASE: database/ci-sqlite-regressions\.sqlite$", job), "SQLite job must use its isolated disposable database file"
    assert re.search(r"(?m)^      CAREGRID_EXPECTED_DATABASE_DRIVER: sqlite$", job), "SQLite driver expectation missing"
    assert "scripts/tests/assert-ci-sqlite-driver.php" in job, "SQLite runtime engine assertion missing"
    assert "vendor/bin/phpunit" in job and "--fail-on-skipped" in job, "strict targeted PHPUnit regressions missing"
    for test_path in (
        "tests/Unit",
        "tests/Feature/AuthorizationMatrixTest.php",
        "tests/Feature/Account/AuthenticationTest.php",
        "tests/Feature/Account/AuthorizationTest.php",
        "tests/Feature/Account/CustomerAreaTest.php",
        "tests/Feature/Account/OnboardingTest.php",
        "tests/Feature/CustomerLocalizationTest.php",
        "tests/Feature/LocalizedMarketingRoutesTest.php",
    ):
        assert test_path in job, f"SQLite regression scope missing: {test_path}"
    assert "services:" not in job, "quick SQLite regressions must not start integration services"
    for forbidden in ("moto_server", "SFTP test service", "CAREGRID_TEST_REQUIRE_SERVICES"):
        assert forbidden not in job, f"quick SQLite job includes full integration service setup: {forbidden}"


def expect_rejected(check, content, reason):
    try:
        check(content)
    except AssertionError:
        return
    raise AssertionError(f"policy accepted {reason}")


def main():
    for bad in ("uses: actions/checkout@v7", "uses: acme/action@main", "uses: acme/action@release"):
        expect_rejected(validate_action_refs, bad, "a mutable Action reference")
    validate_action_refs("uses: actions/checkout@" + "a" * 40 + " # v1\nuses: ./local-action")
    for bad in ("run: echo ${{ github.event.pull_request.title }}", "run: |\n  echo safe\n  echo ${{ github.event.inputs.value }}"):
        expect_rejected(validate_run_expressions, bad, "untrusted event context in shell")

    workflow = WORKFLOW.read_text(encoding="utf-8")
    validate_workflow_controls(workflow)
    validate_sqlite_regression_gate(workflow)
    mutations = (
        (workflow + "\npull_request_target:\n", "pull_request_target"),
        (workflow.replace("contents: read", "contents: write", 1), "write token permission"),
        (workflow.replace("name: Quality (lint, style, dependencies, build, secrets)", "name: quality removed", 1), "missing mandatory job"),
        (workflow.replace("Required MySQL process-concurrency gate", "optional MySQL process gate", 1), "missing MySQL process gate"),
        (workflow.replace("image: mysql:8.4", "image: mariadb:10.11", 1), "wrong Laravel engine"),
        (workflow.replace("CAREGRID_EXPECTED_DATABASE_VERSION=8.4", "CAREGRID_EXPECTED_DATABASE_VERSION=8.0", 1), "wrong MySQL version"),
        (workflow.replace("php: ['8.4', '8.5']", "php: ['8.5']", 1), "missing PHP 8.4 server lane"),
        (workflow.replace("  sqlite_regressions:", "  quick_sqlite_missing:", 1), "missing dedicated SQLite regression gate"),
        (workflow.replace("^[0-9a-fA-F]{40}$", "^.+$", 1), "weak manual SHA grammar"),
    )
    for mutated, reason in mutations:
        expect_rejected(lambda content: (validate_workflow_controls(content), validate_sqlite_regression_gate(content)), mutated, reason)
    deployed = workflow + "\n      - name: forbidden\n        run: scripts/deploy-staging.sh\n"
    expect_rejected(lambda content: assert_no_deployment(content), deployed, "deployment command in CI")

    for path in sorted((ROOT / ".github/workflows").glob("*.y*ml")):
        content = path.read_text(encoding="utf-8")
        validate_action_refs(content, str(path))
        validate_run_expressions(content, str(path))
        assert_no_deployment(content)

    composer_files = (ROOT / "composer.json", ROOT / "wordpress/composer.json")
    lock_files = (ROOT / "composer.lock", ROOT / "wordpress/composer.lock")
    for path in composer_files:
        manifest = json.loads(path.read_text(encoding="utf-8"))
        assert not manifest.get("repositories"), f"custom Composer repositories require review: {path}"
    for path in lock_files:
        lock = json.loads(path.read_text(encoding="utf-8"))
        for package in lock.get("packages", []) + lock.get("packages-dev", []):
            for field in ("dist", "source"):
                url = (package.get(field) or {}).get("url")
                if url:
                    assert url.startswith("https://"), f"non-HTTPS Composer source requires review: {package.get('name')}"
    lock = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
    for name, package in lock.get("packages", {}).items():
        resolved = package.get("resolved")
        if resolved:
            assert resolved.startswith("https://registry.npmjs.org/"), f"non-registry npm source requires review: {name}"


if __name__ == "__main__":
    main()
