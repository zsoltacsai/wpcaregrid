<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupManifest;
use App\Backups\BackupObjectName;
use App\Backups\BackupType;
use App\Backups\CustomerDownloads\BackupDownloadGrants;
use App\Backups\CustomerDownloads\PrepareBackupDownloadGrant;
use App\Backups\Storage\BackupStorage;
use App\Backups\Storage\StorageManager;
use App\Backups\Storage\StreamableBackupStorage;
use App\Backups\Storage\Verification;
use App\Models\Backup;
use App\Models\BackupDownloadGrant;
use App\Models\BackupObject;
use App\Models\BackupRestore;
use App\Models\WebsiteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

/** End-to-end package checks using generated local objects and customer fixtures only. */
class RecoveryPackageVerificationTest extends TestCase
{
    use CreatesBackups, RefreshDatabase;

    private SyntheticRecoveryPackageStorage $storage;

    private StorageManager $storages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
        config([
            'backup.downloads.enabled' => true,
            'backup.downloads.fingerprint_block_size' => 1024 * 1024,
            'backup.downloads.max_range_size' => 1024 * 1024,
            'backup.providers.sftp' => [
                'driver' => 'sftp', 'host' => 'fixture.invalid', 'username' => 'fixture',
                'password' => 'synthetic-only', 'host_fingerprint' => 'SHA256:synthetic', 'root' => '/synthetic',
            ],
        ]);

