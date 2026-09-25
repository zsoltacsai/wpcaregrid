<?php

namespace App\Backups\Storage;

/** The storage rejected data because it did not match the declared checksum. */
class ChecksumMismatch extends \RuntimeException {}
