<?php

namespace App\Security\Vulnerabilities;

use RuntimeException;

/** Publishes one complete authoritative index using a same-filesystem rename. */
class AtomicIndexPublisher
{
    public function publish(string $stagedIndex, string $activeIndex): void
    {
        if (! @rename($stagedIndex, $activeIndex)) {
            throw new RuntimeException('The validated vulnerability index could not be installed.');
        }
    }
}
