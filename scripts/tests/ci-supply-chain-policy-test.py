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
    sanitized_lines = []
    for line in content.splitlines():
        stripped = line.strip()
        if re.match(r"^python3\s+-m\s+py_compile(?:\s+[^&;|`$]+)+$", stripped):
            continue
        sanitized_lines.append(line)
    sanitized = "\n".join(sanitized_lines)
    assert not re.search(r"(?i)\b(?:scripts/deploy-staging\.sh|deploy-production|deploy-staging)\b", sanitized), "deployment command introduced into CI workflow"


def validate_weekly_deployment_workflow(content):
    """Only the explicitly reviewed weekly workflow may reach existing controllers."""
    assert "pull_request:" not in content and "pull_request_target" not in content
    assert "permissions:\n  contents: read" in content
    assert "cancel-in-progress: false" in content
    assert "runs-on: [self-hosted, linux, wpcg-staging]" in content
    assert "runs-on: [self-hosted, linux, wpcg-production]" in content
    assert "vars.WPCG_WEEKLY_STAGING_ENABLED == 'true'" in content
    assert "vars.WPCG_STAGING_RECOVERY_GATE_READY == 'true'" in content
    for gate in ("WPCG_FIRST_PRODUCTION_LAUNCH_SHA", "WPCG_RECURRING_PRODUCTION_ENABLED",
                 "WPCG_PRODUCTION_RECOVERY_GATE_READY", "WPCG_PRODUCTION_ROLLBACK_READY",
                 "WPCG_PRODUCTION_INCIDENTS_CLEAR", "WPCG_SECURITY_HOTFIX_ENABLED"):
        assert gate in content, f"weekly production gate missing: {gate}"
    assert "production_deploy_controller.py sync" in content
    assert "deploy-staging.sh" in content
    assert "--environment production" in content and "--environment staging" in content
    assert "validate-recovery" in content
    assert "production_deploy_controller.py sync" in content
    assert "deployments: write" in content
    assert "workflow_dispatch" in content and "inputs.phase == 'production'" not in content, \
        "manual production deployment must remain unavailable"


def validate_workflow_controls(workflow):
    assert "pull_request_target" not in workflow, "pull_request_target is prohibited"
    assert re.search(r"(?m)^\s*pull_request:\s*$", workflow), "public PR CI trigger missing"
    # The persistent Contabo runner is allowed for exactly one job (the SQLite regression gate) and only behind a fork fallback;
    # everywhere else untrusted PR code must never reach a persistent self-hosted runner.
    sqlite_start = workflow.find("  sqlite_regressions:" + chr(10))
    assert sqlite_start != -1, "dedicated fast SQLite regression job missing"
    sqlite_end = workflow.find("  wordpress:" + chr(10), sqlite_start)
    assert sqlite_end != -1, "job following the SQLite gate missing"
    assert "self-hosted" not in workflow[:sqlite_start] + workflow[sqlite_end:], "self-hosted is only allowed in the SQLite regression gate"
    assert workflow.count("self-hosted") == 1, "exactly one self-hosted reference (the SQLite gate runs-on) is allowed"
    assert "github.event.pull_request.head.repo.full_name != github.repository" in workflow[sqlite_start:sqlite_end], "fork pull requests must fall back to a GitHub-hosted runner"
    assert '["ubuntu-24.04"]' in workflow[sqlite_start:sqlite_end], "fork fallback runner missing"
    assert "secrets." not in workflow, "CI must not access repository or environment secrets"
    assert not re.search(r"(?i)actions/(?:upload|download)-artifact", workflow), "PR artifacts must not become release inputs"
    assert re.search(r"(?m)^permissions:\s*\n\s+contents:\s*read\s*$", workflow), "workflow token must remain contents: read"
    assert not re.search(r"(?m)^\s+(?:write-all|contents|actions|checks|pull-requests|deployments|id-token):\s*(?:write|write-all)\s*$", workflow), "unexpected token write permission"
    assert "name: Quality (lint, style, dependencies, build, secrets)" in workflow
    assert "name: Server tests (PHP ${{ matrix.php }}, mysql)" in workflow
    assert "php: ['8.5']" in workflow, "server PHP matrix must run only PHP 8.5"
    assert "PHP_VERSION: '8.5'" in workflow, "Quality runtime must use PHP 8.5"
    server = workflow.split("  server:" + chr(10), 1)[1].split("  sqlite_regressions:" + chr(10), 1)[0]
    assert "database:" not in server and "matrix.database" not in server, "obsolete Laravel matrix remains"
    mysql_service = re.search(
        r"(?ms)^      mysql:\n(.*?)(?=^      [A-Za-z0-9_-]+:\n|^    env:)", server
    )
    assert mysql_service and re.search(r"(?m)^        image: mysql:8\.4\s*$", mysql_service.group(1)), \
        "Laravel integration service must remain on the pinned MySQL 8.4 line"
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

    connector = workflow.split("  wordpress:" + chr(10), 1)[1]
    artifact_build = connector.split("      - name: Resolve test tools for the customer PHP branch", 1)[0]
    assert "php-version: '8.5'" in artifact_build, "Connector release artifact must build on PHP 8.5"


