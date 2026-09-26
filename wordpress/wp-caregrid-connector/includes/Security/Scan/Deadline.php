<?php

namespace WPCareGrid\Connector\Security\Scan;

/**
 * The time and memory budget of one scan part. When it is reached the scan
 * stops cleanly, reports what it has and continues in its next part.
 *
 * Sites on slow hosting may also cap the work units (files, checksums) per
 * part with the `wp_caregrid_security_check_limit` filter; it can only
 * shorten a part, never lengthen it.
 */
final class Deadline
{
    private float $until;

    private ?int $checks_left;

    public function __construct(int $seconds)
    {
        $this->until = microtime(true) + max(5, $seconds);
        $limit = apply_filters('wp_caregrid_security_check_limit', null);
        $this->checks_left = is_int($limit) && $limit > 0 ? $limit : null;
    }

    public function reached(): bool
    {
        if ($this->checks_left !== null && --$this->checks_left < 0) {
            return true;
        }

        return microtime(true) >= $this->until || $this->memory_tight();
    }

    /** Stop well before PHP's memory_limit instead of dying. */
    private function memory_tight(): bool
    {
        $limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));

        return $limit > 0 && memory_get_usage(true) > $limit * 0.8;
    }
}
