<?php

namespace WPCareGrid\Connector\Backups;

/** Bounds total archive work while preserving the per-file policy. */
final class ArchiveBudget
{
    private int $entries = 0;

    private int $expandedBytes = 0;

    private int $pathComponents = 0;

    private int $paxBytes = 0;

    public function __construct(
        private readonly int $maxEntries = ArchiveLimits::MAX_ENTRIES,
        private readonly int $maxExpandedBytes = ArchiveLimits::MAX_EXPANDED_BYTES,
        private readonly int $maxPathDepth = ArchiveLimits::MAX_PATH_DEPTH,
        private readonly int $maxTotalPathComponents = ArchiveLimits::MAX_TOTAL_PATH_COMPONENTS,
        private readonly int $maxMemberBytes = ArchiveLimits::MAX_MEMBER_BYTES,
        private readonly int $maxPaxBytes = ArchiveLimits::MAX_PAX_BYTES,
    ) {}

    public function record(string $path, string $type, int $size): void
    {
        $parts = explode('/', PathGuard::relative($path));
        if (strlen($path) > 4096) {
            throw new BackupFailure('BACKUP_ARCHIVE_LIMIT_EXCEEDED', 'The site contains an archive path longer than the supported limit.');
        }

        $this->entries++;
        $this->pathComponents += count($parts);
        if ($this->entries > $this->maxEntries || count($parts) > $this->maxPathDepth
            || $this->pathComponents > $this->maxTotalPathComponents) {
            throw new BackupFailure('BACKUP_ARCHIVE_LIMIT_EXCEEDED', 'The site contains more archive entries or path depth than the supported limit.');
        }

        if ($type === 'file') {
            if ($size < 0 || $size > $this->maxMemberBytes || $size > $this->maxExpandedBytes - $this->expandedBytes) {
                throw new BackupFailure('BACKUP_ARCHIVE_LIMIT_EXCEEDED', 'The site backup exceeds the supported expanded-size limit.');
            }
            $this->expandedBytes += $size;
        }

        // TarWriter emits one PAX path record for non-ASCII or >100-byte names.
        if (strlen($path) > 100 || preg_match('/[^\x20-\x7e]/', $path)) {
            $recordBytes = self::paxRecordBytes($path);
            if ($recordBytes > $this->maxPaxBytes - $this->paxBytes) {
                throw new BackupFailure('BACKUP_ARCHIVE_LIMIT_EXCEEDED', 'The site backup contains too much extended path metadata.');
            }
            $this->paxBytes += $recordBytes;
        }
    }

    private static function paxRecordBytes(string $path): int
    {
        $bodyLength = strlen(' path='.$path."\n");
        $length = $bodyLength + 1;
        do {
            $next = $bodyLength + strlen((string) $length);
            $stable = $next === $length;
            $length = $next;
        } while (! $stable);

        return $length;
    }
}
