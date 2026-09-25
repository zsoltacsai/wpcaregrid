<?php

namespace WPCareGrid\Connector\Auth;

/**
 * The connector credential, encrypted at rest.
 *
 * WordPress has no secret store, so the credential is kept in a
 * non-autoloaded option, encrypted with libsodium (XSalsa20-Poly1305) under
 * a key derived from this site's secret salts in wp-config.php. A database
 * dump alone does not reveal it. If the salts change, the credential can no
 * longer be read and the site must be paired again.
 *
 * The credential is never printed, logged, or exposed through any REST
 * route, admin page or script.
 */
final class CredentialStore
{
    private const OPTION = 'wp_caregrid_credential';

    public function set(string $credential): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($credential, $nonce, $this->key());

        update_option(self::OPTION, base64_encode($nonce.$cipher), false); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
    }

    public function get(): ?string
    {
        $stored = get_option(self::OPTION);
        $raw = is_string($stored) ? base64_decode($stored, true) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key()
        );

        return $plain === false ? null : $plain;
    }

    public function has(): bool
    {
        return $this->get() !== null;
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }

    private function key(): string
    {
        return hash_hmac('sha256', 'wp-caregrid-connector-credential', wp_salt('secure_auth'), true);
    }
}
