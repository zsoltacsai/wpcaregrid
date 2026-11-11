<?php

namespace App\Platform\Updates;

/**
 * Strict reader of the one supported .github/workflows/actions.lock structure (written by `gh actions-lock`, format
 * version v0.0.3). It is not a YAML parser: every line must match the exact grammar below, otherwise the whole file is
 * rejected (null), so a changed or hostile file can never yield a partial or misleading list.
 *
 *   # comment / blank lines
 *   version: 'v0.0.3'
 *   workflows:
 *       '.github/workflows/<file>.yml':
 *           - '<owner>/<repo>[/<path>]@<ref>'
 *   dependencies:
 *       '<owner>/<repo>[/<path>]@<ref>':
 *           ref: '<ref>'
 *           commit: 'sha1-<40 hex>'
 *           owner_id: <digits>
 *           repo_id: <digits>
 *
 * Only the dependencies section produces entries. Nothing is executed, fetched or written.
 */
final class ActionsLockParser
{
    public const SUPPORTED_VERSIONS = ['v0.0.3'];

    public const MAX_LINES = 2000;

    public const MAX_LINE_LENGTH = 300;

    public const MAX_ENTRIES = 200;

    /** The component key (github-action:<name>@<40 hex>) must fit the 191-character column: too long is a rejection, never a silent drop. */
    public const KEY_PREFIX = 'github-action:';

    public const MAX_KEY_LENGTH = 190;

    private const SPEC = '([A-Za-z0-9][A-Za-z0-9-]{0,38})/([A-Za-z0-9._-]{1,100})((?:/[A-Za-z0-9._-]{1,100}){0,3})@([A-Za-z0-9._+\/-]{1,100})';

    /** No path segment may be a dot or a double dot (the pattern alone would allow them). */
    private static function safeName(string $name): bool
    {
        foreach (explode('/', $name) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{name: string, ref: string, commit: string}>|null */
    public static function parse(string $text): ?array
    {
        if (str_contains($text, "\0") || str_contains($text, "\r") || preg_match('//u', $text) !== 1) {
            return null;
        }
        $lines = explode("\n", $text);
        if (count($lines) > self::MAX_LINES) {
            return null;
        }
        $spec = self::SPEC;
        $section = null;       // null | 'workflows' | 'dependencies'
        $seen = [];            // top-level keys seen
        $version = null;
        $current = null;       // the dependency being read
        $dependencies = [];
        $specs = [];
        $workflowSpecs = [];   // every action a workflow names must be described in dependencies

        $finish = function () use (&$current, &$dependencies, &$specs): bool {
            if ($current === null) {
                return true;
            }
            if ($current['ref'] === null || $current['commit'] === null || $current['owner_id'] === null || $current['repo_id'] === null
                || isset($specs[$current['spec']])) {
                return false;
            }
            $specs[$current['spec']] = true;
            $dependencies[] = ['name' => $current['name'], 'ref' => $current['ref'], 'commit' => $current['commit']];
            $current = null;

            return true;
        };

        $last = count($lines) - 1;
        foreach ($lines as $index => $line) {
            if (strlen($line) > self::MAX_LINE_LENGTH) {
                return null;
            }
            if ($index === $last && $line === '') {
                continue;   // the final newline
            }
            if (preg_match('/^\s*(#.*)?$/', $line) === 1) {
                continue;
            }
            if (preg_match("/^version: '(v[0-9]+\\.[0-9]+\\.[0-9]+)'$/", $line, $m) === 1) {
                if ($seen !== [] || ! $finish() || $version !== null || ! in_array($m[1], self::SUPPORTED_VERSIONS, true)) {
                    return null;
                }
                $version = $m[1];
                $section = null;

                continue;
            }
            if ($line === 'workflows:' || $line === 'dependencies:') {
                $name = rtrim($line, ':');
                if (! $finish() || isset($seen[$name])) {
                    return null;
                }
                $seen[$name] = true;
                $section = $name;

                continue;
            }
            if ($section === 'workflows') {
                if (preg_match("#^    '\\.github/workflows/[A-Za-z0-9._-]{1,100}\\.ya?ml':$#", $line) === 1) {
                    continue;
                }
                if (preg_match("#^        - '".$spec."'$#", $line, $m) === 1 && self::safeName($m[1].'/'.$m[2].$m[3])) {
                    $workflowSpecs[$m[1].'/'.$m[2].$m[3].'@'.$m[4]] = true;

                    continue;
                }

                return null;
            }
            if ($section === 'dependencies') {
                if (preg_match("#^    '".$spec."':$#", $line, $m) === 1) {
                    if (! self::safeName($m[1].'/'.$m[2].$m[3]) || ! $finish() || count($dependencies) >= self::MAX_ENTRIES
                        || strlen(self::KEY_PREFIX.$m[1].'/'.$m[2].$m[3].'@'.str_repeat('0', 40)) > self::MAX_KEY_LENGTH) {
                        return null;
                    }
                    $current = ['spec' => $m[1].'/'.$m[2].$m[3].'@'.$m[4], 'name' => $m[1].'/'.$m[2].$m[3],
                        'ref' => null, 'commit' => null, 'owner_id' => null, 'repo_id' => null];

                    continue;
                }
                if ($current !== null) {
                    if ($current['ref'] === null && preg_match("#^        ref: '([A-Za-z0-9._+/-]{1,100})'$#", $line, $m) === 1) {
                        $current['ref'] = $m[1];

                        continue;
                    }
                    if ($current['commit'] === null && preg_match("/^        commit: 'sha1-([0-9a-f]{40})'$/", $line, $m) === 1) {
                        $current['commit'] = $m[1];

                        continue;
                    }
                    if ($current['owner_id'] === null && preg_match('/^        owner_id: [0-9]{1,12}$/', $line) === 1) {
                        $current['owner_id'] = true;

                        continue;
                    }
                    if ($current['repo_id'] === null && preg_match('/^        repo_id: [0-9]{1,12}$/', $line) === 1) {
                        $current['repo_id'] = true;

                        continue;
                    }
                }
            }

            return null;   // any other line, anywhere
        }
        if (! $finish() || $version === null || ! isset($seen['workflows'], $seen['dependencies']) || $dependencies === []
            || array_diff_key($workflowSpecs, $specs) !== []) {
            return null;   // including a workflow that names an action the dependencies do not describe
        }

        return $dependencies;
    }
}
