#!/usr/bin/env python3
"""Small repository-owned guard for the public CI trust boundary."""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/ci.yml"
DATABASES = ("sqlite", "mysql", "mariadb")


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
    assert "name: Server tests (PHP ${{ matrix.php }}, ${{ matrix.database }})" in workflow
    assert "php: ['8.4', '8.5']" in workflow, "server PHP matrix must retain 8.4 and validate 8.5"
    assert "name: ${{ matrix.legacy_check_name || format('Connector tests (PHP {0}, WordPress {1}, {2})', matrix.php, matrix.wordpress, matrix.database) }}" in workflow
    assert "database: [sqlite, mysql, mariadb]" in workflow, "server matrix shrank"
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
    assert re.search(r"DISPATCH_BASE.*inputs\.connector_release_base", workflow)
    assert "^[0-9a-fA-F]{40}$" in workflow


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
    mutations = (
        (workflow + "\npull_request_target:\n", "pull_request_target"),
        (workflow.replace("contents: read", "contents: write", 1), "write token permission"),
        (workflow.replace("name: Quality (lint, style, dependencies, build, secrets)", "name: quality removed", 1), "missing mandatory job"),
        (workflow.replace("Required MySQL process-concurrency gate", "optional MySQL process gate", 1), "missing MySQL process gate"),
        (workflow.replace("^[0-9a-fA-F]{40}$", "^.+$", 1), "weak manual SHA grammar"),
    )
    for mutated, reason in mutations:
        expect_rejected(validate_workflow_controls, mutated, reason)
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
