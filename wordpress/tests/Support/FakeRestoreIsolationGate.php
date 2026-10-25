<?php

namespace WPCareGrid\Connector\Tests\Support;

use WPCareGrid\Connector\Backups\RestoreIsolationGateProvider;
use WPCareGrid\Connector\Backups\RestoreIsolationLease;

/** Synthetic provider for tests; it models atomic host gating, not production hosting. */
final class FakeRestoreIsolationGate implements RestoreIsolationGateProvider
{
    private readonly string $statePath;

    public bool $allowAcquire = true;

    public bool $writersQuiescent = true;

    public ?int $loseQuiescenceOnAssertion = null;

    private int $assertionCount = 0;

    public function __construct()
    {
        $root = realpath((string) getenv('WP_CAREGRID_TEST_ROOT'));
        if (! is_string($root) || ! is_dir($root)) {
            throw new \RuntimeException('The synthetic restore gate requires an owned test root.');
        }
        $this->statePath = $root.'/.restore-isolation-gate.json';
    }

    public function provider_id(): string
    {
        return 'test-host-gate';
    }

    public function acquire(RestoreIsolationLease $lease, ?RestoreIsolationLease $previous): ?RestoreIsolationLease
    {
        if (! $this->allowAcquire || ! $this->writersQuiescent) {
            return null;
        }

        return $this->mutate(function (array &$state) use ($lease, $previous): ?RestoreIsolationLease {
            if ($previous !== null && ! isset($state['active'][$previous->generation])) {
                return null;
            }
            foreach ($state['active'] as $value) {
                $active = RestoreIsolationLease::from_array($value);
                if ($active !== null
                    && $active->restore_uuid === $lease->restore_uuid
                    && $active->scope === $lease->scope
                    && $active->provider === $lease->provider) {
                    return $active;
                }
            }
            // Keep the previous generation active until the caller has
            // durably recorded the new lease and calls commit().
            $state['active'][$lease->generation] = $lease->to_array();

            return $lease;
        });
    }

    public function assert_active(RestoreIsolationLease $lease): bool
    {
        $this->assertionCount++;
        if ($this->loseQuiescenceOnAssertion === $this->assertionCount) {
            $this->writersQuiescent = false;
        }
        if (! $this->writersQuiescent) {
            return false;
        }
        $state = $this->read();
        $active = RestoreIsolationLease::from_array($state['active'][$lease->generation] ?? null);

        return $active !== null && $active->to_array() === $lease->to_array();
    }

    public function release(RestoreIsolationLease $lease): bool
    {
        if (! $this->assert_active($lease)) {
            $state = $this->read();

            return ! isset($state['active'][$lease->generation]);
        }

        return $this->mutate(function (array &$state) use ($lease): bool {
            unset($state['active'][$lease->generation]);

            return true;
        });
    }

    public function commit(RestoreIsolationLease $current, RestoreIsolationLease $previous): bool
    {
        if (! $this->assert_active($current)) {
            return false;
        }

        return $this->mutate(function (array &$state) use ($current, $previous): bool {
            $activeCurrent = RestoreIsolationLease::from_array($state['active'][$current->generation] ?? null);
            if ($activeCurrent === null || $activeCurrent->restore_uuid !== $current->restore_uuid) {
                return false;
            }
            $activePrevious = RestoreIsolationLease::from_array($state['active'][$previous->generation] ?? null);
            if ($activePrevious !== null && $activePrevious->to_array() !== $previous->to_array()) {
                return false;
            }
            unset($state['active'][$previous->generation]);

            return true;
        });
    }

    public function lose_generation(RestoreIsolationLease $lease): void
    {
        $this->mutate(function (array &$state) use ($lease): bool {
            unset($state['active'][$lease->generation]);

            return true;
        });
    }

    public function is_gated(): bool
    {
        return $this->read()['active'] !== [];
    }

    public function reset(): void
    {
        $this->mutate(static function (array &$state): bool {
            $state['active'] = [];

            return true;
        });
        $this->writersQuiescent = true;
        $this->allowAcquire = true;
        $this->loseQuiescenceOnAssertion = null;
        $this->assertionCount = 0;
    }

    private function read(): array
    {
        if (! is_file($this->statePath) || is_link($this->statePath)) {
            return ['active' => []];
        }
        $handle = @fopen($this->statePath, 'rb');
        if (! is_resource($handle) || ! flock($handle, LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return ['active' => []];
        }
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        $state = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($state) && is_array($state['active'] ?? null) ? $state : ['active' => []];
    }

    private function mutate(callable $callback): mixed
    {
        $handle = @fopen($this->statePath, 'c+');
        if (! is_resource($handle) || ! flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return false;
        }
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (! is_array($state) || ! is_array($state['active'] ?? null)) {
            $state = ['active' => []];
        }
        $result = $callback($state);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, wp_json_encode($state));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $result;
    }
}
