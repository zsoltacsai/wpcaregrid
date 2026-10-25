<?php

namespace WPCareGrid\Connector\Core;

use RuntimeException;

/** Raised when a poll worker no longer owns its site-wide lease. */
final class LeaseLost extends RuntimeException {}
