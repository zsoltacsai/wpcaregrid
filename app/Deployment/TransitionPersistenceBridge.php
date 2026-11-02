<?php

namespace App\Deployment;

use App\Models\DeploymentTransitionFence;
use App\Models\TransitionBridgeRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    private const OPERATIONS = [
        'read_tuple',
        'read_permit_manifest_hash',
        'verify_transition_preconditions',
        'validate_permit_manifest',
        'compare_and_set',
        'nonce_consumed',
        'validate_release',
        'assert_no_unresolved_drain_effects',
        'read_transition_receipt',
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

    /** @param array<string, mixed> $envelope @return array<string, mixed> */
    public function handleRpc(array $envelope): array
    {
        $request = $this->validateEnvelope($envelope);
        [$status, $result] = match ($request['operation']) {
            'read_tuple' => ['OK', $this->readTuple($request['payload'])],
            'read_permit_manifest_hash' => ['OK', $this->readPermitManifestHash($request['payload'])],
            'verify_transition_preconditions' => ['OK', $this->verifyTransitionPreconditions($request['payload'])],
            'validate_permit_manifest' => ['OK', $this->validatePermitManifest($request['payload'])],
            'compare_and_set' => $this->compareAndSet($request),
            'nonce_consumed' => ['OK', $this->nonceConsumed($request['payload'])],
            'validate_release' => ['OK', $this->validateRelease($request['payload'])],
            'assert_no_unresolved_drain_effects' => ['OK', $this->assertNoUnresolvedDrainEffects($request['payload'])],
            'read_transition_receipt' => ['OK', $this->readTransitionReceipt($request['payload'])],
            default => throw new RuntimeException('The transition RPC operation is unsupported.'),
        };

        return [
            'version' => self::PROTOCOL_VERSION,
            'request_id' => $request['request_id'],
            'request_digest' => $request['request_digest'],
            'status' => $status,
            'result' => $result,
        ];
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function validateEnvelope(array $request): array
    {
        $expected = ['version', 'request_id', 'request_digest', 'operation', 'payload'];
        $keys = array_keys($request);
        sort($keys);
        sort($expected);

        if ($keys !== $expected
            || ($request['version'] ?? null) !== self::PROTOCOL_VERSION
            || ! $this->isUuidV7($request['request_id'] ?? null)
            || ! $this->isSha256($request['request_digest'] ?? null)
            || ! is_string($request['operation'] ?? null)
            || ! in_array($request['operation'], self::OPERATIONS, true)
            || ! is_array($request['payload'] ?? null)) {
            throw new RuntimeException('The transition RPC envelope is invalid.');
        }

        if (! hash_equals($request['request_digest'], self::digestForRequest(
            $request['version'],
            $request['request_id'],
            $request['operation'],
            $request['payload'],
        ))) {
            throw new RuntimeException('The transition RPC digest does not match the canonical envelope projection.');
        }

        return $request;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function digestProjection(int $version, string $requestId, string $operation, array $payload): array
    {
        if ($operation === 'compare_and_set') {
            if (isset($payload['target']) && is_array($payload['target'])) {
                unset($payload['target']['journal_head_hash']);
            }
            if (isset($payload['details']) && is_array($payload['details'])) {
                unset($payload['details']['rpc_request_id'], $payload['details']['rpc_request_digest']);
                if ($payload['details'] === []) {
                    $payload['details'] = (object) [];
                }
            }
        }
        if ($payload === []) {
            $payload = (object) [];
        }

        return [
            'version' => $version,
            'request_id' => $requestId,
            'operation' => $operation,
            'payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function digestForRequest(int $version, string $requestId, string $operation, array $payload): string
    {
        return hash('sha256', TransitionBridgeRequest::canonicalJsonValue(
            self::digestProjection($version, $requestId, $operation, $payload),
        ));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function readTuple(array $payload): array
    {
        $this->requirePayloadKeys($payload, []);

        return DB::transaction(fn (): array => $this->lockedFence()->completeTuple(), 1);
    }

    /** @param array<string, mixed> $payload */
    private function readPermitManifestHash(array $payload): ?string
    {
        $this->requirePayloadKeys($payload, []);
        $manifest = DB::transaction(function (): ?string {
            $fence = $this->lockedFence();

            return $fence->state === 'DRAINING' ? (string) $fence->permit_manifest_hash : null;
        }, 1);

        return $manifest;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function verifyTransitionPreconditions(array $payload): array
    {
        $this->requirePayloadKeys($payload, ['source', 'target', 'details', 'permits']);
        $source = $this->validatedTuple($this->requireMap($payload['source']));
        $target = $this->validatedPreflightTarget($this->requireMap($payload['target']), $payload['details']);
        $details = $this->validatedDetails($this->requireMap($payload['details']));
        $permits = $this->validatedPermitList($payload['permits']);

        DB::transaction(function () use ($source, $target, $details, $permits): void {
            $fence = $this->lockedFence();
            if (! $this->sameCanonicalValue($fence->completeTuple(), $source)) {
                throw new RuntimeException('The current SQL fence does not match the preflight source tuple.');
            }
            $this->validateTransitionSemantics($source, $target, $details, $permits, null);
            $this->requireNoUnresolvedDrainEffects($source);
        }, 1);

        return ['valid' => true];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function validatePermitManifest(array $payload): array
    {
        $this->requirePayloadKeys($payload, ['source', 'permits', 'digest']);
        $source = $this->validatedTuple($this->requireMap($payload['source']));
        $permits = $this->validatedPermitList($payload['permits']);
        $digest = $payload['digest'];

        if (! $this->isSha256($digest) || $permits !== []) {
            throw new RuntimeException('The production effect catalog is empty; permits are refused.');
        }

        DB::transaction(function () use ($source, $digest): void {
            $fence = $this->lockedFence();
            if (! $this->sameCanonicalValue($fence->completeTuple(), $source)) {
                throw new RuntimeException('The current SQL fence does not match the manifest source tuple.');
            }
            $target = [
                'operation_id' => $source['operation_id'],
                'generation' => $source['generation'],
                'cutoff_seq' => $source['journal_seq'],
                'permits' => [],
            ];
            $expected = hash('sha256', TransitionBridgeRequest::canonicalJson($target));
            $this->requireNoExistingPermits($source['operation_id'], $source['generation']);
            if (! hash_equals($expected, $digest)) {
                throw new RuntimeException('The DRAIN manifest digest is invalid.');
            }
        }, 1);

        return ['valid' => true];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function nonceConsumed(array $payload): array
    {
        $this->requirePayloadKeys($payload, ['nonce_digest']);
        if (! $this->isSha256($payload['nonce_digest'])) {
            throw new RuntimeException('The release nonce digest is invalid.');
        }

        return DB::transaction(function () use ($payload): array {
            $this->lockedFence();

            return ['consumed' => TransitionBridgeRequest::query()
                ->where('release_nonce_digest', $payload['nonce_digest'])
                ->exists()];
        }, 1);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function validateRelease(array $payload): array
    {
        $this->requirePayloadKeys($payload, ['authorization', 'source', 'nonce_digest']);
        $source = $this->validatedTuple($this->requireMap($payload['source']));
        $binding = $this->validatedReleaseBinding($this->requireMap($payload['authorization']));
        $nonceDigest = $payload['nonce_digest'];

        if (! is_array($binding) || ! $this->isSha256($nonceDigest)
            || ! hash_equals($binding['nonce_digest'], $nonceDigest)
            || $source['state'] !== 'READY_PENDING_OWNER'
            || $binding['operation_id'] !== $source['operation_id']
            || $binding['generation'] !== $source['generation']
            || $binding['expected_journal_seq'] !== $source['journal_seq'] + 1) {
            throw new RuntimeException('The Owner release binding does not match its source tuple.');
        }

        return DB::transaction(function () use ($source, $nonceDigest): array {
            $fence = $this->lockedFence();
            if (! $this->sameCanonicalValue($fence->completeTuple(), $source)
                || TransitionBridgeRequest::query()->where('release_nonce_digest', $nonceDigest)->exists()) {
                throw new RuntimeException('The Owner release source is stale or its nonce is consumed.');
            }

            return ['valid' => true];
        }, 1);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function assertNoUnresolvedDrainEffects(array $payload): array
    {
        $this->requirePayloadKeys($payload, ['operation_id', 'generation']);
        if (! $this->isUuidV7($payload['operation_id'])
            || ! is_int($payload['generation'])
            || $payload['generation'] < 0) {
            throw new RuntimeException('The unresolved-effect scope is invalid.');
        }

        return DB::transaction(function () use ($payload): array {
            $this->lockedFence();

            return ['clear' => ! $this->hasUnresolvedDrainEffects($payload['operation_id'], $payload['generation'])];
        }, 1);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function readTransitionReceipt(array $payload): array
    {
        $this->requirePayloadKeys($payload, ['request_id', 'request_digest']);
        if (! $this->isUuidV7($payload['request_id']) || ! $this->isSha256($payload['request_digest'])) {
            throw new RuntimeException('The transition receipt lookup identity is invalid.');
        }

        return DB::transaction(function () use ($payload): array {
            $this->lockedFence();
            $prior = TransitionBridgeRequest::query()
                ->where('request_id', $payload['request_id'])
                ->lockForUpdate()
                ->first();

            if (! $prior) {
                return [
                    'committed' => false,
                    'request_id' => $payload['request_id'],
                    'request_digest' => $payload['request_digest'],
                    'target' => null,
                ];
            }

            $receipt = $prior->verifiedReceipt($payload['request_digest']);
            if (! $receipt || ($receipt['request_id'] ?? null) !== $payload['request_id']) {
                throw new RuntimeException('The transition receipt is absent, corrupt, or bound to another digest.');
            }

            return $receipt;
        }, 1);
    }

    /** @param array<string, mixed> $request @return array{0:string,1:array<string,mixed>} */
    private function compareAndSet(array $request): array
    {
        $payload = $request['payload'];
        $this->requirePayloadKeys($payload, ['source', 'target', 'release', 'details']);
        $source = $this->validatedTuple($this->requireMap($payload['source']));
        $target = $this->validatedTuple($this->requireMap($payload['target']));
        $release = $payload['release'] === null ? null : $this->validatedReleaseBinding($this->requireMap($payload['release']));
        $details = $this->validatedDetails($this->requireMap($payload['details']));
        if (($details['rpc_request_id'] ?? null) !== $request['request_id']
            || ($details['rpc_request_digest'] ?? null) !== $request['request_digest']) {
            throw new RuntimeException('The journal RPC identity does not match the request envelope.');
        }

        $result = DB::transaction(function () use ($request, $source, $target, $release, $details): array {
            // Every mutation starts with the single global fence row lock.
            $fence = $this->lockedFence();
            $prior = TransitionBridgeRequest::query()
                ->where('request_id', $request['request_id'])
                ->lockForUpdate()
                ->first();

            if ($prior) {
                return $this->verifiedPriorReceipt($prior, $request, $source, $target, $release);
            }

            $current = $fence->completeTuple();
            if (! $this->sameCanonicalValue($current, $source)) {
                throw new RuntimeException('The current SQL fence does not match the exact request source tuple.');
            }

            $this->validateTransitionSemantics($source, $target, $details, [], $release);
            $this->requireNoUnresolvedDrainEffects($source);
            if ($target['state'] === 'DRAINING') {
                $this->assertEmptyProductionManifest($source, $target);
            }

            if ($source['state'] === 'DRAINING' && $target['state'] !== 'DRAINING') {
                DB::table('transition_admissions')
                    ->where('operation_id', $source['operation_id'])
                    ->where('generation', $source['generation'])
                    ->where('status', 'ISSUED')
                    ->update(['status' => 'CLOSED', 'closed_at' => now(), 'updated_at' => now()]);
            }

            $nonceDigest = $release['nonce_digest'] ?? null;
            if ($nonceDigest !== null
                && TransitionBridgeRequest::query()->where('release_nonce_digest', $nonceDigest)->exists()) {
                throw new RuntimeException('The Owner release nonce has already been consumed.');
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
                    'permit_manifest_hash' => $target['permit_manifest_hash'] ?? null,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new RuntimeException('The deployment fence compare-and-set did not update exactly one row.');
            }

            $receipt = [
                'committed' => true,
                'request_id' => $request['request_id'],
                'request_digest' => $request['request_digest'],
                'source' => $source,
                'target' => $target,
                'release_binding' => $release,
            ];
            $receiptPayload = TransitionBridgeRequest::canonicalJson($receipt);
            TransitionBridgeRequest::query()->create([
                'request_id' => $request['request_id'],
                'request_digest' => $request['request_digest'],
                'action' => 'compare_and_set',
                'receipt_payload' => $receiptPayload,
                'receipt_digest' => hash('sha256', $receiptPayload),
                'release_nonce_digest' => $nonceDigest,
                'committed_at' => now()->utc(),
            ]);

            return $receipt;
        }, 1);

        return ['COMMITTED', $result];
    }

    private function lockedFence(): DeploymentTransitionFence
    {
        $fence = DeploymentTransitionFence::query()
            ->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
            ->lockForUpdate()
            ->first();

        if (! $fence || ! $fence->hasValidTuple()
            || ($fence->state === 'DRAINING' && ! $fence->hasValidDrainManifest())) {
            throw new RuntimeException('The global deployment fence is missing or invalid.');
        }

        return $fence;
    }

    /** @param array<string, mixed> $payload @param list<string> $expected */
    private function requirePayloadKeys(array $payload, array $expected): void
    {
        $keys = array_keys($payload);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('The transition RPC payload shape is invalid.');
        }
    }

    /** @return array<string, mixed> */
    private function requireMap(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('The transition RPC object field is invalid.');
        }

        return $value;
    }

    /** @param list<mixed> $permits @return list<array<string,mixed>> */
    private function validatedPermitList(mixed $permits): array
    {
        if (! is_array($permits) || ! array_is_list($permits)) {
            throw new RuntimeException('The transition permit list is invalid.');
        }
        foreach ($permits as $permit) {
            if (! is_array($permit)) {
                throw new RuntimeException('A transition permit entry is invalid.');
            }
        }

        return $permits;
    }

    /** @param array<string, mixed> $details @return array<string, mixed> */
    private function validatedDetails(array $details): array
    {
        if (strlen(TransitionBridgeRequest::canonicalJson($details)) > 32768
            || $this->containsSecretLikeField($details)) {
            throw new RuntimeException('Transition details exceed their bound or contain a secret-like field.');
        }

        return $details;
    }

    private function containsSecretLikeField(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $nested) {
            if (is_string($key) && preg_match('/secret|token|password|credential|api[_-]?key|\bnonce\b/i', $key) === 1) {
                return true;
            }
            if ($this->containsSecretLikeField($nested)) {
                return true;
            }
        }

        return false;
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

    /** @param array<string, mixed> $target @param mixed $details @return array<string, mixed> */
    private function validatedPreflightTarget(array $target, mixed $details): array
    {
        if (($target['state'] ?? null) === 'DRAINING' && ! array_key_exists('permit_manifest_hash', $target)) {
            $detailsMap = $this->requireMap($details);
            $manifestHash = $detailsMap['permit_manifest_hash'] ?? null;
            if (! $this->isSha256($manifestHash)) {
                throw new RuntimeException('The DRAINING preflight lacks its exact empty manifest hash.');
            }
            $target['permit_manifest_hash'] = $manifestHash;
        }

        return $this->validatedTuple($target);
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

    /** @param array<string, mixed> $source @param array<string, mixed> $target
     * @param  array<string, mixed>  $details  @param list<array<string, mixed>> $permits
     * @param  array<string, mixed>|null  $release
     */
    private function validateTransitionSemantics(array $source, array $target, array $details, array $permits, ?array $release): void
    {
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

        if (count($permits) !== 0) {
            throw new RuntimeException('The production effect catalog is empty; DRAIN permits cannot be issued.');
        }
        if ($target['state'] === 'DRAINING') {
            $this->assertEmptyProductionManifest($source, $target);
        }
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $target */
    private function assertEmptyProductionManifest(array $source, array $target): void
    {
        if (! Schema::hasTable('transition_admissions') || DB::table('transition_admissions')
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

    private function requireNoExistingPermits(string $operationId, int $generation): void
    {
        if (! Schema::hasTable('transition_admissions')
            || DB::table('transition_admissions')
                ->where('operation_id', $operationId)
                ->where('generation', $generation)
                ->exists()) {
            throw new RuntimeException('The production effect catalog is empty; existing permits block DRAIN.');
        }
    }

    /** @param array<string, mixed> $source */
    private function requireNoUnresolvedDrainEffects(array $source): void
    {
        if ($source['state'] !== 'DRAINING') {
            return;
        }

        if (! Schema::hasTable('transition_executions') || ! Schema::hasTable('transition_admissions')) {
            throw new RuntimeException('DRAIN effect evidence tables are unavailable.');
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

    /** @param array<string, mixed> $request @param array<string, mixed> $source
     * @param  array<string, mixed>  $target  @param array<string, mixed>|null $release
     * @return array<string, mixed>
     */
    private function verifiedPriorReceipt(TransitionBridgeRequest $prior, array $request, array $source, array $target, ?array $release): array
    {
        if (! hash_equals((string) $prior->request_digest, $request['request_digest'])) {
            throw new RuntimeException('A request ID cannot be replayed with a different digest.');
        }

        $receipt = $prior->verifiedReceipt($request['request_digest']);
        if (! $receipt
            || ! $this->sameCanonicalValue($receipt['source'] ?? null, $source)
            || ! $this->sameCanonicalValue($receipt['target'] ?? null, $target)
            || ! $this->sameCanonicalValue($receipt['release_binding'] ?? null, $release)) {
            throw new RuntimeException('The stored bridge receipt cannot prove this request committed.');
        }

        return $receipt;
    }

    private function hasUnresolvedDrainEffects(string $operationId, int $generation): bool
    {
        if (! Schema::hasTable('transition_executions') || ! Schema::hasTable('transition_admissions')) {
            throw new RuntimeException('DRAIN effect evidence tables are unavailable.');
        }

        return DB::table('transition_executions as executions')
            ->join('transition_admissions as permits', 'executions.transition_admission_id', '=', 'permits.id')
            ->where('permits.operation_id', $operationId)
            ->where('permits.generation', $generation)
            ->whereIn('executions.status', ['INTENT', 'UNKNOWN'])
            ->exists();
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
