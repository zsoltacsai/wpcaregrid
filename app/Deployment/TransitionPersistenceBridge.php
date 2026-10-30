<?php

namespace App\Deployment;

use App\Models\DeploymentTransitionFence;
use App\Models\TransitionBridgeRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * SQL-authoritative persistence endpoint for the inert root transition adapter.
 * The caller holds the root EXCLUSIVE lock before invoking a transition request.
 * This service takes the global DB fence first and never acquires an OS lock.
 */
final class TransitionPersistenceBridge
{
    public const PROTOCOL_VERSION = 1;

    private const TUPLE_FIELDS = [
        'state', 'operation_id', 'generation', 'boot_id', 'journal_seq', 'journal_head_hash', 'evidence_digest',
    ];

    private const STATES = [
        'OPEN', 'HOLD_REQUESTED', 'CLOSING', 'CLOSED', 'DRAINING', 'QUIESCED', 'READY_PENDING_OWNER', 'RECOVERY_REQUIRED',
    ];

    private const EDGES = [
        'OPEN' => ['HOLD_REQUESTED', 'RECOVERY_REQUIRED'],
        'HOLD_REQUESTED' => ['CLOSING', 'RECOVERY_REQUIRED'],
        'CLOSING' => ['CLOSED', 'RECOVERY_REQUIRED'],
        'CLOSED' => ['DRAINING', 'RECOVERY_REQUIRED'],
        'DRAINING' => ['QUIESCED', 'RECOVERY_REQUIRED'],
        'QUIESCED' => ['READY_PENDING_OWNER', 'RECOVERY_REQUIRED'],
        'READY_PENDING_OWNER' => ['OPEN', 'RECOVERY_REQUIRED'],
        'RECOVERY_REQUIRED' => [],
    ];

    /** @var callable(): string */
    private $bootIdResolver;

