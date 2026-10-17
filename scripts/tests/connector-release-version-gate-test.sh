#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
temporary="$(mktemp -d)"
trap 'rm -rf -- "$temporary"' EXIT
mkdir -p "$temporary/scripts" "$temporary/wordpress/wp-caregrid-connector/includes"
cp "$root/scripts/check-connector-release.php" "$temporary/scripts/check-connector-release.php"
cat > "$temporary/wordpress/wp-caregrid-connector/wp-caregrid-connector.php" <<'PHP'
<?php
/**
 * Version: 0.4.1
 */
define('WP_CAREGRID_VERSION', '0.0.0'); // release.json is the runtime source.
PHP
printf '%s\n' '<?php return "before";' > "$temporary/wordpress/wp-caregrid-connector/includes/example.php"
git -C "$temporary" init -q
git -C "$temporary" config user.name 'Connector release gate test'
git -C "$temporary" config user.email 'release-gate@example.test'
git -C "$temporary" add .
git -C "$temporary" commit -qm 'Base Connector release'
base="$(git -C "$temporary" rev-parse HEAD)"
tree="$(git -C "$temporary" rev-parse HEAD^{tree})"
multiline="$(printf '%s\n--output=/tmp/injected' "$base")"
for invalid in '-' 'main' '0123' "$multiline" "$tree"; do
    if php "$temporary/scripts/check-connector-release.php" "$invalid" >/dev/null 2>&1; then
        echo 'Connector release gate accepted a non-SHA base argument.' >&2
        exit 1
    fi
done

printf '%s\n' '<?php return "changed without version bump";' > "$temporary/wordpress/wp-caregrid-connector/includes/example.php"
cat > "$temporary/wordpress/wp-caregrid-connector/release.json" <<'JSON'
{"version":"0.4.1","package_schema":1}
JSON
git -C "$temporary" add .
git -C "$temporary" commit -qm 'Runtime change without release bump'
if php "$temporary/scripts/check-connector-release.php" "$base" >/dev/null 2>&1; then
    echo 'Connector release gate accepted runtime changes without a version bump.' >&2
    exit 1
fi

cat > "$temporary/wordpress/wp-caregrid-connector/release.json" <<'JSON'
{"version":"0.4.2","package_schema":1}
JSON
sed -i 's/Version: 0.4.1/Version: 0.4.2/' "$temporary/wordpress/wp-caregrid-connector/wp-caregrid-connector.php"
git -C "$temporary" add .
git -C "$temporary" commit -qm 'Release bump'
php "$temporary/scripts/check-connector-release.php" "$base"
