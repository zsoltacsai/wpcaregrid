<?php

namespace App\Platform\Updates;

use RuntimeException;

/** The advisory source could not be read or answered something unexpected. The message is a fixed code. */
final class OsvUnavailable extends RuntimeException {}