    public function __construct(?callable $bootIdResolver = null)
    {
        $this->bootIdResolver = $bootIdResolver ?? static function (): string {
            $bootId = @file_get_contents('/proc/sys/kernel/random/boot_id');

            if (! is_string($bootId)) {
                throw new RuntimeException('The current boot ID is unavailable.');
            }

            return trim($bootId);
        };
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function handle(array $request): array
    {
        return match ($request['action'] ?? null) {
            'transition' => $this->applyTransition($request),
            'readback' => $this->readback($request),
            default => throw new RuntimeException('The bridge action is unsupported.'),
        };
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function applyTransition(array $request): array
    {
        $validated = $this->validateTransitionRequest($request);

        return DB::transaction(function () use ($validated): array {
            // Global fence first is the shared lock order for every bridge mutation.
            $fence = DeploymentTransitionFence::query()
                ->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
                ->lockForUpdate()
                ->first();

            if (! $fence || ! $fence->hasValidTuple()) {
                throw new RuntimeException('The global deployment fence is missing or invalid.');
            }

            $prior = TransitionBridgeRequest::query()
                ->where('request_id', $validated['request_id'])
                ->lockForUpdate()
                ->first();

            if ($prior) {
                return $this->verifiedPriorReceipt($prior, $validated);
            }

            $source = $fence->completeTuple();
            if (! $this->sameCanonicalValue($source, $validated['source_tuple'])) {
                throw new RuntimeException('The current SQL fence does not match the exact request source tuple.');
            }

            $this->validateTransitionSemantics($validated);
            $this->requireNoUnresolvedDrainEffects($source);

            $target = $validated['target_tuple'];
            $targetManifest = $target['permit_manifest_hash'] ?? null;

            if ($target['state'] === 'DRAINING') {
                $this->assertEmptyProductionManifest($source, $target, $validated['permits']);
            }

            if ($source['state'] === 'DRAINING' && $target['state'] !== 'DRAINING') {
                DB::table('transition_admissions')
                    ->where('operation_id', $source['operation_id'])
                    ->where('generation', $source['generation'])
                    ->where('status', 'ISSUED')
                    ->update(['status' => 'CLOSED', 'closed_at' => now(), 'updated_at' => now()]);
            }

            $updated = DB::table('deployment_transition_fences')
                ->where('id', $fence->getKey())
                ->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
                ->where('state', $source['state'])
                ->where('operation_id', $source['operation_id'])
                ->where('generation', $source['generation'])
                ->where('boot_id', $source['boot_id'])
                ->where('journal_seq', $source['journal_seq'])
                ->where('journal_head_hash', $source['journal_head_hash'])
                ->where('evidence_digest', $source['evidence_digest'])
                ->when(array_key_exists('permit_manifest_hash', $source),
                    fn ($query) => $query->where('permit_manifest_hash', $source['permit_manifest_hash']),
                    fn ($query) => $query->whereNull('permit_manifest_hash'))
                ->update([
                    'state' => $target['state'],
                    'operation_id' => $target['operation_id'],
                    'generation' => $target['generation'],
                    'boot_id' => $target['boot_id'],
                    'journal_seq' => $target['journal_seq'],
                    'journal_head_hash' => $target['journal_head_hash'],
                    'evidence_digest' => $target['evidence_digest'],
                    'permit_manifest_hash' => $targetManifest,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new RuntimeException('The deployment fence compare-and-set did not update exactly one row.');
            }

            $committedAt = now()->utc();
            $receipt = [
                'protocol_version' => self::PROTOCOL_VERSION,
                'status' => 'COMMITTED',
                'request_id' => $validated['request_id'],
                'request_digest' => $validated['request_digest'],
                'action' => 'transition',
                'source_tuple' => $source,
                'target_tuple' => $target,
                'release_binding' => $validated['release_binding'],
                'committed_at' => $committedAt->format('Y-m-d\TH:i:s.u\Z'),
            ];
            $receiptPayload = TransitionBridgeRequest::canonicalJson($receipt);

            // This receipt and the fence CAS commit atomically. No PENDING receipt is exposed.
            TransitionBridgeRequest::query()->create([
                'request_id' => $validated['request_id'],
                'request_digest' => $validated['request_digest'],
                'action' => 'transition',
                'receipt_payload' => $receiptPayload,
                'receipt_digest' => hash('sha256', $receiptPayload),
                'release_nonce_digest' => $validated['release_binding']['nonce_digest'] ?? null,
                'committed_at' => $committedAt,
            ]);

            return ['protocol_version' => self::PROTOCOL_VERSION, 'status' => 'COMMITTED', 'receipt' => $receipt];
        }, 1);
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function readback(array $request): array
    {
        $validated = $this->validateReadbackRequest($request);

        return DB::transaction(function () use ($validated): array {
            $fence = DeploymentTransitionFence::query()
                ->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
                ->lockForUpdate()
                ->first();

            if (! $fence || ! $fence->hasValidTuple()) {
                throw new RuntimeException('The global deployment fence is missing or invalid.');
            }

            $prior = TransitionBridgeRequest::query()
                ->where('request_id', $validated['request_id'])
                ->lockForUpdate()
                ->first();

            if ($prior) {
                return $this->verifiedPriorReceipt($prior, $validated);
            }

            $current = $fence->completeTuple();
            if ($this->sameCanonicalValue($current, $validated['source_tuple'])) {
                return [
                    'protocol_version' => self::PROTOCOL_VERSION,
                    'status' => 'NOT_COMMITTED',
                    'request_id' => $validated['request_id'],
                    'request_digest' => $validated['request_digest'],
                    'current_tuple' => $current,
                ];
            }

            throw new RuntimeException('Read-back cannot prove either the exact source or a committed receipt.');
        }, 1);
    }

    /** @param array<string, mixed> $request */
    public static function digestForRequest(array $request): string
    {
        unset($request['request_digest']);

        return hash('sha256', TransitionBridgeRequest::canonicalJson($request));
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function validateTransitionRequest(array $request): array
    {
        $expectedKeys = ['action', 'permits', 'protocol_version', 'release_binding', 'request_digest', 'request_id', 'source_tuple', 'target_tuple'];
        $keys = array_keys($request);
        sort($keys);
        sort($expectedKeys);

        if ($keys !== $expectedKeys
            || ($request['protocol_version'] ?? null) !== self::PROTOCOL_VERSION
            || ($request['action'] ?? null) !== 'transition'
            || ! $this->isUuidV7($request['request_id'] ?? null)
            || ! $this->isSha256($request['request_digest'] ?? null)
            || ! is_array($request['permits'] ?? null)
            || ! array_is_list($request['permits'])
            || ! is_array($request['source_tuple'] ?? null)
            || ! is_array($request['target_tuple'] ?? null)
            || (($request['release_binding'] ?? null) !== null && ! is_array($request['release_binding']))) {
            throw new RuntimeException('The transition bridge request envelope is invalid.');
        }

        $source = $this->validatedTuple($request['source_tuple']);
        $target = $this->validatedTuple($request['target_tuple']);
        $normalized = [
            'protocol_version' => self::PROTOCOL_VERSION,
            'action' => 'transition',
            'request_id' => $request['request_id'],
            'source_tuple' => $source,
            'target_tuple' => $target,
            'permits' => $request['permits'],
            'release_binding' => $request['release_binding'],
        ];

        if (! hash_equals($request['request_digest'], self::digestForRequest($normalized))) {
            throw new RuntimeException('The transition bridge request digest does not match its payload.');
        }

        return [
            ...$normalized,
            'request_digest' => $request['request_digest'],
            'release_binding' => $this->validatedReleaseBinding($request['release_binding']),
        ];
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function validateReadbackRequest(array $request): array
    {
        $expectedKeys = ['action', 'protocol_version', 'request_digest', 'request_id', 'source_tuple', 'target_tuple'];
        $keys = array_keys($request);
        sort($keys);
        sort($expectedKeys);

        if ($keys !== $expectedKeys
            || ($request['protocol_version'] ?? null) !== self::PROTOCOL_VERSION
            || ($request['action'] ?? null) !== 'readback'
            || ! $this->isUuidV7($request['request_id'] ?? null)
            || ! $this->isSha256($request['request_digest'] ?? null)
            || ! is_array($request['source_tuple'] ?? null)
            || ! is_array($request['target_tuple'] ?? null)) {
            throw new RuntimeException('The transition bridge read-back request is invalid.');
        }

        return [
            'request_id' => $request['request_id'],
            'request_digest' => $request['request_digest'],
            'source_tuple' => $this->validatedTuple($request['source_tuple']),
            'target_tuple' => $this->validatedTuple($request['target_tuple']),
        ];
    }

    /** @param array<string, mixed> $tuple @return array<string, mixed> */
    private function validatedTuple(array $tuple): array
    {
        $state = $tuple['state'] ?? null;
        $expected = self::TUPLE_FIELDS;
        if ($state === 'DRAINING') {
            $expected[] = 'permit_manifest_hash';
        }
        $keys = array_keys($tuple);
        sort($keys);
        sort($expected);

        if ($keys !== $expected
            || ! is_string($state)
            || ! in_array($state, self::STATES, true)
            || ! $this->isUuidV7($tuple['operation_id'] ?? null)
            || ! is_int($tuple['generation'] ?? null)
            || $tuple['generation'] < 0
            || ! $this->isUuid($tuple['boot_id'] ?? null)
            || ! is_int($tuple['journal_seq'] ?? null)
            || $tuple['journal_seq'] < 0
            || ! $this->isSha256($tuple['journal_head_hash'] ?? null)
            || ! $this->isSha256($tuple['evidence_digest'] ?? null)
            || ($state === 'DRAINING' && ! $this->isSha256($tuple['permit_manifest_hash'] ?? null))) {
            throw new RuntimeException('The transition tuple is invalid.');
        }

        $validated = [
            'state' => $state,
            'operation_id' => $tuple['operation_id'],
            'generation' => $tuple['generation'],
            'boot_id' => strtolower($tuple['boot_id']),
            'journal_seq' => $tuple['journal_seq'],
            'journal_head_hash' => $tuple['journal_head_hash'],
            'evidence_digest' => $tuple['evidence_digest'],
        ];
        if ($state === 'DRAINING') {
            $validated['permit_manifest_hash'] = $tuple['permit_manifest_hash'];
        }

        return $validated;
    }

    /** @param array<string, mixed>|null $binding @return array<string, mixed>|null */
    private function validatedReleaseBinding(?array $binding): ?array
    {
        if ($binding === null) {
            return null;
        }

        $expected = [
            'approval_ref', 'expected_journal_seq', 'generation', 'host_id', 'nonce_digest',
            'operation_id', 'php_profile', 'rollback_evidence', 'source_sha', 'target_sha',
        ];
        $keys = array_keys($binding);
        sort($keys);
        sort($expected);

        if ($keys !== $expected
            || ! $this->isUuidV7($binding['operation_id'] ?? null)
            || ! is_int($binding['generation'] ?? null)
            || $binding['generation'] < 0
            || ! is_int($binding['expected_journal_seq'] ?? null)
            || $binding['expected_journal_seq'] < 0
            || ! $this->isSha256($binding['nonce_digest'] ?? null)
            || ! $this->isSha256($binding['rollback_evidence'] ?? null)
            || ! is_string($binding['source_sha'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/D', $binding['source_sha']) !== 1
            || ! is_string($binding['target_sha'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/D', $binding['target_sha']) !== 1
            || ($binding['php_profile'] ?? null) !== '8.4'
            || ! $this->isOpaque($binding['host_id'] ?? null, 191)
            || ! $this->isOpaque($binding['approval_ref'] ?? null, 255)) {
            throw new RuntimeException('The Owner release binding is invalid.');
        }

        return [
            'host_id' => $binding['host_id'],
            'operation_id' => $binding['operation_id'],
            'generation' => $binding['generation'],
            'source_sha' => $binding['source_sha'],
            'target_sha' => $binding['target_sha'],
            'approval_ref' => $binding['approval_ref'],
            'php_profile' => '8.4',
            'rollback_evidence' => $binding['rollback_evidence'],
            'expected_journal_seq' => $binding['expected_journal_seq'],
            'nonce_digest' => $binding['nonce_digest'],
        ];
    }

    /** @param array<string, mixed> $request */
    private function validateTransitionSemantics(array $request): void
    {
        $source = $request['source_tuple'];
        $target = $request['target_tuple'];
        $currentBoot = ($this->bootIdResolver)();

        if (! $this->isUuid($currentBoot)
            || ! hash_equals(strtolower($currentBoot), $target['boot_id'])) {
            throw new RuntimeException('The target tuple does not belong to the current boot.');
        }

        if ($source['state'] === 'RECOVERY_REQUIRED'
            || ! in_array($target['state'], self::EDGES[$source['state']] ?? [], true)
            || $target['journal_seq'] !== $source['journal_seq'] + 1) {
            throw new RuntimeException('The requested state edge or journal sequence is invalid.');
        }

        $startsOperation = in_array($target['state'], ['HOLD_REQUESTED', 'RECOVERY_REQUIRED'], true);
        if ($startsOperation) {
            if ($target['generation'] !== $source['generation'] + 1
                || $target['operation_id'] === $source['operation_id']) {
                throw new RuntimeException('A new transition operation must use a new UUIDv7 and generation.');
            }
        } elseif ($target['operation_id'] !== $source['operation_id']
            || $target['generation'] !== $source['generation']) {
            throw new RuntimeException('The transition operation and generation are immutable across this edge.');
        }

        $release = $request['release_binding'];
        if ($target['state'] === 'OPEN') {
            if (! is_array($release)
                || $source['state'] !== 'READY_PENDING_OWNER'
                || $release['operation_id'] !== $source['operation_id']
                || $release['generation'] !== $source['generation']
                || $release['expected_journal_seq'] !== $target['journal_seq']) {
                throw new RuntimeException('OPEN requires an exact one-use Owner release binding.');
            }
        } elseif ($release !== null) {
            throw new RuntimeException('A release binding is valid only for READY_PENDING_OWNER to OPEN.');
        }

        if (count($request['permits']) !== 0) {
            throw new RuntimeException('The production effect catalog is empty; DRAIN permits cannot be issued.');
        }
        if ($target['state'] === 'DRAINING') {
            $this->assertEmptyProductionManifest($source, $target, $request['permits']);
        }
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $target @param list<mixed> $permits */
    private function assertEmptyProductionManifest(array $source, array $target, array $permits): void
    {
        if ($permits !== [] || DB::table('transition_admissions')
            ->where('operation_id', $target['operation_id'])
            ->where('generation', $target['generation'])
            ->exists()) {
            throw new RuntimeException('The production effect catalog is empty; DRAIN permits cannot be issued.');
        }

        $manifestPayload = [
            'operation_id' => $target['operation_id'],
            'generation' => $target['generation'],
            'cutoff_seq' => $source['journal_seq'],
            'permits' => [],
        ];
        $expectedHash = hash('sha256', TransitionBridgeRequest::canonicalJson($manifestPayload));
        if (! hash_equals($expectedHash, $target['permit_manifest_hash'] ?? '')) {
            throw new RuntimeException('The DRAINING empty-manifest hash is invalid.');
        }
    }

    /** @param array<string, mixed> $source */
    private function requireNoUnresolvedDrainEffects(array $source): void
    {
        if ($source['state'] !== 'DRAINING') {
            return;
        }

        $unresolved = DB::table('transition_executions as executions')
            ->join('transition_admissions as permits', 'executions.transition_admission_id', '=', 'permits.id')
            ->where('permits.operation_id', $source['operation_id'])
            ->where('permits.generation', $source['generation'])
            ->whereIn('executions.status', ['INTENT', 'UNKNOWN'])
            ->exists();

        if ($unresolved) {
            throw new RuntimeException('Unresolved DRAIN effects block transition progression.');
        }
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function verifiedPriorReceipt(TransitionBridgeRequest $prior, array $validated): array
    {
        if (! hash_equals((string) $prior->request_digest, $validated['request_digest'])) {
            throw new RuntimeException('A request ID cannot be replayed with a different digest.');
        }

        $receipt = $prior->verifiedReceipt($validated['request_digest']);
        if (! $receipt
            || ! $this->sameCanonicalValue($receipt['source_tuple'] ?? null, $validated['source_tuple'])
            || ! $this->sameCanonicalValue($receipt['target_tuple'] ?? null, $validated['target_tuple'])
            || (array_key_exists('release_binding', $validated)
                && ! $this->sameCanonicalValue($receipt['release_binding'] ?? null, $validated['release_binding']))) {
            throw new RuntimeException('The stored bridge receipt cannot prove this request committed.');
        }

        return ['protocol_version' => self::PROTOCOL_VERSION, 'status' => 'COMMITTED', 'receipt' => $receipt];
    }

    private function sameCanonicalValue(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }

        try {
            return hash_equals(
                TransitionBridgeRequest::canonicalJson($left),
                TransitionBridgeRequest::canonicalJson($right),
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function isUuidV7(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }

    private function isOpaque(mixed $value, int $limit): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= $limit
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }
}
