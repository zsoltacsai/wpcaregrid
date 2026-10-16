<?php

namespace WPCareGrid\Connector\Backups;

/** Shared writer/reader limits so the Connector never creates an archive it cannot safely restore. */
final class ArchiveLimits
{
    public const MAX_ENTRIES = 100000;

    public const MAX_ARCHIVE_BYTES = 64 * 1024 * 1024 * 1024;

    public const MAX_EXPANDED_BYTES = 60 * 1024 * 1024 * 1024;

    public const MAX_MEMBER_BYTES = 1024 * 1024 * 1024;

    public const MAX_PATH_DEPTH = 64;

    public const MAX_TOTAL_PATH_COMPONENTS = 300000;

    public const MAX_PAX_BYTES = 8 * 1024 * 1024;
}
