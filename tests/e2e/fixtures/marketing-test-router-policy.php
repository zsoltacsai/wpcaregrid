<?php

declare(strict_types=1);

/**
 * The test router may grant its in-process marker only to local clients.
 */
function wpcg_test_router_client_is_loopback(?string $remoteAddress): bool
{
    if ($remoteAddress === null || $remoteAddress === '') {
        return false;
    }

    $packed = @inet_pton($remoteAddress);
    if ($packed === false) {
        return false;
    }

    if (strlen($packed) === 4) {
        return ord($packed[0]) === 127;
    }

    return strlen($packed) === 16 && $packed === inet_pton('::1');
}
