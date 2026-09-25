<?php

namespace WPCareGrid\Connector\Backups;

/**
 * wp-config.php for the files backup, without secrets. The database user
 * and password, and the authentication keys and salts, are replaced by a
 * placeholder, so backup storage (and WP CareGrid) never holds them. If any
 * secret value would still appear in the file (unusual syntax), the file is
 * left out of the backup instead.
 */
final class WpConfigRedactor
{
    public const SECRETS = ['DB_USER', 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];

    private const PLACEHOLDER = 'removed-by-wp-caregrid';

    public static function path(): ?string
    {
        if (is_file(ABSPATH.'wp-config.php')) {
            return ABSPATH.'wp-config.php';
        }

        // WordPress also loads it one level up, unless that belongs to another install.
        $parent = dirname(ABSPATH).'/wp-config.php';

        return is_file($parent) && ! is_file(dirname(ABSPATH).'/wp-settings.php') ? $parent : null;
    }

    /** @return array{status: 'redacted'|'excluded'|'missing', content: ?string} */
    public static function read(): array
    {
        $path = self::path();
        $content = $path && ! is_link($path) ? @file_get_contents($path) : false;

        if (! is_string($content)) {
            return ['status' => 'missing', 'content' => null];
        }

        foreach (self::SECRETS as $name) {
            $content = preg_replace(
                '/(define\s*\(\s*([\'"])'.$name.'\2\s*,\s*)([\'"])(?:\\\\.|(?!\3).)*\3/s',
                '${1}\''.self::PLACEHOLDER.'\'',
                $content
            ) ?? '';
        }

        foreach (self::SECRETS as $name) {
            $value = defined($name) ? (string) constant($name) : '';
            if (strlen($value) >= 4 && str_contains($content, $value)) {
                return ['status' => 'excluded', 'content' => null];
            }
        }

        $note = "// WP CareGrid backup copy: database user and password, keys and salts were removed.\n// Set new values before using this file.\n";
        $content = preg_replace('/^<\?php\s*/', "<?php\n".$note, $content, 1) ?? $content;

        return ['status' => 'redacted', 'content' => $content];
    }
}
