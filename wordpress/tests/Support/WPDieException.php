<?php

namespace WPCareGrid\Connector\Tests\Support;

/** Thrown instead of wp_die() output, so tests can assert on it. */
final class WPDieException extends \RuntimeException {}