        $this->storage = new SyntheticRecoveryPackageStorage;
        $this->storages = new StorageManager;
        $this->storages->set('sftp', $this->storage);
        $this->app->instance(StorageManager::class, $this->storages);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    public function test_full_files_only_and_database_only_packages_match_their_manifest_and_downloaded_objects(): void
    {
        foreach ([BackupType::Full, BackupType::Files, BackupType::Database] as $type) {
            $fixture = $this->syntheticPackage($type);
            $downloaded = $this->downloadPackage($fixture);

            $this->assertTrue($this->packageMatchesManifest($fixture['backup'], $downloaded), $type->value);
            $expectedNames = [...$type->objects(), 'manifest'];
            $this->assertEqualsCanonicalizing($expectedNames, array_keys($downloaded), $type->value);

            $manifest = json_decode($downloaded['manifest'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($fixture['backup']->uuid, $manifest['backup_uuid']);
            $this->assertSame($fixture['website']->uuid, $manifest['website_uuid']);
            $this->assertSame($type->value, $manifest['type']);
            $this->assertSame($type === BackupType::Files || $type === BackupType::Full, is_array($manifest['files']));
            $this->assertSame($type === BackupType::Database || $type === BackupType::Full, is_array($manifest['database']));
            $this->assertNoRestoreSideEffects($fixture['backup']);
        }
    }

    public function test_missing_or_corrupt_full_package_component_never_forms_a_complete_download(): void
    {
        foreach (['missing', 'corrupt'] as $failure) {
            foreach (['files', 'database', 'manifest'] as $component) {
                $fixture = $this->syntheticPackage(BackupType::Full);
                $key = $fixture['backup']->objectKey(BackupObjectName::from($component));
                if ($failure === 'missing') {
                    unset($this->storage->objects[$key]);
                } else {
                    $original = $this->storage->objects[$key];
                    $this->storage->objects[$key] = ($original[0] === 'X' ? 'Y' : 'X').substr($original, 1);
                }

                $downloaded = $this->downloadPackage($fixture);

                $this->assertArrayNotHasKey($component, $downloaded, "{$failure} {$component}");
                $this->assertFalse($this->packageMatchesManifest($fixture['backup'], $downloaded), "{$failure} {$component}");
                $failedGrant = BackupDownloadGrant::query()
                    ->where('backup_id', $fixture['backup']->id)
                    ->where('object_name', $component)
                    ->firstOrFail();
                $this->assertSame(BackupDownloadGrant::FAILED, $failedGrant->status, "{$failure} {$component}");
            }
        }
    }

    public function test_a_downloaded_but_inconsistent_manifest_is_not_a_verified_recovery_package(): void
    {
        $fixture = $this->syntheticPackage(BackupType::Full);
        $manifest = $fixture['manifest'];
        $manifest['checksums']['files.tar.gz'] = str_repeat('0', 64);
        $manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $manifestObject = $fixture['backup']->objects()->where('name', 'manifest')->firstOrFail();
        $this->bindObject($fixture['backup'], $manifestObject, $manifestBytes);
        $fixture['backup']->forceFill(['manifest' => $manifest])->save();
        $fixture['manifest'] = $manifest;

        $downloaded = $this->downloadPackage($fixture);

        // Each object endpoint may succeed independently; only package-level
        // reconciliation proves that the downloaded components belong together.
        $this->assertCount(3, $downloaded);
        $this->assertFalse($this->packageMatchesManifest($fixture['backup'], $downloaded));
        $this->assertNoRestoreSideEffects($fixture['backup']);
    }

    public function test_synthetic_files_and_database_contents_match_declared_exclusions(): void
    {
        foreach ([BackupType::Files, BackupType::Database, BackupType::Full] as $type) {
            $fixture = $this->syntheticPackage($type);
            $downloaded = $this->downloadPackage($fixture);
            $manifest = json_decode($downloaded['manifest'], true, flags: JSON_THROW_ON_ERROR);

            if (isset($downloaded['files'])) {
                $tar = gzdecode($downloaded['files']);
                $this->assertIsString($tar);
                $this->assertStringContainsString('SYNTHETIC_FILE_CONTENT', $tar);
                $this->assertStringNotContainsString('SYNTHETIC_CONFIG_SECRET', $tar);
                $this->assertStringNotContainsString('wp-admin/', $tar);
                $this->assertSame('redacted', $manifest['files']['wp_config']);
            }

            if (isset($downloaded['database'])) {
                $sql = gzdecode($downloaded['database']);
                $this->assertIsString($sql);
                $this->assertStringContainsString('wp_posts', $sql);
                $this->assertStringNotContainsString('SYNTHETIC_CONFIG_SECRET', $sql);
                $this->assertStringNotContainsString('_transient_', $sql);
                $this->assertStringNotContainsString('connector_state', $sql);
                $this->assertEqualsCanonicalizing(['transients', 'connector_state'], $manifest['database']['excluded']);
            }

            $this->assertTrue($this->packageMatchesManifest($fixture['backup'], $downloaded), $type->value);
        }
    }

    public function test_object_mutation_between_range_requests_fails_before_mutated_bytes_are_returned(): void
    {
        $original = gzencode(str_repeat('A', 2 * 1024 * 1024 + 17));
        $this->assertIsString($original);
        $fixture = $this->syntheticPackage(BackupType::Files, ['files' => $original]);
        $object = $fixture['backup']->objects()->where('name', 'files')->firstOrFail();
        $grant = $this->prepareGrant($fixture, $object);
        $user = $fixture['website']->customer->user;

        $first = $this->actingAs($user)->withHeader('Range', 'bytes=0-9')
            ->get(route('account.backup-downloads.content', $grant));
        $first->assertStatus(206);
        $this->assertSame(substr($original, 0, 10), $first->streamedContent());

        $this->storage->objects[$grant->object_key] = str_repeat('B', strlen($original));
        $second = $this->actingAs($user)->withHeader('Range', 'bytes=10-19')
            ->get(route('account.backup-downloads.content', $grant));

        $second->assertStatus(503)->assertJson(['code' => 'OBJECT_INTEGRITY_MISMATCH']);
        $this->assertStringNotContainsString('BBBB', $second->getContent());
        $this->assertNoRestoreSideEffects($fixture['backup']);
    }

    private function syntheticPackage(BackupType $type, array $overrides = []): array
    {
        $site = $this->backupReadySite('https://synthetic-'.$type->value.'-'.Str::uuid().'.example');
        $backup = $this->verifiedBackup($site['website'], $type);
        $backup->forceFill(['storage_provider' => 'sftp'])->save();

        $fileContent = "wp-content/uploads/synthetic.txt\nSYNTHETIC_FILE_CONTENT\n";
        $databaseContent = "CREATE TABLE wp_posts (ID integer PRIMARY KEY);\nINSERT INTO wp_posts VALUES (1);\n";
        $payloads = [];
        foreach ($type->objects() as $name) {
            $payloads[$name] = $overrides[$name] ?? gzencode($name === 'files' ? $this->ustar('wp-content/uploads/synthetic.txt', $fileContent) : $databaseContent);
            $this->assertIsString($payloads[$name]);
        }

        $objects = [];
        foreach ($type->objects() as $name) {
            $object = $backup->objects()->where('name', $name)->firstOrFail();
            $this->bindObject($backup, $object, $payloads[$name]);
            $objects[$name] = $object->fresh();
            $this->storage->objects[$backup->objectKey(BackupObjectName::from($name))] = $payloads[$name];
        }

        $manifestInput = $this->manifestFor($backup->load('website'), $payloads);
        if (isset($manifestInput['files'])) {
            $manifestInput['files']['file_count'] = 1;
            $manifestInput['files']['uncompressed_size'] = strlen($fileContent);
        }
        if (isset($manifestInput['database'])) {
            $manifestInput['database']['tables'] = 1;
            $manifestInput['database']['rows'] = 1;
        }
        $manifest = app(BackupManifest::class)->build($backup, $manifestInput, $objects);
        $manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $manifestObject = $backup->objects()->where('name', 'manifest')->firstOrFail();
        $this->bindObject($backup, $manifestObject, $manifestBytes);
        $this->storage->objects[$backup->objectKey(BackupObjectName::Manifest)] = $manifestBytes;

        $backup->forceFill([
            'manifest' => $manifest,
            'checksums' => $manifest['checksums'],
            'files_size' => $manifest['files']['size'] ?? null,
            'database_size' => $manifest['database']['size'] ?? null,
            'total_size' => $manifest['total_size'],
        ])->save();

        return ['website' => $site['website']->refresh(), 'backup' => $backup->refresh(), 'manifest' => $manifest];
    }

    /** @return array<string, string> Object name => bytes downloaded from the real account endpoint. */
    private function downloadPackage(array $fixture): array
    {
        $downloaded = [];
        foreach ([...$fixture['backup']->type->objects(), 'manifest'] as $name) {
            $object = $fixture['backup']->objects()->where('name', $name)->firstOrFail();
            $grant = $this->prepareGrant($fixture, $object);
            if ($grant->status !== BackupDownloadGrant::READY) {
                continue;
            }

            $response = $this->actingAs($fixture['website']->customer->user)
                ->withHeader('Range', 'bytes=0-'.($grant->object_size - 1))
                ->get(route('account.backup-downloads.content', $grant));
            if ($response->status() === 206) {
                $downloaded[$name] = $response->streamedContent();
            }
        }

        return $downloaded;
    }

    private function prepareGrant(array $fixture, BackupObject $object): BackupDownloadGrant
    {
        $user = $fixture['website']->customer->user;
        $response = $this->actingAs($user)->postJson(route('account.websites.backups.download-grants.store', [
            $fixture['website'], $fixture['backup'], $object->name,
        ]));
        $response->assertAccepted();
        $grant = BackupDownloadGrant::query()->where('uuid', $response->json('grant'))->firstOrFail();
        (new PrepareBackupDownloadGrant($grant->id))->handle(app(BackupDownloadGrants::class), $this->storages);

        return $grant->fresh();
    }

    private function bindObject(Backup $backup, BackupObject $object, string $bytes): void
    {
        $object->forceFill([
            'status' => BackupObject::VERIFIED,
            'size' => strlen($bytes),
            'received_bytes' => strlen($bytes),
            'remote_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'verification_method' => 'readback',
            'verified_at' => now(),
        ])->save();
    }

    private function packageMatchesManifest(Backup $backup, array $downloaded): bool
    {
        if (! isset($downloaded['manifest'])) {
            return false;
        }
        try {
            $manifest = json_decode($downloaded['manifest'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (($manifest['backup_uuid'] ?? null) !== $backup->uuid
            || ($manifest['website_uuid'] ?? null) !== $backup->website->uuid
            || ($manifest['type'] ?? null) !== $backup->type->value) {
            return false;
        }

        $expectedNames = [...$backup->type->objects(), 'manifest'];
        if (array_diff(array_keys($downloaded), $expectedNames) !== [] || array_diff($expectedNames, array_keys($downloaded)) !== []) {
            return false;
        }
        $checksums = $manifest['checksums'] ?? null;
        if (! is_array($checksums)) {
            return false;
        }
        $expectedChecksums = ['algorithm', ...array_map(fn (string $name): string => BackupObjectName::from($name)->filename(), $backup->type->objects())];
        if (array_diff(array_keys($checksums), $expectedChecksums) !== []
            || array_diff($expectedChecksums, array_keys($checksums)) !== []) {
            return false;
        }

        foreach ($backup->type->objects() as $name) {
            $entry = $manifest[$name] ?? null;
            $filename = BackupObjectName::from($name)->filename();
            $bytes = $downloaded[$name] ?? null;
            if (! is_array($entry) || ! is_string($bytes)
                || ($entry['object'] ?? null) !== $filename
                || ($entry['size'] ?? null) !== strlen($bytes)
                || ! hash_equals((string) ($entry['sha256'] ?? ''), hash('sha256', $bytes))
                || ! hash_equals((string) ($checksums[$filename] ?? ''), hash('sha256', $bytes))) {
                return false;
            }
        }

        return ($checksums['algorithm'] ?? null) === 'sha256';
    }

    private function assertNoRestoreSideEffects(Backup $backup): void
    {
        $this->assertSame(0, BackupRestore::query()->where('website_id', $backup->website_id)->count());
        $this->assertSame(0, WebsiteJob::query()->where('website_id', $backup->website_id)->where('type', 'RESTORE')->count());
        $this->assertSame('verified', $backup->fresh()->status->value);
        Queue::assertPushed(PrepareBackupDownloadGrant::class);
    }

    private function ustar(string $path, string $contents): string
    {
        $header = str_pad($path, 100, "\0")
            .str_pad('0000644', 8, "\0")
            .str_pad('0000000', 8, "\0")
            .str_pad('0000000', 8, "\0")
            .str_pad(sprintf('%011o', strlen($contents)), 12, "\0")
            .str_pad(sprintf('%011o', time()), 12, "\0")
            .str_repeat(' ', 8)
            .'0'
            .str_repeat("\0", 100)
            .'ustar' . "\0" . '00'
            .str_repeat("\0", 32 * 2 + 8 * 2 + 155 + 12);
        $this->assertSame(512, strlen($header));
        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, sprintf('%06o' . "\0 ", $checksum), 148, 8);
        $padding = (512 - (strlen($contents) % 512)) % 512;

        return $header.$contents.str_repeat("\0", $padding).str_repeat("\0", 1024);
    }
}

final class SyntheticRecoveryPackageStorage implements BackupStorage, StreamableBackupStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    public function name(): string
    {
        return 'sftp';
    }

    public function put(string $key, $stream, int $size, string $sha256): void {}

    public function verify(string $key, int $size, string $sha256): Verification
    {
        return Verification::passed(Verification::READBACK, $size);
    }

    public function readRange(string $key, int $offset, int $length): string
    {
        return substr($this->objects[$key] ?? '', $offset, $length);
    }

    public function streamObject(string $key, callable $consume): int
    {
        $bytes = 0;
        foreach (str_split($this->objects[$key] ?? '', 16 * 1024) as $chunk) {
            $consume($chunk);
            $bytes += strlen($chunk);
        }

        return $bytes;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }
}