def validate_sqlite_regression_gate(workflow):
    lines = workflow.splitlines()
    start = next((index for index, line in enumerate(lines) if line == "  sqlite_regressions:"), None)
    assert start is not None, "dedicated fast SQLite regression job missing"
    end = next((index for index in range(start + 1, len(lines)) if lines[index].startswith("  ") and not lines[index].startswith("    ") and lines[index].endswith(":")), len(lines))
    job = "\n".join(lines[start:end])
    assert "name: Server SQLite regression gate (PHP 8.5)" in job, "SQLite check must identify PHP 8.5"
    dockerfile = (ROOT / ".github/ci/php85-sqlite.Dockerfile").read_text(encoding="utf-8")
    assert re.search(r"(?m)^FROM php:8\.5\.\d+-cli@sha256:[0-9a-f]{64}$", dockerfile), "SQLite CI image must pin PHP 8.5 by digest"
    assert all("@sha256:" in line for line in dockerfile.splitlines() if line.startswith("FROM ")), "every CI image base must be pinned by digest"
    assert ".github/ci/php85-sqlite.Dockerfile" in job and "docker build" in job, "SQLite gate must build the pinned CI image"
    assert "--cap-drop=ALL" in job and "--security-opt=no-new-privileges" in job, "SQLite container must drop privileges"
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
        (workflow.replace("      mysql:\n        image: mysql:8.4", "      mysql:\n        image: mariadb:10.11", 1), "wrong Laravel engine"),
        (workflow.replace("CAREGRID_EXPECTED_DATABASE_VERSION=8.4", "CAREGRID_EXPECTED_DATABASE_VERSION=8.0", 1), "wrong MySQL version"),
        (workflow.replace("php: ['8.5']", "php: ['8.4']", 1), "server matrix must use PHP 8.5 only"),
        (workflow.replace("  sqlite_regressions:", "  quick_sqlite_missing:", 1), "missing dedicated SQLite regression gate"),
        (workflow.replace("github.event.pull_request.head.repo.full_name != github.repository", "true", 1), "missing fork fallback for the self-hosted runner"),
        (workflow.replace("    runs-on: ubuntu-24.04" + chr(10), "    runs-on: [self-hosted, Linux]" + chr(10), 1), "self-hosted runner outside the SQLite gate"),
        (workflow.replace("--cap-drop=ALL", "", 1), "container without dropped capabilities"),
        (workflow.replace("^[0-9a-fA-F]{40}$", "^.+$", 1), "weak manual SHA grammar"),
    )
    for mutated, reason in mutations:
        expect_rejected(lambda content: (validate_workflow_controls(content), validate_sqlite_regression_gate(content)), mutated, reason)
    deployed = workflow + "\n      - name: forbidden\n        run: scripts/deploy-staging.sh\n"
    expect_rejected(lambda content: assert_no_deployment(content), deployed, "deployment command in CI")
    deployed_prod = workflow + "\n      - name: forbidden\n        run: python3 scripts/wpcaregrid-deploy-production-priv-2_1_6.py\n"
    expect_rejected(lambda content: assert_no_deployment(content), deployed_prod, "execution of production deploy helper")
    deployed_chained = workflow + "\n      - name: forbidden\n        run: python3 -m py_compile scripts/foo.py && deploy-production\n"
    expect_rejected(lambda content: assert_no_deployment(content), deployed_chained, "chained deployment command after compile")

    for path in sorted((ROOT / ".github/workflows").glob("*.y*ml")):
        content = path.read_text(encoding="utf-8")
        validate_action_refs(content, str(path))
        validate_run_expressions(content, str(path))
        if path.name == "weekly-dependency-release.yml":
            validate_weekly_deployment_workflow(content)
        else:
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
