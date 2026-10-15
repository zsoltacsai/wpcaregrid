<?php

namespace App\Privacy;

use Illuminate\Contracts\Filesystem\Filesystem;

interface PrivacyTombstoneObjectStore
{
    public function configured(): bool;

    public function driver(): string;

    public function disk(): Filesystem;
}
