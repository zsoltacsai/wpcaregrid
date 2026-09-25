<?php

namespace App\Backups\Storage;

/** Storage could not be reached or refused the request. Messages never include credentials. */
class StorageUnavailable extends \RuntimeException {}
